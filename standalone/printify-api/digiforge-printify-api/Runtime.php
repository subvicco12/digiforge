<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Standalone local operations. This class owns no provider transport. */
final class Runtime
{
    public static function register(): void
    {
        \add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void
    {
        \add_management_page('DigiForge Printify API', 'Printify API', 'manage_options', 'digiforge-printify-api', [self::class, 'panel']);
    }

    public static function panel(): void
    {
        $status = self::discover();
        if ($status['capabilities'] === []) {
            return;
        }
        echo '<div class="wrap"><h1>DigiForge Printify API</h1><dl>';
        foreach ([
            'Version' => $status['version'],
            'Mode' => $status['mode'],
            'Local capabilities' => implode(', ', $status['capabilities']),
            'Provider dispatch' => 'Denied',
            'Release' => 'Held',
        ] as $label => $value) {
            echo '<dt>' . \esc_html($label) . '</dt><dd>' . \esc_html($value) . '</dd>';
        }
        echo '</dl></div>';
    }

    /** The config supplies only a local shop scope, never provider authority. */
    private static function shop(): ?string
    {
        $value = \defined('DIGIFORGE_PRINTIFY_OFFLINE_SHOP') ? \constant('DIGIFORGE_PRINTIFY_OFFLINE_SHOP') : null;
        return is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1 ? $value : null;
    }

    private static function canInspect(): bool
    {
        return \function_exists('current_user_can') && \current_user_can('manage_options');
    }

    private static function canValidate(?string $shop): bool
    {
        return $shop !== null && self::canInspect()
            && \current_user_can('digiforge_printify_review_shop_' . $shop)
            && is_readable(__DIR__ . '/Engine/PrintifyCatalogReadContract.php');
    }

    public static function discover(): array
    {
        $status = Status::snapshot();
        if (!self::canInspect()) {
            return $status;
        }
        $status['capabilities'] = ['PANEL/DIAGNOSTICS'];
        $status['mode'] = 'DEGRADED_READ_ONLY';
        if (self::canValidate(self::shop())) {
            $status['capabilities'][] = 'OFFLINE_CATALOG_VALIDATE';
            if (self::canReviewLifecycle()) {
                $status['capabilities'][] = 'OFFLINE_LIFECYCLE_REVIEW';
            }
        }
        return $status;
    }

    private static function fixtureAccount(): ?string
    {
        $value = \defined('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT') ? \constant('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT') : null;
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value) === 1 ? $value : null;
    }

    public static function permitsFixtureAccount(string $shop, string $account): bool
    {
        return self::permitsFixtureShop($shop) && self::fixtureAccount() === $account;
    }

    public static function permitsFixtureShop(string $shop): bool
    {
        return self::shop() === $shop && self::canValidate($shop);
    }

    private static function canReviewLifecycle(): bool
    {
        $path = __DIR__ . '/OfflineLifecycle.php';
        if (self::fixtureAccount() === null || !is_readable($path)) {
            return false;
        }
        require_once $path;
        return OfflineLifecycle::available();
    }

    public static function reviewLifecycle(string $shop, array $intent, array $fixture): array
    {
        if (!is_string($intent['account_reference'] ?? null) || !self::permitsFixtureAccount($shop, $intent['account_reference'])
            || !self::canReviewLifecycle()) {
            return ['state' => 'DENIED', 'provider_execution_authorized' => false];
        }
        return OfflineLifecycle::review($shop, $intent, $fixture);
    }

    /**
     * Validate one supplied snapshot. Keep raw response values out of diagnostics.
     * No receipt, freshness claim, network request, or durable write follows.
     *
     * @param array<string,mixed> $request
     */
    public static function validateCatalog(string $shop, array $request, string $body): array
    {
        $configured = self::shop();
        if (!self::canValidate($configured) || $configured !== $shop || strlen($body) > 262144) {
            return ['state' => 'DENIED', 'provider_execution_authorized' => false];
        }
        try {
            require_once __DIR__ . '/Engine/PrintifyCatalogReadContract.php';
            $data = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Snapshot must contain an array or object.');
            }
            $endpoint = Engine\PrintifyCatalogReadContract::endpoint($request);
            Engine\PrintifyCatalogReadContract::select($data, $request);
            ksort($request, SORT_STRING);
            return [
                'state' => 'LOCAL_VALIDATION_PASSED',
                'evidence_class' => 'UNATTRIBUTED_OFFLINE_INPUT',
                'endpoint' => $endpoint,
                'request_sha256' => hash('sha256', json_encode(['GET', $endpoint, $request], JSON_THROW_ON_ERROR)),
                'snapshot_sha256' => hash('sha256', $body),
                'freshness_verified' => false,
                'provider_execution_authorized' => false,
            ];
        } catch (\Throwable) {
            return ['state' => 'DENIED', 'provider_execution_authorized' => false];
        }
    }
}
