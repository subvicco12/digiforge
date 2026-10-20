<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Finance\AllocationReview;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use WP_Error;

/** Same-page local allocation persistence and independent consistency review. */
final class FinanceAllocationReviewWorkflow
{
    /** The profitability consumer shares the portal's stronger all-four Finance read guard. */
    public static function canRead(string $shop):true|WP_Error {return self::authorize($shop);}

    public static function processBrowser(array $post,string $selectedShop):array|WP_Error|null
    {
        $access=self::authorize($selectedShop);if($access instanceof WP_Error)return $access;
        if(!is_string($post['_wpnonce']??null)||strlen($post['_wpnonce'])>256||!wp_verify_nonce($post['_wpnonce'],FinanceAllocationReviewInput::ACTION))return self::error(403);
        try{$request=FinanceAllocationReviewInput::normalize($post,$selectedShop);}catch(\InvalidArgumentException){return self::error(400);}
        $decision=isset($request['decision']);$access=self::authorize($selectedShop,$decision);if($access instanceof WP_Error)return $access;
        try{
            $service=new AllocationReview();
            $result=match($request['operation']){
                'view'=>$service->read($request['source_hash'],$request['scope']),
                'propose'=>$service->propose($request['source_hash'],$request['scope'],$request['lines'],$request['effective_at'],$request['expires_at'],$request['request_id'],$request['expected_revision']),
                default=>$service->decide($request['source_hash'],$request['scope'],$request['expected_revision'],$request['proposal_hash'],$request['decision'],$request['request_id']),
            };
        }catch(\Throwable){$result=self::error(503);}
        $access=self::authorize($selectedShop,$decision);if($access instanceof WP_Error)return $access;
        if($result instanceof WP_Error){$data=$result->get_error_data();$status=is_array($data)?($data['status']??409):409;return self::error(in_array($status,[400,401,403,409,413,503],true)?$status:409);}
        if($result===null)return null;
        try{return FinanceAllocationReviewProjection::fromResult($result,$request['scope'],$request['source_hash']);}catch(\Throwable){return self::error(503);}
    }

