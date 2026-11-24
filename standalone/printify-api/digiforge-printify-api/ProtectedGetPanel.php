<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__.'/ProtectedGet.php';

/** Manual scoped request preparation. There is no WordPress-to-gateway exchange. */
final class ProtectedGetPanel
{
    private const ACTION = 'df_printify_protected_get_v1';
    private const FIELDS = [
        'SHOPS_READ'=>[], 'BLUEPRINTS_READ'=>[], 'BLUEPRINT_READ'=>['blueprint_id'],
        'BLUEPRINT_PROVIDERS_READ'=>['blueprint_id'], 'PROVIDERS_READ'=>[], 'PROVIDER_READ'=>['provider_id'],
        'VARIANTS_READ'=>['blueprint_id','provider_id'], 'VARIANT_READ'=>['blueprint_id','provider_id','variant_id'],
        'CATALOG_SHIPPING_READ'=>['blueprint_id','provider_id'],
        'CATALOG_SHIPPING_METHOD_READ'=>['blueprint_id','provider_id','method'],
        'PRODUCTS_READ'=>['page'],'PRODUCT_READ'=>['product_id','blueprint_id','provider_id','variant_ids'],
        'ORDERS_READ'=>['page'],'ORDER_READ'=>['order_id'],
    ];

    public static function render(array $post): void
    {
        if (!\current_user_can('manage_options')) {
            return;
        }
        echo '<section><h2>Protected Printify reads</h2><p>Gateway unavailable. Requests can be prepared locally. No data is synchronized.</p>';
        $profile = AccountProfile::current();
        $revision = AccountProfile::revisionToken();
        if ($profile === null || $profile['custody'] === '' || $revision === null) {
            echo '<p>An assigned account profile and custody reference are required.</p></section>';
            return;
        }
        echo '<p>Shop '.\esc_html($profile['shop']).' · account '.\esc_html($profile['account']).'</p>';
        if (isset($post[self::ACTION])) {
            $result = self::prepare($post);
            echo '<p role="status">'.\esc_html($result['reason']).'</p>';
            if (isset($result['path'])) {
                echo '<code>GET '.\esc_html($result['path']).'</code>';
            }
        }
        echo '<form method="post"><input type="hidden" name="'.self::ACTION.'" value="1">';
        \wp_nonce_field(self::ACTION, 'df_printify_get_nonce');
        echo '<input type="hidden" name="df_printify_get_revision" value="'.\esc_attr($revision).'">';
        echo '<label>Read operation <select name="df_printify_get_operation">';
        foreach (self::FIELDS as $operation => $fields) {
            echo '<option value="'.\esc_attr($operation).'">'.\esc_html($operation).'</option>';
        }
        echo '</select></label><p>Fill only the fields needed by the operation. Product detail needs the catalog selection and comma-separated variant IDs.</p>';
        foreach (['blueprint_id','provider_id','variant_id','method','page','product_id','variant_ids','order_id'] as $field) {
            echo '<p><label>'.\esc_html($field).' <input name="df_printify_get['.\esc_attr($field).']" maxlength="200"></label></p>';
        }
        echo '<button type="submit">Prepare GET</button></form></section>';
    }

    public static function prepare(array $post): array
    {
        $denied = ProtectedGet::denied();
        $nonce = $post['df_printify_get_nonce'] ?? null;
        if (!\current_user_can('manage_options') || !is_string($nonce) || !\wp_verify_nonce($nonce, self::ACTION)) {
            return ['reason'=>'OPERATOR_DENIED'] + $denied;
        }
        $profile = AccountProfile::current();
        $revision = AccountProfile::revisionToken();
        $session = \wp_get_session_token();
        if ($profile === null || $revision === null || $session === ''
            || $revision !== ($post['df_printify_get_revision'] ?? null)) {
            return ['reason'=>'SCOPE_OR_REVISION_UNAVAILABLE'] + $denied;
        }
        $operation = $post['df_printify_get_operation'] ?? null;
        $fields = $post['df_printify_get'] ?? null;
        if (!is_string($operation) || !isset(self::FIELDS[$operation]) || !is_array($fields) || count($fields) > 8) {
            return ['reason'=>'INVALID_SELECTION'] + $denied;
        }
        $selection = [];
        try {
            foreach ($fields as $name => $value) {
                if ($value === '') {
                    continue;
                }
                if (!in_array($name, self::FIELDS[$operation], true) || !is_string($value) || strlen($value) > 200) {
                    throw new \InvalidArgumentException('Unexpected selection.');
                }
                if ($name === 'variant_ids') {
                    $selection[$name] = array_map(self::positiveInteger(...), explode(',', $value));
                } elseif (in_array($name, ['blueprint_id','provider_id','variant_id','page'], true)) {
                    $selection[$name] = self::positiveInteger($value);
                } else {
                    $selection[$name] = $value;
                }
            }
            $scope = $profile + ['site'=>'wp-'.hash('sha256', \home_url('/')), 'actor'=>\get_current_user_id(),
                'session'=>hash_hmac('sha256', $session, \wp_salt('auth')), 'revision'=>$revision];
            $request = ProtectedGet::request($scope, $operation, $selection);
            if ($profile !== AccountProfile::current() || $revision !== AccountProfile::revisionToken()) {
                return ['reason'=>'SCOPE_OR_REVISION_UNAVAILABLE'] + $denied;
            }
            return ['state'=>'PREPARED_OFFLINE','path'=>$request['path']] + $denied;
        } catch (\Throwable) {
            return ['reason'=>'INVALID_SELECTION'] + $denied;
        }
    }

    private static function positiveInteger(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1 || (int)$value > 2147483647) {
            throw new \InvalidArgumentException('Invalid positive integer.');
        }
        return (int)$value;
    }
}
