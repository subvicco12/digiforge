<?php

declare(strict_types=1);

namespace DigiForge\Readiness;

use DigiForge\Core\Config;
use DigiForge\Core\Settings;
use DigiForge\Database\MigrationPlan;
use DigiForge\Integrations\Repository as IntegrationRepository;

/**
 * Local-only release readiness evaluator.
 *
 * This class never arms automation, changes STOP ALL, dispatches jobs, or calls
 * an external provider. It only evaluates local state and returns deterministic
 * evidence suitable for human review.
 */
final class ReleaseReadiness
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return self::evaluate($this->liveContext());
    }

    /** @return array<string, mixed> */
    public function canaryDryRun(): array
    {
        return self::canary($this->liveContext());
    }

    /** @return array<string, mixed> */
    public function emergencyStopDrill(): array
    {
        return self::emergencyStop($this->liveContext());
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function evaluate(array $context): array
    {
        $switches = is_array($context['switches'] ?? null) ? $context['switches'] : [];
        $externalEnabled = [];
        foreach ($switches as $key => $enabled) {
            if ($key !== 'stop_all' && $enabled === true) {
                $externalEnabled[] = (string) $key;
            }
        }

        $checks = [
            'schema_current' => (int) ($context['schema_current'] ?? 0) === (int) ($context['schema_expected'] ?? -1),
            'stop_all_active' => ($context['stop_all'] ?? false) === true,
            'automation_unarmed' => ($context['automation_armed'] ?? true) === false,
            'external_switches_disabled' => $externalEnabled === [],
            'scheduler_inert' => ($context['scheduler_inert'] ?? false) === true,
            'production_integrations_disabled' => (int) ($context['enabled_production_integrations'] ?? 0) === 0,
            'retention_safeguard_available' => ($context['retention_safeguard_available'] ?? false) === true,
            'recovery_drill_available' => ($context['recovery_drill_available'] ?? false) === true,
            'activation_not_authorized' => ($context['activation_authorized'] ?? true) === false,
        ];

        $status = in_array(false, $checks, true) ? 'REVIEW_REQUIRED' : 'READY_FOR_INTERNAL_RELEASE';
        $evidence = [
            'status' => $status,
            'checks' => $checks,
            'schema' => [
                'current' => (int) ($context['schema_current'] ?? 0),
                'expected' => (int) ($context['schema_expected'] ?? 0),
            ],
            'enabled_external_switches' => $externalEnabled,
            'integration_summary' => [
                'total' => (int) ($context['integration_total'] ?? 0),
                'enabled_production' => (int) ($context['enabled_production_integrations'] ?? 0),
            ],
            'external_execution_allowed' => false,
            'activation_authorized' => false,
            'release_version' => (string) ($context['release_version'] ?? ''),
        ];
        $evidence['evidence_hash'] = self::hash($evidence);
        return $evidence;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function canary(array $context): array
    {
        $locked = ($context['stop_all'] ?? false) === true && ($context['automation_armed'] ?? true) === false;
        $state = $locked ? 'BLOCKED_BY_STOP_ALL' : 'NOT_ACTIVATED';
        $domains = ['etsy', 'printify', 'gelato', 'ai', 'fulfillment', 'finance_tax', 'publishing'];
        $results = [];
        foreach ($domains as $domain) {
            $results[$domain] = $state;
        }

        $evidence = [
            'mode' => 'DRY_RUN_ONLY',
            'provider_calls_made' => 0,
            'jobs_enqueued' => 0,
            'settings_changed' => 0,
            'results' => $results,
            'external_execution_allowed' => false,
            'activation_authorized' => false,
        ];
        $evidence['evidence_hash'] = self::hash($evidence);
        return $evidence;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function emergencyStop(array $context): array
    {
        $switches = is_array($context['switches'] ?? null) ? $context['switches'] : [];
        $effectiveDisabled = true;
        foreach ($switches as $key => $enabled) {
            if ($key !== 'stop_all' && $enabled === true) {
                $effectiveDisabled = false;
                break;
            }
        }

        $checks = [
            'stop_all_active' => ($context['stop_all'] ?? false) === true,
            'automation_unarmed' => ($context['automation_armed'] ?? true) === false,
            'external_switches_disabled' => $effectiveDisabled,
            'scheduler_inert' => ($context['scheduler_inert'] ?? false) === true,
            'activation_endpoint_absent' => true,
        ];
        $evidence = [
            'status' => in_array(false, $checks, true) ? 'REVIEW_REQUIRED' : 'PASS',
            'checks' => $checks,
            'settings_changed' => 0,
            'jobs_enqueued' => 0,
            'provider_calls_made' => 0,
            'activation_authorized' => false,
        ];
        $evidence['evidence_hash'] = self::hash($evidence);
        return $evidence;
    }

    /** @return array<string, mixed> */
    private function liveContext(): array
    {
        $switches = [];
        foreach (Config::SWITCHES as $switch) {
            $switches[$switch] = $switch === 'stop_all'
                ? (bool) Settings::get('stop_all', true)
                : Settings::is_enabled($switch);
        }

        $integrationTotal = 0;
        $enabledProduction = 0;
        $repository = new IntegrationRepository();
        $page = 1;
        do {
            $result = $repository->all($page, 100);
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];
            foreach ($items as $item) {
                ++$integrationTotal;
                if (($item['environment'] ?? '') === 'production' && ($item['enabled'] ?? false) === true) {
                    ++$enabledProduction;
                }
            }
            $pages = max(1, (int) ($result['pagination']['total_pages'] ?? 1));
            ++$page;
        } while ($page <= $pages);

        return [
            'schema_current' => (int) get_option('digiforge_db_schema_version', 0),
            'schema_expected' => MigrationPlan::LATEST,
            'stop_all' => (bool) Settings::get('stop_all', true),
            'automation_armed' => (bool) Settings::get('automation_armed', false),
            'switches' => $switches,
            'scheduler_inert' => true,
            'integration_total' => $integrationTotal,
            'enabled_production_integrations' => $enabledProduction,
            'retention_safeguard_available' => class_exists('DigiForge\\Operations\\RetentionPolicy'),
            'recovery_drill_available' => class_exists('DigiForge\\Operations\\RecoveryDrill'),
            'activation_authorized' => false,
            'release_version' => defined('DIGIFORGE_VERSION') ? DIGIFORGE_VERSION : '',
        ];
    }

    /** @param array<string, mixed> $value */
    private static function hash(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
