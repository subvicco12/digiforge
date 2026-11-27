<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

final class RecordBrowserPanel
{
    public static function render(array $scopes, array $query): void
    {
        echo '<section id="df-records"><h2>Read-only products and orders</h2>';
        echo '<p>Local synthetic records. Provider freshness is unavailable.</p>';
        $shop = $query['df_records_shop'] ?? null;
        $kind = $query['df_records_kind'] ?? '';
        echo '<form method="get"><input type="hidden" name="page" value="'.\esc_attr(Runtime::ADMIN_PAGE).'">';
        echo '<label>Shop <select name="df_records_shop">';
        foreach ($scopes as $key=>$account) {
            $key = (string)$key;
            echo '<option value="'.\esc_attr($key).'"'.($key === $shop ? ' selected' : '').'>Shop '.\esc_html($key).'</option>';
        }
        echo '</select></label> <label>Records <select name="df_records_kind">';
        foreach (['products'=>'Products', 'orders'=>'Orders'] as $value=>$label) {
            echo '<option value="'.\esc_attr($value).'"'.($kind === $value ? ' selected' : '').'>'.\esc_html($label).'</option>';
        }
        echo '</select></label> <button type="submit">Browse records</button></form>';
        if ($shop !== null) {
            self::renderResult($scopes, $query, $shop, $kind);
        }
        echo '</section>';
    }

    private static function renderResult(array $scopes, array $query, mixed $shop, mixed $kind): void
    {
        $id = $query['df_records_id'] ?? null;
        $rawPage = $query['df_records_page'] ?? '1';
        if (!is_string($shop) || !isset($scopes[$shop])) {
            echo '<p role="status">SCOPE_OR_TRANSPORT_UNAVAILABLE</p>';
            return;
        }
        if (!is_string($kind) || ($id !== null && !is_string($id)) || !is_string($rawPage)
            || preg_match('/^[1-9][0-9]{0,2}$/D', $rawPage) !== 1) {
            echo '<p role="status">INVALID_SELECTION</p>';
            return;
        }
        $result = Runtime::browseRecords(1, $shop, $scopes[$shop], $kind, $id, (int)$rawPage);
        if ($result['state'] !== 'OBSERVED') {
            echo '<p role="status">'.\esc_html($result['reason']).'</p>';
            return;
        }
        echo '<p>Observed locally '.\esc_html(gmdate('Y-m-d H:i:s', $result['observed_at'])).' UTC</p>';
        $params = ['page'=>Runtime::ADMIN_PAGE, 'df_records_shop'=>$shop, 'df_records_kind'=>$kind];
        $data = $result['data'];
        if ($id !== null) {
            self::link($params + ['df_records_page'=>$rawPage], 'Back to records');
            self::detail($kind, $data);
            return;
        }
        if ($data['data'] === []) {
            echo '<p>No records on this page.</p>';
        }
        echo '<ul>';
        foreach ($data['data'] as $row) {
            echo '<li>';
            self::link($params + ['df_records_id'=>$row['id']], $row['id']);
            if ($kind === 'orders') {
                echo ' · '.\esc_html($row['status']);
            }
            echo '</li>';
        }
        echo '</ul><p>Page '.(int)$data['current_page'].'</p>';
        if ($data['current_page'] > 1) {
            self::link($params + ['df_records_page'=>$data['current_page'] - 1], 'Previous page');
        }
        if ($data['next_page'] !== null) {
            self::link($params + ['df_records_page'=>$data['next_page']['page']], 'Next page');
        }
    }

    private static function detail(string $kind, array $record): void
    {
        echo '<h3>'.\esc_html($record['id']).'</h3>';
        if ($kind === 'products') {
            echo '<p>Blueprint '.(int)$record['blueprint_id'].' · Print provider '.(int)$record['print_provider_id'].'</p>';
            echo '<ul>';
            foreach ($record['variants'] as $variant) {
                echo '<li>Variant '.(int)$variant['id'].' · '.($variant['is_enabled'] ? 'Enabled' : 'Disabled').'</li>';
            }
            echo '</ul>';
            return;
        }
        echo '<p>Order status '.\esc_html($record['status']).' · Line items '.count($record['line_items']).'</p>';
        echo '<h4>Shipments and tracking</h4>';
        if ($record['shipments'] === []) {
            echo '<p>No shipment observed.</p>';
        }
        echo '<ul>';
        foreach ($record['shipments'] as $shipment) {
            echo '<li>'.\esc_html($shipment['carrier'].' · '.($shipment['tracking_reference'] ?? 'Tracking reference unavailable')
                .' · '.($shipment['delivered_at'] ?? 'Delivery time unavailable')).'</li>';
        }
        echo '</ul>';
    }

    private static function link(array $params, string $label): void
    {
        echo '<a href="'.\esc_url(\add_query_arg($params, \admin_url('tools.php'))).'">'.\esc_html($label).'</a> ';
    }
}
