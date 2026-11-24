<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Finance\AllocationReadModel;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use WP_Error;

/** Same-page explicit allocation preview. No provider parsing, persistence, review or execution. */
final class FinanceImportPreviewWorkflow
{
    public static function processBrowser(array $post,string $selectedShop):array|WP_Error
    {
        $access=self::authorize($selectedShop);if($access instanceof WP_Error)return $access;
        if(!is_string($post['_wpnonce']??null)||strlen($post['_wpnonce'])>256||!wp_verify_nonce($post['_wpnonce'],FinanceImportPreviewInput::ACTION))return self::error('nonce',403);
        if(is_string($post['lines_json']??null)&&is_string($post['original_packet_json']??'')
            &&strlen($post['lines_json'])+strlen($post['original_packet_json']??'')>FinanceImportPreviewInput::MAX_JSON_BYTES)return self::error('bounded',413);
        try{$request=FinanceImportPreviewInput::normalize($post,$selectedShop);}catch(\InvalidArgumentException){return self::error('typed',400);}
        try{
            $packet=(new AllocationReadModel())->preview($request['source_hash'],$request['scope'],$request['lines'],$request['original_packet']);
            if($packet instanceof WP_Error){$status=$packet->get_error_data()['status']??409;return self::error('unavailable',in_array($status,[400,403,409,413,503],true)?$status:409);}
            $result=FinanceImportPreviewProjection::fromPacket($packet,$request['scope'],$request['source_hash']);
        }catch(\Throwable){return self::error('unavailable',503);}
        $access=self::authorize($selectedShop);return $access instanceof WP_Error?$access:$result;
    }

