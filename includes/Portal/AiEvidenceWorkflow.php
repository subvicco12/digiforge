<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\AI\MonetaryReservationRepository;
use DigiForge\AI\ProviderEvidenceRepository;
use WP_Error;

/** Local human-reviewed evidence only: never executes or activates an AI provider. */
final class AiEvidenceWorkflow
{
    public const ACTION='digiforge_ai_evidence_review';
    public function register():void{add_action('admin_post_'.self::ACTION,[$this,'handle']);}
    public function submit(array $input):array|WP_Error
    {
        if(!current_user_can('manage_digiforge_ai')||get_current_user_id()<1)return new WP_Error('ai_review_forbidden','AI evidence reviewer capability required.',['status'=>403]);
        $shop=$input['shop_key']??null;if(!is_string($shop)||!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true))return new WP_Error('ai_review_shop_required','Select one exact concrete shop.',['status'=>400]);
        if(($input['operation']??'')==='attribution')return (new \DigiForge\AI\CostAttributionRepository())->review((int)($input['usage_id']??0),$shop,$input,(string)($input['decision']??''));
        $document=$input['document']??null;if(!is_string($document)||strlen($document)<2||strlen($document)>32768)return new WP_Error('ai_review_document_required','A bounded actual provider document is required.',['status'=>400]);
        if(($input['operation']??'')==='tariff')return (new MonetaryReservationRepository())->reviewTariff($shop,$document,(string)($input['decision']??''));
        if(($input['operation']??'')==='quote')return (new MonetaryReservationRepository())->reviewQuote($shop,$document,(string)($input['decision']??''));
        if(($input['operation']??'')!=='charge'||($input['decision']??'')!=='CONFIRM_PROVIDER_STATEMENT_CHARGE')return new WP_Error('ai_review_decision_required','Explicit provider statement charge review required.',['status'=>400]);
        $usageId=(int)($input['usage_id']??0);$repo=new ProviderEvidenceRepository();$snapshot=$repo->snapshot($usageId,$shop);if($snapshot instanceof WP_Error)return $snapshot;$meter=$snapshot['metering'];if(!is_array($meter))return new WP_Error('ai_review_metering_required','Completed provider evidence required.',['status'=>409]);
        $statement=json_decode($document,true);if(!is_array($statement)||($statement['provider']??'')!=='openai'||!is_string($statement['invoice_id']??null)||!is_array($statement['lines']??null)||count($statement['lines'])>100)return new WP_Error('ai_review_statement_invalid','Bounded provider invoice and line evidence required.',['status'=>400]);
        $matches=[];$seen=[];
        foreach($statement['lines']as $line){if(!is_array($line)||!is_string($line['line_id']??null)||isset($seen[$line['line_id']]))return new WP_Error('ai_review_statement_invalid','Unique statement line identities required.',['status'=>400]);$seen[$line['line_id']]=true;if(($line['response_id']??null)===$meter['response_id'])$matches[]=$line;}
        if(count($matches)!==1)return new WP_Error('ai_review_statement_identity','Exactly one statement line must identify the metered response.',['status'=>409]);
        $evidence=$matches[0];$evidence['source']='provider_statement';$evidence['source_sha256']=hash('sha256',$document);$evidence['source_document']=$document;$evidence['invoice_id']=$statement['invoice_id'];
        return $repo->settle($usageId,$shop,$evidence);
    }
    public function processBrowser(array $post):array|WP_Error
    {
        if(!is_string($post['_wpnonce']??null)||!wp_verify_nonce($post['_wpnonce'],self::ACTION))return new WP_Error('ai_review_nonce','Valid evidence review nonce required.',['status'=>403]);
        return $this->submit($post);
    }
    public function handle():void
    {
        $result=$this->processBrowser(wp_unslash($_POST));$shop=is_string($_POST['shop_key']??null)?sanitize_key(wp_unslash($_POST['shop_key'])):'all';$message=$result instanceof WP_Error?$result->get_error_message():'Reviewed evidence recorded. No AI execution or activation authorized.';
        wp_safe_redirect(add_query_arg(['df_view'=>'ai_budget','df_shop'=>$shop,'df_message'=>$message,'df_error'=>$result instanceof WP_Error?'1':'0'],\DigiForge\Portal\OperatorReturnUrl::resolve(isset($_POST['return_url'])?wp_unslash($_POST['return_url']):'')));exit;
    }
    public function render(string $shop):void
    {
        if(!current_user_can('manage_digiforge_ai')||!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true))return;
        echo '<section class="df-panel"><h2>Provider evidence review</h2><p>Import an inclusive provider tariff, exact-request maximum quote or a statement line for an existing reservation. Human attestation does not independently certify provider authenticity. No provider call, activation or money movement occurs.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="shop_key" value="'.esc_attr($shop).'">';wp_nonce_field(self::ACTION);echo '<input type="hidden" name="return_url" value="'.esc_attr(\DigiForge\Portal\OperatorReturnUrl::current()).'">';
        echo '<p><label>Evidence type <select name="operation"><option value="tariff">Inclusive provider tariff</option><option value="quote">Inclusive request quote</option><option value="charge">Provider statement charge</option><option value="attribution">Reviewed product/order attribution</option></select></label></p><p><label>Usage reservation ID (charge or attribution) <input type="number" min="1" name="usage_id"></label></p><p><label>Stored product ID (attribution only) <input type="number" min="1" name="product_id"></label></p><p><label>Stored order ID (attribution only) <input type="number" min="1" name="order_id"></label></p><p><label>Actual provider evidence JSON <textarea name="document" maxlength="32768" rows="8"></textarea></label></p><p><label>Explicit review decision <select name="decision"><option value="">Choose after review</option><option value="CONFIRM_PROVIDER_TARIFF">CONFIRM_PROVIDER_TARIFF</option><option value="CONFIRM_PROVIDER_QUOTE">CONFIRM_PROVIDER_QUOTE</option><option value="CONFIRM_PROVIDER_STATEMENT_CHARGE">CONFIRM_PROVIDER_STATEMENT_CHARGE</option><option value="CONFIRM_COST_ATTRIBUTION">CONFIRM_COST_ATTRIBUTION</option></select></label></p><button class="df-button" type="submit">Record reviewed provider evidence</button></form></section>';
    }
}
