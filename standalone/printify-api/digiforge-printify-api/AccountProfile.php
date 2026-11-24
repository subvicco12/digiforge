<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Site-local metadata and offline GET planning. No credential or authorization is stored. */
final class AccountProfile
{
    private const OPTION = 'digiforge_printify_account_profile_v1';
    private const FORM = 'digiforge_printify_account_profile_save';
    private const FIELDS = [
        'shop'=>'Printify shop ID',
        'account'=>'Account reference',
        'integration'=>'Integration reference',
        'custody'=>'Custody reference (optional)',
    ];

    public static function save(array $input, string $nonce): string
    {
        if (!\current_user_can('manage_options') || !\wp_verify_nonce($nonce, self::FORM)) {
            return 'DENIED';
        }
        $profile = self::validate($input);
        if ($profile === null || !\current_user_can('digiforge_printify_review_shop_'.$profile['shop'])) {
            return 'INVALID_OR_UNASSIGNED_SCOPE';
        }
        $stored = \get_option(self::OPTION, null);
        $previous = self::storedProfile($stored);
        if ($previous !== null && !\current_user_can('digiforge_printify_review_shop_'.$previous['shop'])) {
            return 'DENIED';
        }
        if ($previous === $profile) {
            return 'SAVED_LOCAL_METADATA';
        }
        $history = self::history($stored);
        $history[] = [
            'changed_at'=>gmdate('c'),
            'before'=>self::fingerprint($previous),
            'after'=>self::fingerprint($profile),
            'scope_changed'=>$previous === null || array_diff_assoc(array_diff_key($profile, ['custody'=>true]), $previous) !== [],
            'alias_changed'=>($previous['custody'] ?? '') !== $profile['custody'],
        ];
        if (\update_option(self::OPTION, ['profile'=>$profile,'history'=>array_slice($history,-20)], false)) {
            return 'SAVED_LOCAL_METADATA';
        }
        return 'SAVE_FAILED';
    }

    public static function current(): ?array
    {
        if (!\current_user_can('manage_options')) {
            return null;
        }
        $stored = \get_option(self::OPTION, null);
        $profile = self::storedProfile($stored);
        return $profile !== null && \current_user_can('digiforge_printify_review_shop_'.$profile['shop'])
            ? $profile : null;
    }

    public static function diagnostics(): array
    {
        $profile = self::current();
        if ($profile === null) {
            return [];
        }
        $fingerprint = self::fingerprint($profile);
        return [
            'custody'=>$profile['custody'] === '' ? 'NO_REFERENCE' : 'FIXTURE_REFERENCE_CONFIGURED',
            'operational_resolution'=>'BLOCKED_EXTERNAL',
            'change_receipts'=>array_values(array_filter(self::history(\get_option(self::OPTION, null)),
                static fn(array $receipt): bool => $receipt['after'] === $fingerprint)),
        ];
    }

    private static function storedProfile(mixed $stored): ?array
    {
        $data = is_array($stored) ? ($stored['profile'] ?? $stored) : null;
        return is_array($data) ? self::validate($data) : null;
    }

    private static function fingerprint(?array $profile): ?string
    {
        return $profile === null ? null : hash_hmac('sha256', json_encode($profile, JSON_THROW_ON_ERROR), \wp_salt('auth'));
    }

    private static function history(mixed $stored): array
    {
        $rows = is_array($stored) && is_array($stored['history'] ?? null) ? $stored['history'] : [];
        return array_values(array_filter(array_slice($rows,-20), static fn(mixed $row): bool =>
            is_array($row) && array_keys($row) === ['changed_at','before','after','scope_changed','alias_changed']
            && is_string($row['changed_at']) && preg_match('/^[0-9T:+-]{25}$/D', $row['changed_at']) === 1
            && ($row['before'] === null || is_string($row['before']) && preg_match('/^[a-f0-9]{64}$/D', $row['before']) === 1)
            && is_string($row['after']) && preg_match('/^[a-f0-9]{64}$/D', $row['after']) === 1
            && is_bool($row['scope_changed']) && is_bool($row['alias_changed'])));
    }