    public static function render(string $shop):void
    {
        $access=self::authorize($shop);
        if($access instanceof WP_Error){echo '<section class="df-panel"><h2>Explicit allocation preview</h2><p>Select one concrete shop with current Finance assignments for all four shops. Preview remains unavailable.</p></section>';return;}
        $result=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)===FinanceImportPreviewInput::ACTION){
            $length=$_SERVER['CONTENT_LENGTH']??'';
            $result=is_string($length)&&ctype_digit($length)&&(float)$length>FinanceImportPreviewInput::MAX_REQUEST_BYTES
                ?self::error('bounded',413):self::processBrowser(wp_unslash($_POST),$shop);
        }
        echo '<section class="df-panel" data-finance-allocation-preview><h2>Explicit allocation preview</h2><p>Read-only preview of an existing stored declaration and explicit current target lines. This does not parse raw provider documents or import, save, approve, reconcile or execute a financial action. Authenticity: UNKNOWN · Source completeness: UNKNOWN.</p><p>Selected shop: '.self::e($shop).'. Current Finance assignments for all four shops remain required. Source/history and target references must still be current when this page reads them.</p>';
        echo self::result($result);
        $url=add_query_arg(['df_view'=>'finance','df_shop'=>$shop],OperatorReturnUrl::current());
        echo '<form method="post" action="'.esc_url($url).'" class="df-review-form"><input type="hidden" name="action" value="'.FinanceImportPreviewInput::ACTION.'"><input type="hidden" name="_wpnonce" value="'.self::e(wp_create_nonce(FinanceImportPreviewInput::ACTION)).'"><input type="hidden" name="shop_key" value="'.self::e($shop).'">';
        echo '<label>Allocation evidence environment <select name="environment"><option value="test">test</option><option value="sandbox">sandbox</option><option value="production">production</option></select></label>';
        foreach(['business_id'=>'Stored business ID','store_id'=>'Stored store ID'] as $name=>$label)echo '<label>'.$label.' <input type="number" min="1" step="1" name="'.$name.'" required></label>';
        echo '<label>Current source declaration SHA-256 <input name="source_hash" pattern="[a-f0-9]{64}" maxlength="64" required></label><label>Explicit allocation lines JSON <textarea name="lines_json" rows="10" maxlength="65536" required></textarea></label><p>Each line has exactly target, amount and base_amount. The exact target tuple has shop_key, business_id, store_id, order_id, order_line_item_id, product_id, product_version_id, listing_id, provider_mapping_id, variant_ref, environment and currency. Amounts must be decimal strings. One to 200 lines; combined JSON limit 64 KiB. No equal split, remainder or target identity is inferred.</p><label>Exact original allocation packet JSON (reversals only, optional) <textarea name="original_packet_json" rows="5" maxlength="65536"></textarea></label><p>Reversal preview requires the complete unchanged original packet. This redacted display is not a replacement for that packet. Raw inputs are cleared after every response.</p><button type="submit" class="df-button">Preview explicit allocation</button></form><p class="df-muted">Money movement authority: NO · Tax filing authority: NO · Period approval authority: NO · External execution authority: NO · Persistence performed: NO.</p></section>';
    }

    private static function result(array|WP_Error|null $result):string
    {
        if($result===null)return '<p>No allocation preview requested. Amounts remain UNKNOWN.</p>';
        if($result instanceof WP_Error)return '<p role="status" class="df-notice df-notice-error">BLOCKED: an exact current authorized allocation preview is unavailable. No amount or safe action is inferred. Raw input and source diagnostics are withheld.</p>';
        $scope=$result['scope'];
        $out='<div class="df-confirmation" data-finance-allocation-result><p><strong>PROVISIONAL · UNREVIEWED</strong> · Shop: '.self::e($scope['shop_key']).' · Environment: '.self::e($scope['environment']).' · Business: '.self::e($scope['business_id']).' · Store: '.self::e($scope['store_id']).'</p><p>Source ledger #'.self::e($result['source_ledger_id']).' · Declaration SHA-256 <code>'.self::e($result['source_hash']).'</code> · Proposal SHA-256 <code>'.self::e($result['proposal_hash']).'</code></p><p>Explicit total: '.self::e($result['allocated_amount']).' '.self::e($result['currency']).' · Base total: '.self::e($result['allocated_base_amount']).' '.self::e($result['base_currency']).'. '.self::e($result['reconciliation_state']).' is a local proposal check; actual settlement remains UNKNOWN.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Explicit allocation preview lines"><table class="df-table"><thead><tr><th>Order</th><th>Order line</th><th>Product / version</th><th>Listing</th><th>Mapping / variant</th><th>Amount</th><th>Base amount</th><th>Reference SHA-256</th></tr></thead><tbody>';
        foreach($result['lines'] as $line){$t=$line['target'];$out.='<tr><td>'.self::e($t['order_id']).'</td><td>'.self::e($t['order_line_item_id']).'</td><td>'.self::e($t['product_id']).' / '.self::e($t['product_version_id']).'</td><td>'.self::e($t['listing_id']).'</td><td>'.self::e($t['provider_mapping_id']).' / '.self::e($t['variant_ref']).'</td><td>'.self::e($line['amount']).'</td><td>'.self::e($line['base_amount']).'</td><td><code>'.self::e($line['reference_hash']).'</code></td></tr>';}
        return $out.'</tbody></table></div></div>';
    }

    private static function authorize(string $shop):true|WP_Error
    {
        if(get_current_user_id()<1)return self::error('authentication',401);
        if(!current_user_can('manage_digiforge')||!current_user_can('manage_digiforge_finance')||!ShopAccessPolicy::validShop($shop))return self::error('access',403);
        $allowed=ShopAccess::allowedShops('manage_digiforge_finance');
        if($allowed instanceof WP_Error)return self::error('access',503);
        return array_diff(ShopAccessPolicy::canonicalShops(),$allowed)===[]?true:self::error('access',403);
    }
    private static function error(string $code,int $status):WP_Error
    {
        return new WP_Error('finance_import_preview_'.$code,'A bounded current authorized allocation preview is unavailable.',['status'=>$status,'state'=>'BLOCKED','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','actual_settlement_verified'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,'money_movement_authorized'=>false,'tax_filing_authorized'=>false,'persistence_performed'=>false]);
    }
    private static function e(mixed $value):string {return htmlspecialchars(is_string($value)||is_int($value)?(string)$value:'UNKNOWN',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
