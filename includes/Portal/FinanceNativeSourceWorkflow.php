<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Finance\SourceAdmission;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use WP_Error;

/** Read-only, nonpersisting staging. Does not look up credentials or write Finance declarations. */
final class FinanceNativeSourceWorkflow
{
    public const ACTION='digiforge_finance_native_preview';
    public const MAX_REQUEST_BYTES=1048576;

    public static function processBrowser(array $post,string $shop):array|WP_Error
    {
        $access=self::authorize($shop);if($access instanceof WP_Error)return $access;
        if(!is_string($post['_wpnonce']??null)||strlen($post['_wpnonce'])>256||!wp_verify_nonce($post['_wpnonce'],self::ACTION))return self::error(403);
        $keys=array_keys($post);sort($keys);
        $expected=['_wpnonce','action','context_json','documents_json','shop_key'];
        if(array_key_exists('provider',$post))$expected[]='provider';if(array_key_exists('source_format',$post))$expected[]='source_format';sort($expected);
        if($keys!==$expected)return self::error(400);
        $size=0;foreach($post as$value){if(!is_string($value))return self::error(400);$size+=strlen($value);}
        if($size>self::MAX_REQUEST_BYTES)return self::error(413);
        if($post['action']!==self::ACTION||$post['shop_key']!==$shop)return self::error(400);
        // Only pinned native JSON can retain the legacy Etsy default; CSV has no inferred provider.
        if(($post['source_format']??'native_json')!=='native_json'&&!array_key_exists('provider',$post))return self::error(400);
        try{
            $context=json_decode($post['context_json'],true,32,JSON_THROW_ON_ERROR);
            $documents=json_decode($post['documents_json'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($context)||!is_array($documents)||($context['shop_key']??null)!==$shop)return self::error(400);
            // Both numeric account/business/store selectors are explicit assertions, not DB ownership evidence.
            // Only the selected shop is an authorization selector; no raw record is read from another tenant.
            $result=SourceAdmission::preview($post['provider']??'etsy',$documents,$context,$context,time(),$post['source_format']??'native_json');
        }catch(\Throwable){return self::error(400);}
        $access=self::authorize($shop);return $access instanceof WP_Error?$access:$result;
    }

    public static function render(string $shop):void
    {
        $access=self::authorize($shop);if($access instanceof WP_Error)return;
        $result=null;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)===self::ACTION){
            $length=$_SERVER['CONTENT_LENGTH']??'';
            $result=is_string($length)&&ctype_digit($length)&&(float)$length>self::MAX_REQUEST_BYTES?self::error(413):self::processBrowser(wp_unslash($_POST),$shop);
        }
        echo '<section class="df-panel" data-finance-native-source><h2>Native-source validation and CSV structure inspection</h2><p>Read-only local staging. No source is saved or imported. Account, business, store and integration IDs are declared context, not verified ownership. Authenticity, settlement, completeness and ownership remain UNKNOWN. All-four-shop Finance access is required.</p>';
        if($result instanceof WP_Error)echo '<p role="status" class="df-notice df-notice-error">BLOCKED: exact bounded source evidence could not be validated. Raw source and diagnostic details are withheld.</p>';
        elseif(is_array($result)){
            echo '<div role="status"><strong>'.self::e($result['state']).'</strong><p>Local stage SHA-256: <code>'.self::e($result['stage_hash']).'</code>. This digest does not establish authenticity or rights.</p></div>';
            echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Native-source integrity summaries"><table class="df-table"><thead><tr><th>Native schema</th><th>Observed at</th><th>Source SHA-256</th><th>Canonical SHA-256</th></tr></thead><tbody>';
            foreach($result['sources']as$source)echo '<tr><td>'.self::e($source['native_schema']).'</td><td>'.self::e($source['observed_at']).'</td><td><code>'.self::e($source['raw_source_sha256']).'</code></td><td><code>'.self::e($source['canonical_source_sha256']).'</code></td></tr>';
            echo '</tbody></table></div><p>Shared identity references: '.self::e($result['shared_identity_references']).'. Documents without a stable transaction identity: '.self::e($result['documents_without_transaction_identity']).'. No payment and ledger totals are added.</p><ul>';
            foreach($result['mapping_blockers']as$blocker)echo '<li>'.self::e($blocker).'</li>';
            echo '</ul>';
            if(($result['schema']??null)===\DigiForge\Finance\PrintifyOrderExportStage::SCHEMA)echo '<p>Order exports are not paid invoices or settlement. Observed Printify header only; monetary units, dates, statuses, identity and ownership remain UNKNOWN. Zero exported rows do not prove zero expenses.</p>';
            if(($result['schema']??null)===\DigiForge\Finance\EtsyMonthlyCsvStage::SCHEMA)echo '<p>Observed Etsy monthly header witness only. Original-file custody and monthly completeness remain UNKNOWN. Amounts, units, dates, types, IDs, corrections and settlement are not recognized. Zero exported rows do not prove zero expenses.</p>';
            if(isset($result['population']))echo '<p>CSV population control: '.self::e($result['population']['state']).'; physical rows: '.self::e($result['population']['physical_rows']).'. Declared row counts do not prove source completeness, transaction identities, native units or financial activity. '.(($result['source_contract_kind']??null)==='OBSERVED_HEADER_ONLY'?'Header pattern observed; financial interpretation remains BLOCKED.':'Provider CSV format remains BLOCKED.').'</p>';
        }
        $url=add_query_arg(['df_view'=>'finance','df_shop'=>$shop],OperatorReturnUrl::current());
        echo '<p>CSV structure mode requires the internal context version hash <code>'.self::e(hash('sha256',\DigiForge\Finance\CsvSourceStage::SCHEMA)).'</code>; this is not a provider schema hash. Each CSV document has raw, layout (delimiter and exact declared headers), declared_rows (integer or null), and capture with the same five scope fields, source_sha256, observed_at and local source_reference. At most 500 physical rows total, 64 columns and 4096 bytes per field. Header-only zero does not prove zero expenses. No column meaning, money, identity or sign is inferred.</p>';
        echo '<p>Observed Printify order profile requires source_contract_sha256 <code>'.self::e(\DigiForge\Finance\PrintifyOrderExportStage::HEADER_SHA256).'</code>, explicit printify provider and Personalized POD scope. Each document has raw, capture and declared_rows only; the exact 306-byte observed header is required. No layout or accounting mapping override is accepted. Native financial custody remains separately restricted.</p>';
        echo '<p>Observed Etsy monthly profile requires source_contract_sha256 <code>'.self::e(\DigiForge\Finance\EtsyMonthlyCsvStage::HEADER_SHA256).'</code>, explicit etsy provider and one active shop scope. Each document has raw, capture and declared_rows only. The exact 73-byte UTF-8 BOM/header/LF witness is required. This does not establish original-file custody or financial semantics.</p>';
        echo '<form method="post" action="'.esc_url($url).'" class="df-review-form"><input type="hidden" name="action" value="'.self::ACTION.'"><input type="hidden" name="shop_key" value="'.self::e($shop).'"><input type="hidden" name="_wpnonce" value="'.self::e(wp_create_nonce(self::ACTION)).'"><label>Inspection format <select name="source_format"><option value="native_json">Pinned native JSON</option><option value="csv_structure">CSV structure only — provider format unverified</option><option value="etsy_monthly_csv_v1">Etsy monthly CSV — observed header witness only</option><option value="printify_order_export_v1">Printify order export — observed header only</option></select></label><label>Source provider <select name="provider"><option value="etsy">Etsy — pinned native schema</option><option value="printify">Printify — paid-source contract required</option><option value="gelato">Gelato — paid-source contract required</option><option value="openai">OpenAI — settled-source contract required</option></select></label><label>Declared source context JSON <textarea name="context_json" rows="5" maxlength="65536" required></textarea></label><p>Exact keys: shop_key, environment, business_id, store_id, external_shop_id, external_account_id, integration_id, source_contract_sha256. IDs must be positive integers. Context does not grant provider ownership.</p><label>Source documents JSON <textarea name="documents_json" rows="8" maxlength="1048576" required></textarea></label><p>For native JSON, use one to 20 documents, each with native_schema, raw (original JSON as a string), and capture. Capture retains the parser’s exact five scope fields, source_sha256, observed_at and local source_reference. Each source is at most 64 KiB; combined original bytes at most 256 KiB. Future capture dates and duplicate/conflicting identities are blocked. Historical timestamps are not automatically stale.</p><button class="df-button" type="submit">Inspect source (no save)</button></form><p class="df-muted">No durable replay claim, accounting mapping, reconciliation, period approval or provider operation. Source inputs are cleared after every response. No raw customer text is shown.</p></section>';
    }

    private static function authorize(string $shop):true|WP_Error
    {
        if(get_current_user_id()<1)return self::error(401);
        if(!in_array($shop,['digital','personalized_pod'],true)||!current_user_can('manage_digiforge')||!current_user_can('manage_digiforge_finance'))return self::error(403);
        $allowed=ShopAccess::allowedShops('manage_digiforge_finance');
        if($allowed instanceof WP_Error)return self::error(503);
        return array_diff(ShopAccessPolicy::canonicalShops(),$allowed)===[]?true:self::error(403);
    }
    private static function e(mixed $value):string{return esc_html((string)$value);}
    private static function error(int $status):WP_Error{return new WP_Error('finance_native_source_blocked','Current authorized offline native-source validation is unavailable.',['status'=>$status,'state'=>'BLOCKED','authenticity'=>'UNKNOWN','external_execution_authorized'=>false,'persistence_performed'=>false]);}
}