    /** Create a GET plan only. The purpose selects a reviewed contract, never an arbitrary URL. */
    public static function plan(string $shop, string $account, string $integration, string $purpose, array $input = []): array
    {
        $denied = ['schema'=>'DF_PRINTIFY_PROFILE_READ_PLAN_1', 'state'=>'DENIED', 'reason'=>'SCOPE_OR_CONTRACT',
            'transport'=>'DISABLED', 'provider_execution_authorized'=>false, 'retry_permitted'=>false];
        $profile = self::current();
        if ($profile === null || $profile['shop'] !== $shop || $profile['account'] !== $account
            || $profile['integration'] !== $integration || !Runtime::permitsFixtureAccount($shop, $account)) {
            return $denied;
        }
        if ($purpose === 'catalog' && $input === []) {
            $contract = __DIR__.'/Engine/PrintifyCatalogReadContract.php';
            if (!is_readable($contract)) {
                return $denied;
            }
            require_once $contract;
            $endpoint = Engine\PrintifyCatalogReadContract::endpoint(['kind'=>'blueprints']);
        } elseif (in_array($purpose, ['products','orders'], true)) {
            $plan = Runtime::prepareProviderRead($shop, $account, $purpose === 'products' ? 'PRODUCTS_READ' : 'ORDERS_READ', $input);
            if ($plan['state'] !== 'OFFLINE_PROVIDER_READ_PREPARED' || ($plan['request']['method'] ?? null) !== 'GET') {
                return $denied;
            }
            $endpoint = $plan['request']['endpoint'] ?? null;
            if (!is_string($endpoint)) {
                return $denied;
            }
        } else {
            return $denied;
        }
        return ['state'=>'PREPARED_OFFLINE', 'reason'=>'EXECUTION_HOLD', 'method'=>'GET', 'endpoint'=>$endpoint,
            'scope'=>['shop'=>$shop, 'account'=>$account, 'integration'=>$integration], 'evidence_class'=>'LOCAL_CONFIGURATION']
            + $denied;
    }

    public static function render(array $post): void
    {
        if (!\current_user_can('manage_options')) {
            return;
        }
        echo '<section id="df-account"><h2>Account configuration</h2>';
        echo '<p>Save shop and account references for offline planning. Provider connectivity is disabled. Do not enter credentials.</p>';
        if (isset($post[self::FORM])) {
            $input = $post['df_printify_profile'] ?? null;
            $nonce = $post['df_printify_profile_nonce'] ?? null;
            $status = is_array($input) && is_string($nonce) ? self::save($input, $nonce) : 'DENIED';
            echo '<p role="status">'.\esc_html(match ($status) {
                'SAVED_LOCAL_METADATA'=>'Local metadata saved.',
                'INVALID_OR_UNASSIGNED_SCOPE'=>'Check the references and your assigned shop.',
                'SAVE_FAILED'=>'Local metadata could not be saved.',
                default=>'Account configuration change denied.',
            }).'</p>';
        }
        $profile = self::current();
        echo '<form method="post"><input type="hidden" name="'.self::FORM.'" value="1">';
        \wp_nonce_field(self::FORM, 'df_printify_profile_nonce');
        foreach (self::FIELDS as $key=>$label) {
            echo '<p><label>'.\esc_html($label).' <input name="df_printify_profile['.$key.']" maxlength="100"'
                .($key === 'custody' ? ' placeholder="custody-ref:example"' : ' required')
                .' value="'.\esc_attr($profile[$key] ?? '').'"></label></p>';
        }
        echo '<button type="submit">Save local configuration</button></form>';
        if ($profile !== null) {
            $diagnostics = self::diagnostics();
            echo '<p>'.\esc_html('Custody: '.$diagnostics['custody'].' · '.$diagnostics['operational_resolution']).'</p>';
            echo '<ul>';
            foreach (['catalog'=>'Catalog', 'products'=>'Products', 'orders'=>'Orders'] as $purpose=>$label) {
                $plan = self::plan($profile['shop'], $profile['account'], $profile['integration'], $purpose);
                echo '<li>'.\esc_html($label.': '.($plan['state'] === 'PREPARED_OFFLINE'
                    ? 'GET '.$plan['endpoint'].' (offline plan)' : 'Unavailable for this fixture scope')).'</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }

    private static function validate(array $input): ?array
    {
        if (count($input) !== count(self::FIELDS) || array_diff_key(self::FIELDS, $input) !== []) {
            return null;
        }
        foreach ($input as $value) {
            if (!is_string($value)) {
                return null;
            }
        }
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $input['shop']) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $input['account']) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $input['integration']) !== 1
            || ($input['custody'] !== '' && preg_match('/^custody-ref:[A-Za-z0-9_-]{1,88}$/D', $input['custody']) !== 1)) {
            return null;
        }
        return array_replace(self::FIELDS, $input);
    }
}
