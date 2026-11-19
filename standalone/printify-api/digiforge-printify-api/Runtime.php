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
        echo '</dl>';
        if (in_array('OFFLINE_SYNTHETIC_JOURNEY', $status['capabilities'], true)) {
            require_once __DIR__ . '/SyntheticOperatorPanel.php';
            SyntheticOperatorPanel::render();
        }
        echo '</div>';
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
            if (self::fixtureAccount() !== null && self::providerReadAvailable()) {
                $status['capabilities'][] = 'OFFLINE_PROVIDER_READ_REVIEW';
            }
            if (self::canRunSyntheticJourney()) {
                $status['capabilities'][] = 'OFFLINE_SYNTHETIC_JOURNEY';
            }
            if (self::canReviewLifecycle()) {
                $status['capabilities'][] = 'OFFLINE_LIFECYCLE_REVIEW';
            }
        }
        return $status;
    }

    private static function canRunSyntheticJourney(): bool
    {
        $shop = self::shop();
        if ($shop === null || strlen($shop) > 18 || !self::canValidate($shop) || self::fixtureAccount() === null
            || !\defined('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY') || DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY !== true
            || !\function_exists('wp_get_environment_type') || \wp_get_environment_type() !== 'local'
            || !self::providerReadAvailable() || !self::canReviewLifecycle()) {
            return false;
        }
        foreach (['SyntheticJourney.php','SyntheticPrintifyClient.php','SyntheticPrintifyTransport.php',
            'SyntheticOperatorPanel.php','Engine/POD/PrintifyLifecycleRequest.php','Engine/POD/PrintifyLifecycleEvidence.php',
            'Engine/POD/PrintifyUploadContract.php'] as $path) {
            if (!is_readable(__DIR__ . '/' . $path)) {
                return false;
            }
        }
        return true;
    }

    public static function runSyntheticJourney(): array
    {
        if (!self::canRunSyntheticJourney()) {
            return ['state'=>'DENIED','steps'=>[],'provider_execution_authorized'=>false,'retry_permitted'=>false];
        }
        require_once __DIR__ . '/SyntheticPrintifyTransport.php';
        require_once __DIR__ . '/SyntheticPrintifyClient.php';
        require_once __DIR__ . '/SyntheticJourney.php';
        return SyntheticJourney::run(self::shop());
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

    public static function inspectNativeHeldOperation(string $shop, string $account, mixed $scope, array $binding, array $context): array
    {
        require_once __DIR__ . '/NativeHeldInspection.php';
        return NativeHeldInspection::read($shop, $account, $scope, $binding, $context);
    }

    private const READ_ENGINE = [
        'Listings/Validator.php', 'POD/PrintifyApiRequest.php',
        'POD/PrintifyOrderEvidence.php', 'POD/PrintifyApiReadEvidence.php',
    ];

    private static function providerReadAvailable(): bool
    {
        if (!\function_exists('wp_json_encode')) {
            return false;
        }
        foreach (self::READ_ENGINE as $path) {
            if (!is_readable(__DIR__ . '/Engine/' . $path)) {
                return false;
            }
        }
        return true;
    }

    public static function prepareProviderRead(string $shop, string $account, string $operation, array $input): array
    {
        $denied = self::providerReadDenied();
        if (!self::permitsFixtureAccount($shop, $account) || !self::providerReadAvailable()) {
            return $denied;
        }
        try {
            foreach (self::READ_ENGINE as $path) {
                require_once __DIR__ . '/Engine/' . $path;
            }
            if (!Engine\POD\PrintifyApiReadEvidence::supported($operation)) {
                return $denied;
            }
            $request = Engine\POD\PrintifyApiRequest::prepare($operation, $shop, $input);
            if ($request['method'] !== 'GET' || $request['external_execution_authorized'] !== false) {
                return $denied;
            }
            return [
                'state' => 'OFFLINE_PROVIDER_READ_PREPARED',
                'account_reference' => $account,
                'request' => $request,
                'native_reconciliation' => 'BLOCKED',
            ] + $denied;
        } catch (\Throwable) {
            return $denied;
        }
    }

    public static function reviewProviderRead(string $shop, string $account, string $operation, array $input, string $body): array
    {
        $denied = self::providerReadDenied();
        if (strlen($body) > 262144) {
            return $denied;
        }
        $plan = self::prepareProviderRead($shop, $account, $operation, $input);
        if ($plan['state'] !== 'OFFLINE_PROVIDER_READ_PREPARED') {
            return $denied;
        }
        try {
            $data = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                return $denied;
            }
            $rows = match ($operation) {
                'ORDERS_READ', 'PRODUCTS_READ' => $data['data'] ?? null,
                'ORDER_READ', 'PRODUCT_READ' => [$data],
                default => [],
            };
            if (!is_array($rows)) {
                return $denied;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    return $denied;
                }
                if (array_key_exists('shop_id', $row)
                    && $row['shop_id'] !== $shop
                    && (!is_int($row['shop_id']) || (string)$row['shop_id'] !== $shop)) {
                    return $denied;
                }
            }
            return [
                'state' => 'OFFLINE_PROVIDER_READ_REVIEWED',
                'evidence_class' => 'UNATTRIBUTED_OFFLINE_INPUT',
                'snapshot_sha256' => hash('sha256', $body),
                'observation' => Engine\POD\PrintifyApiReadEvidence::project($operation, $data, $shop, $input),
            ] + $plan;
        } catch (\Throwable) {
            return $denied;
        }
    }

    private static function providerReadDenied(): array
    {
        return ['state' => 'DENIED', 'provider_execution_authorized' => false,
            'provider_outcome_confirmed' => false, 'retry_permitted' => false];
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
