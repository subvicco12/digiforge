<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

final class CatalogBrowserPanel
{
    public static function render(array $scopes, array $query): void
    {
        echo '<section id="df-catalog"><h2>Read-only catalog browser</h2>';
        echo '<p>Synthetic observations only. Printify network access is disabled.</p>';
        $shops = [];
        foreach ($scopes as $id=>$account) {
            $shops[] = ['id'=>(string)$id, 'title'=>'Shop '.$id];
        }
        self::selector('Shop', 'df_catalog_shop', $shops, [], is_string($query['df_catalog_shop'] ?? null) ? $query['df_catalog_shop'] : '');
        $shop = $query['df_catalog_shop'] ?? null;
        if (!is_string($shop) || !isset($scopes[$shop])) {
            echo '</section>';
            return;
        }
        $selection = [];
        $fields = ['blueprint_id'=>'df_catalog_blueprint', 'provider_id'=>'df_catalog_provider',
            'variant_id'=>'df_catalog_variant', 'method'=>'df_catalog_method'];
        foreach ($fields as $key=>$field) {
            if (!isset($query[$field])) {
                continue;
            }
            $value = $query[$field];
            $selection[$key] = $key === 'method' ? $value
                : (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 ? (int)$value : false);
        }
        $result = CatalogBrowser::read(1, $shop, $scopes[$shop], $selection);
        if ($result['state'] !== 'OBSERVED') {
            echo '<p role="status">Catalog unavailable. '.\esc_html($result['error']).'</p></section>';
            return;
        }
        $data = $result['data'];
        echo '<p>'.\esc_html($data['shop']['title']).' · '.\esc_html(gmdate('Y-m-d H:i:s', $result['observed_at'])).' UTC</p>';
        $prior = ['df_catalog_shop'=>$shop];
        foreach (['blueprint_id'=>'blueprints', 'provider_id'=>'providers', 'variant_id'=>'variants'] as $key=>$group) {
            if ($data[$group] === null) {
                break;
            }
            self::selector(ucfirst(str_replace('_id','',$key)), $fields[$key], $data[$group]['rows'], $prior,
                (string)($selection[$key] ?? ''));
            if (isset($selection[$key])) {
                $prior[$fields[$key]] = (string)$selection[$key];
            }
        }
        if (isset($selection['variant_id'])) {
            echo '<h3>Print areas</h3>';
            if (!$data['print_areas']) {
                echo '<p>Print-area metadata unavailable.</p>';
            } else {
                echo '<ul>';
                foreach ($data['print_areas'] as $area) {
                    echo '<li>'.\esc_html($area['position'].': '.($area['width'] ?? 'unknown').' × '.($area['height'] ?? 'unknown')).'</li>';
                }
                echo '</ul><p>Bleed and safe zones unavailable.</p>';
            }
            $methods = array_map(static fn(string $method): array => ['id'=>$method, 'title'=>ucfirst($method)],
                $data['shipping_methods']);
            self::selector('Catalog shipping method', $fields['method'], $methods, $prior, (string)($selection['method'] ?? ''));
        }
        if ($data['catalog_rates'] !== null) {
            echo '<h3>Catalog shipping rates</h3><p>Fixture amounts in provider units. This catalog GET does not quote an address.</p>';
            if ($data['catalog_rates'] === []) {
                echo '<p>No catalog rate observed for this variant.</p>';
            }
            echo '<ul>';
            foreach ($data['catalog_rates'] as $rate) {
                echo '<li>'.\esc_html($rate['country'].' · '.$rate['currency'].' '.$rate['first_item']
                    .' first item / '.$rate['additional_item'].' additional').'</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }

    private static function selector(string $label, string $field, array $rows, array $prior, string $selected): void
    {
        echo '<form method="get"><input type="hidden" name="page" value="digiforge-printify-api">';
        foreach ($prior as $key=>$value) {
            echo '<input type="hidden" name="'.\esc_attr($key).'" value="'.\esc_attr($value).'">';
        }
        echo '<label>'.\esc_html($label).' <select name="'.\esc_attr($field).'">';
        foreach ($rows as $row) {
            $id = (string)$row['id'];
            echo '<option value="'.\esc_attr($id).'"'.($selected === $id ? ' selected' : '').'>'
                .\esc_html($row['title'] ?? $id).'</option>';
        }
        echo '</select></label> <button type="submit">Browse '.\esc_html(strtolower($label)).'</button></form>';
    }
}
