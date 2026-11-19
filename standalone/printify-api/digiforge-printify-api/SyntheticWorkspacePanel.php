<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

final class SyntheticWorkspacePanel
{
    private const ACTION = 'df_printify_synthetic_workspace_v1';

    public static function render(SyntheticWorkspace $workspace, string $shop, array $shops): void
    {
        try {
            $current = $workspace->inspect();
            $result = self::post($workspace,$shop);
            if ($result !== null) {
                $current = $workspace->inspect();
            }
        } catch (\Throwable) {
            echo '<p role="alert">Synthetic workspace state could not be verified.</p>';
            return;
        }

        echo '<h2>Synthetic operator workspace</h2><p>Configured fixture shop '
            .\esc_html($shop).'. Provider execution is disabled. Enter fixture values only.</p>';
        if ($result !== null) {
            echo '<h3>Operation result</h3><pre>'.\esc_html(
                json_encode($result, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''
            ).'</pre>';
        }

        if (count($shops)>1) {
            echo '<form method="get"><input type="hidden" name="page" value="digiforge-printify-api">';
            echo '<label for="df-fixture-shop">Fixture shop</label> <select id="df-fixture-shop" name="df_workspace_shop">';
            foreach ($shops as $id) {
                $id=(string)$id;
                echo '<option value="'.\esc_attr($id).'"'.($shop===$id?' selected':'').'>'.\esc_html($id).'</option>';
            }
            echo '</select> <button type="submit">Open workspace</button></form>';
        }
        echo '<form method="post">';
        \wp_nonce_field(self::ACTION.':'.$shop,'df_workspace_nonce',false);
        echo '<input type="hidden" name="df_workspace_shop" value="'.\esc_attr($shop).'">';
        echo '<input type="hidden" name="df_workspace_revision" value="'.\esc_attr((string)$current['revision']).'">';
        echo '<input type="hidden" name="df_workspace_id" value="'.\esc_attr(bin2hex(random_bytes(32))).'">';
        echo '<p><label for="df-workspace-operation">Operation</label> ';
        echo '<select id="df-workspace-operation" name="df_workspace_operation">';
        $operations=array_values(array_diff(SyntheticPrintifyClient::operations(),['ORDER_SEND_TO_PRODUCTION']));
        foreach ([...$operations,'FIXTURE_PRODUCTION_CONFIRM','FIXTURE_FULFILL','FIXTURE_WEBHOOK_EVENT'] as $op) {
            echo '<option value="'.\esc_attr($op).'">'.\esc_html($op).'</option>';
        }
        echo '</select></p><p><label for="df-workspace-input">Operation input (JSON)</label><br>';
        echo '<textarea id="df-workspace-input" class="large-text code" name="df_workspace_input" rows="7" spellcheck="false">{}</textarea></p>';
        echo '<details><summary>Input examples</summary><ul>';
        foreach ([
            'Catalog list: {}. Variants: {"blueprint_id":42,"provider_id":7}.',
            'Artwork: file_name and base64 contents of a PNG.',
            'Product creation: title, description, blueprint_id, print_provider_id, variants and print_areas.',
            'Product update: product_id, expected_version and changed fields. Read the product first to find its synthetic version.',
            'Shipping quote: {"order":{…}}. Order creation: external_id, line_items, shipping_method, flags and a fixture address_to.',
            'Order read, cancellation, synthetic production and fulfillment: {"order_id":"order-1"}.',
            'Event fixture: id, type, created_at and resource with type, id and integer shop_id under data.',
        ] as $hint) echo '<li>'.\esc_html($hint).'</li>';
        echo '</ul></details><p><button type="submit" class="button button-primary">Run synthetic operation</button></p></form>';

        echo '<h3>Recent synthetic audit</h3><table class="widefat striped"><thead><tr>';
        foreach (['Operation','Result','Reason','Reference'] as $label) echo '<th scope="col">'.\esc_html($label).'</th>';
        echo '</tr></thead><tbody>';
        foreach (array_reverse(array_slice($current['audit'],-20)) as $entry) {
            echo '<tr>';
            foreach (['operation','state','reason','operation_id'] as $field) {
                echo '<td>'.\esc_html((string)$entry[$field]).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function post(SyntheticWorkspace $workspace,string $shop): ?array
    {
        if (($_SERVER['REQUEST_METHOD']??null)!=='POST' || !isset($_POST['df_workspace_operation'])) {
            return null;
        }
        $nonce=$_POST['df_workspace_nonce']??null;
        $id=$_POST['df_workspace_id']??null;
        $op=$_POST['df_workspace_operation'];
        $revision=$_POST['df_workspace_revision']??null;
        $raw=$_POST['df_workspace_input']??null;
        if (!is_string($nonce)||!preg_match('/^[a-f0-9]{10}$/D',$nonce)||!\wp_verify_nonce($nonce,self::ACTION.':'.$shop)
            || !is_string($id)||!is_string($op)||strlen($op)>40||!is_string($revision)
            || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D',$revision)
            || !is_string($raw)||strlen($raw)>3000000) {
            return ['state'=>'DENIED','reason'=>'INVALID_FORM_OR_SESSION'];
        }
        try {
            $json=trim(\wp_unslash($raw));
            $input=json_decode($json,true,24,JSON_THROW_ON_ERROR);
            if (!str_starts_with($json,'{')||!is_array($input)) {
                return ['state'=>'DENIED','reason'=>'INPUT_MUST_BE_JSON_OBJECT'];
            }
            return $workspace->execute((int)$revision,$id,$op,$input);
        } catch (\JsonException) {
            return ['state'=>'DENIED','reason'=>'MALFORMED_OPERATION_JSON'];
        }
    }
}