    public static function render(string $shop):void
    {
        $access=self::authorize($shop);
        if($access instanceof WP_Error){echo '<section class="df-panel"><h2>Local allocation review</h2><p>BLOCKED: select one concrete shop with current Finance assignments for all four shops.</p></section>';return;}
        $result=null;$requested=false;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)===FinanceAllocationReviewInput::ACTION){
            $requested=true;$length=$_SERVER['CONTENT_LENGTH']??'';
            $queryMatches=is_string($_GET['df_shop']??null)&&$_GET['df_shop']===$shop;
            $result=!$queryMatches?self::error(400):(is_string($length)&&ctype_digit($length)&&(float)$length>FinanceAllocationReviewInput::MAX_REQUEST_BYTES
                ?self::error(413):self::processBrowser(wp_unslash($_POST),$shop));
        }
        echo '<section class="df-panel" data-finance-allocation-review><h2>Local allocation review</h2><p>Save explicit allocation evidence, read its version, and request an independent local consistency decision. CONFIRM_LOCAL_ALLOCATION records local review only. It grants no financial approval, reconciliation, certification or production authority. Authenticity: UNKNOWN · Source completeness: UNKNOWN.</p><p>Selected shop: '.self::e($shop).'. Current Finance assignments for all four shops remain required. Decisions also require current Settings access for this shop and an independent reviewer.</p>';
        echo self::result($result,$requested);
        self::openForm($shop,'view');echo '<button type="submit" class="df-button">Read local allocation status</button></form>';
        self::openForm($shop,'propose');
        echo '<label>Explicit allocation lines JSON <textarea name="lines_json" rows="8" maxlength="32768" required></textarea></label><p>Each line has exactly target, amount and base_amount. The target has shop_key, business_id, store_id, order_id, order_line_item_id, product_id, product_version_id, listing_id, provider_mapping_id, variant_ref, environment and currency. Amounts are explicit decimal strings. One to 100 lines; JSON limit 32 KiB, with the complete stored envelope also limited to 32 KiB. No allocation or money policy is inferred. Persistent reversal allocations are unsupported.</p>';
        foreach(['effective_at'=>'Effective at (UTC Unix seconds)','expires_at'=>'Expires at (UTC Unix seconds)'] as $name=>$label)echo '<label>'.$label.' <input type="number" name="'.$name.'" min="1" step="1" required></label>';
        self::mutationFields(0);echo '<button type="submit" class="df-button">Save local allocation proposal</button></form>';
        if(self::authorize($shop,true)===true){
            self::openForm($shop,null);echo '<label>Explicit local decision <select name="operation" required><option value="">Choose a local decision</option><option value="localreview">CONFIRM_LOCAL_ALLOCATION</option><option value="reject">REJECT_LOCAL_ALLOCATION</option><option value="revoke">REVOKE_LOCAL_ALLOCATION</option></select></label><label>Exact review proposal SHA-256 <input name="proposal_hash" pattern="[a-f0-9]{64}" maxlength="64" required></label>';
            self::mutationFields(1);echo '<button type="submit" class="df-button">Record selected local decision</button></form>';
        }else echo '<p>Current Settings access for this shop is required to record a local decision.</p>';
        echo '<p>Read status before changing a proposal. Copy its exact review proposal hash and revision into the decision form. Supply a new explicit request ID for every change. Raw form inputs are cleared after every response.</p><p class="df-muted">Money movement authority: NO · Tax filing authority: NO · Period approval authority: NO · External execution authority: NO. Local review never approves an actual financial period or executes a provider action.</p></section>';
    }

    private static function openForm(string $shop,?string $operation):void
    {
        $url=add_query_arg(['df_view'=>'finance','df_shop'=>$shop],OperatorReturnUrl::current());
        echo '<form method="post" action="'.esc_url($url).'" class="df-review-form"><input type="hidden" name="action" value="'.FinanceAllocationReviewInput::ACTION.'"><input type="hidden" name="_wpnonce" value="'.self::e(wp_create_nonce(FinanceAllocationReviewInput::ACTION)).'"><input type="hidden" name="shop_key" value="'.self::e($shop).'">';
        if($operation!==null)echo '<input type="hidden" name="operation" value="'.self::e($operation).'">';
        echo '<label>Allocation evidence environment <select name="environment" required><option value="">Choose environment</option><option value="test">test</option><option value="sandbox">sandbox</option><option value="production">production</option></select></label>';
        foreach(['business_id'=>'Stored business ID','store_id'=>'Stored store ID'] as $name=>$label)echo '<label>'.$label.' <input type="number" min="1" step="1" name="'.$name.'" required></label>';
        echo '<label>Exact source declaration SHA-256 <input name="source_hash" pattern="[a-f0-9]{64}" maxlength="64" required></label>';
    }
    private static function mutationFields(int $minimum):void
    {
        echo '<label>Expected current revision (0 only for a first proposal) <input type="number" name="expected_revision" min="'.$minimum.'" step="1" required></label><label>New request ID (16–128 letters, digits, underscores or hyphens) <input name="request_id" pattern="[A-Za-z0-9_-]{16,128}" minlength="16" maxlength="128" required></label>';
    }
    private static function result(array|WP_Error|null $result,bool $requested):string
    {
        if($result instanceof WP_Error)return '<p role="status" class="df-notice df-notice-error">BLOCKED: the exact current authorized local allocation request is unavailable. No amounts or decision are inferred. Raw inputs and source diagnostics are withheld.</p>';
        if($result===null)return '<p role="status">'.($requested?'No saved local allocation exists for that exact source and scope.':'No local allocation status requested.').' Amounts remain UNKNOWN.</p>';
        $scope=$result['scope'];$review=$result['review'];
        $out='<div class="df-confirmation" data-finance-allocation-review-result><p role="status"><strong>'.self::e($result['local_review_status']).'</strong> · local consistency status only</p><p>Shop: '.self::e($scope['shop_key']).' · Environment: '.self::e($scope['environment']).' · Business: '.self::e($scope['business_id']).' · Store: '.self::e($scope['store_id']).'</p><p>Source declaration SHA-256 <code>'.self::e($result['source_hash']).'</code> · Review proposal SHA-256 <code>'.self::e($review['proposal_hash']).'</code> · Review state SHA-256 <code>'.self::e($review['state_hash']).'</code></p><p>Revision: '.self::e($review['revision']).' · Version: '.self::e($review['version']).' · Author ID: '.self::e($review['author_id']).' · Reviewer ID: '.self::e($review['reviewer_id']).' · Effective UTC Unix seconds: '.self::e($review['effective_at']).' · Expires UTC Unix seconds: '.self::e($review['expires_at']).'</p>';
        $allocation=$result['allocation'];if($allocation===null)return $out.'<p>Current allocation amounts unavailable. Recovery metadata does not confer financial authority.</p></div>';
        $out.='<p>Packet: PROVISIONAL · UNREVIEWED. The local review status above is separate from the packet. Source ledger #'.self::e($allocation['source_ledger_id']).' · Explicit total: '.self::e($allocation['allocated_amount']).' '.self::e($allocation['currency']).' · Base total: '.self::e($allocation['allocated_base_amount']).' '.self::e($allocation['base_currency']).'. Actual settlement remains UNKNOWN.</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Local allocation evidence lines"><table class="df-table"><thead><tr><th>Order / line</th><th>Product / version</th><th>Listing</th><th>Mapping / variant</th><th>Amount</th><th>Base amount</th><th>Reference SHA-256</th></tr></thead><tbody>';
        foreach($allocation['lines'] as $line){$t=$line['target'];$out.='<tr><td>'.self::e($t['order_id']).' / '.self::e($t['order_line_item_id']).'</td><td>'.self::e($t['product_id']).' / '.self::e($t['product_version_id']).'</td><td>'.self::e($t['listing_id']).'</td><td>'.self::e($t['provider_mapping_id']).' / '.self::e($t['variant_ref']).'</td><td>'.self::e($line['amount']).'</td><td>'.self::e($line['base_amount']).'</td><td><code>'.self::e($line['reference_hash']).'</code></td></tr>';}
        return $out.'</tbody></table></div></div>';
    }
    private static function authorize(string $shop,bool $decision=false):true|WP_Error
    {
        if(get_current_user_id()<1)return self::error(401);
        if(!current_user_can('manage_digiforge')||!current_user_can('manage_digiforge_finance')||!ShopAccessPolicy::validShop($shop))return self::error(403);
        $allowed=ShopAccess::allowedShops('manage_digiforge_finance');if($allowed instanceof WP_Error)return self::error(503);
        if(array_diff(ShopAccessPolicy::canonicalShops(),$allowed)!==[])return self::error(403);
        if($decision&&(!current_user_can('manage_digiforge_settings')||ShopAccess::authorize($shop,'manage_digiforge_settings') instanceof WP_Error))return self::error(403);
        return true;
    }
    private static function error(int $status):WP_Error
    {
        return new WP_Error('finance_allocation_review_portal_blocked','A bounded current authorized local allocation request is unavailable.',['status'=>$status,'state'=>'BLOCKED','authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','actual_settlement_verified'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,'money_movement_authorized'=>false,'tax_filing_authorized'=>false]);
    }
    private static function e(mixed $value):string {return htmlspecialchars(is_string($value)||is_int($value)?(string)$value:'UNKNOWN',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
