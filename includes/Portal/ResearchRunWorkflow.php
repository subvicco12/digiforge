<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Launch\ExecutionEngine;
use DigiForge\Security\Logger;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use DigiForge\Security\ShopAccessRestGuard;
use WP_Error;

/** Explicit local consent for the existing governed REST operation, never activation. */
final class ResearchRunWorkflow
{
    public const PREPARE_ACTION='digiforge_portal_prepare_research_run';
    public const EXECUTE_ACTION='digiforge_portal_execute_research_run';
    private const TTL=300;
    public function register():void
    {
        add_action('admin_post_'.self::PREPARE_ACTION,[$this,'prepare']);
        add_action('admin_post_'.self::EXECUTE_ACTION,[$this,'execute']);
    }
    public function render(string $shop):void
    {
        echo '<section class="df-panel"><h2>Manual research run</h2><p>Prepare a specific research request, then explicitly confirm its current evidence. Output remains pending human review. This never enables providers, publishing or production.</p>';
        $access=$this->access($shop);
        if(is_wp_error($access)){echo '<p>Manual research requires a specific assigned shop and the existing global launch-route permissions.</p></section>';return;}
        $enabled=Settings::is_enabled('research')&&Settings::is_enabled('ai');
        if(!$enabled)echo '<p>Blocked: STOP ALL, external safety lock or effective Research/AI controls prohibit execution. No credential is read and no provider is called.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::PREPARE_ACTION).'"><input type="hidden" name="shop_key" value="'.esc_attr($shop).'">';wp_nonce_field(self::PREPARE_ACTION);
        echo '<p><label>Research phase <select name="phase"><option value="trend_scan">Trend scan</option><option value="deep_research">Deep research of approved selection</option></select></label></p><p><label>Approved candidate ID (deep research only) <input type="number" name="selected_candidate_id" min="1"></label></p><p><label>Market <input name="market" maxlength="512" value="United States and Europe"></label></p><p><label>Focus (required for trend scan) <input name="focus" maxlength="2048"></label></p><p><label>Research request (trend scan) <textarea name="query" maxlength="4096">Find a commercially attractive Etsy product opportunity</textarea></label></p><button class="df-button"'.($enabled?'':' disabled').'>Prepare research consent</button></form>';
        $id=$_GET['df_research_run']??null;
        if($enabled&&is_string($id)&&preg_match('/^[a-f0-9-]{36}$/D',$id)){
            $pending=$this->pending($id);
            if(is_array($pending)&&$pending['actor_id']===get_current_user_id()&&($pending['input']['shop']??null)===$shop){
                $current=$this->evidence($pending['input']);
                if(!is_wp_error($current)&&hash_equals($pending['evidence_hash'],$current)){
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::EXECUTE_ACTION).'"><input type="hidden" name="shop_key" value="'.esc_attr($shop).'"><input type="hidden" name="df_research_run" value="'.esc_attr($id).'"><input type="hidden" name="df_research_signature" value="'.esc_attr($this->signature($pending)).'">';wp_nonce_field(self::EXECUTE_ACTION);
                    echo '<dl>';foreach(['selected_candidate_id'=>'Approved selection','market'=>'Market','focus'=>'Focus','query'=>'Research request'] as $field=>$label)if(isset($pending['input'][$field]))echo '<dt>'.esc_html($label).'</dt><dd>'.esc_html((string)$pending['input'][$field]).'</dd>';echo '</dl>';
                    echo '<p>Shop: '.esc_html($shop).' · Phase: '.esc_html($pending['input']['phase']).' · Planned generation quantity: 1. Provider quote, task permissions and budget admission are still independently required at execution.</p><p>Current request evidence SHA-256 <code>'.esc_html($current).'</code></p><p><label><input type="checkbox" name="df_confirm_research_run" value="1"> I reviewed this exact request and consent to one governed research attempt. This is not artwork, listing or supplier approval.</label></p><button class="df-button">Confirm research attempt</button></form>';
                }else echo '<p>Research consent is stale or its evidence is unavailable. Prepare a new request.</p>';
            }
        }
        echo '</section>';
    }
    public function prepare():never
    {
        $this->nonce(self::PREPARE_ACTION);
        $shop=$_POST['shop_key']??'';$input=$this->input($_POST);
        if(is_wp_error($input))$this->respond(is_string($shop)?$shop:'',$input->get_error_message(),true);
        $admit=$this->access($input['shop']);if(is_wp_error($admit))$this->respond($input['shop'],$admit->get_error_message(),true);
        $evidence=$this->evidence($input);if(is_wp_error($evidence))$this->respond($input['shop'],$evidence->get_error_message(),true);
        $id=wp_generate_uuid4();$issued=time();$pending=['version'=>1,'operation_id'=>$id,'actor_id'=>get_current_user_id(),'issued_at'=>$issued,'expires_at'=>$issued+self::TTL,'input'=>$input,'evidence_hash'=>$evidence];$pending['seal']=$this->signature($pending);
        $json=wp_json_encode($pending);
        if(!is_string($json)||!Logger::write('portal_manual_research_prepared',['shop'=>$input['shop'],'request_hash'=>$evidence],'research_run',hash('sha256',$id))||!add_option('digiforge_manual_research_pending_'.$id,$json,'',false))$this->respond($input['shop'],'Research consent evidence could not be persisted.',true);
        $this->respond($input['shop'],'Research request prepared; explicit current-evidence confirmation is required.',false,['df_research_run'=>$id]);
    }
    public function execute():never
    {
        $this->nonce(self::EXECUTE_ACTION);
        $shop=$_POST['shop_key']??'';$id=$_POST['df_research_run']??'';$signature=$_POST['df_research_signature']??'';
        if(!is_string($shop)||!is_string($id)||!preg_match('/^[a-f0-9-]{36}$/D',$id)||!is_string($signature)||!preg_match('/^[a-f0-9]{64}$/D',$signature)||($_POST['df_confirm_research_run']??null)!=='1')$this->respond(is_string($shop)?$shop:'','Explicit valid research consent is required.',true);
        $access=$this->access($shop);if(is_wp_error($access))$this->respond($shop,$access->get_error_message(),true);
        $pending=$this->pending($id);
        if(!is_array($pending)||$pending['actor_id']!==get_current_user_id()||($pending['input']['shop']??null)!==$shop||!hash_equals($this->signature($pending),$signature))$this->respond($shop,'Research consent is expired, invalid or belongs to another actor/shop.',true);
        $current=$this->evidence($pending['input']);
        if(is_wp_error($current)||!hash_equals($pending['evidence_hash'],$current))$this->respond($shop,'Research consent is stale, blocked or its evidence is unavailable.',true);
        if(!Logger::write('portal_manual_research_consent',['shop'=>$shop,'request_hash'=>$current,'single_use'=>true],'research_run',hash('sha256',$id)))$this->respond($shop,'Research consent audit persistence is unavailable.',true);
        $receipt=wp_json_encode(['version'=>1,'actor_id'=>get_current_user_id(),'request_hash'=>$current,'pending_hash'=>hash('sha256',(string)wp_json_encode($pending)),'consumed_at'=>time()]);
        $name='digiforge_manual_research_consumed_'.$id;
        if(!is_string($receipt)||!add_option($name,$receipt,'',false))$this->respond($shop,'Research consent was already consumed or could not be recorded.',true);
        global $wpdb;$wpdb->last_error='';$stored=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$name));
        if($wpdb->last_error!==''||!is_string($stored)||!hash_equals($receipt,$stored))$this->respond($shop,'Research consent receipt could not be verified; reconciliation is required.',true);
        $request=new \WP_REST_Request('POST','/digiforge/v1/launch/research');$request->set_header('Content-Type','application/json');$request->set_header('Idempotency-Key','portal-research-'.$id);$request->set_body((string)wp_json_encode($pending['input']));
        $response=rest_do_request($request);
        if(is_wp_error($response))$this->respond($shop,$response->get_error_message(),true);
        $data=$response->get_data();
        if($response->get_status()>=400)$this->respond($shop,is_array($data)?(string)($data['message']??'Governed research was denied.'):'Governed research was denied.',true);
        if(!is_array($data)||($data['shop']??null)!==$shop||!is_array($data['candidate']??null)||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)($data['candidate']['id']??'')))$this->respond($shop,'Research result is uncertain; review immutable operation evidence before preparing another attempt.',true);
        $this->respond($shop,'Research completed; its recorded output is pending human review.',false,['df_candidate'=>(int)$data['candidate']['id']]);
    }
    private function access(string $shop):true|WP_Error
    {
        if(!in_array($shop,ShopAccessPolicy::canonicalShops(),true)||get_current_user_id()<1||!current_user_can('manage_digiforge'))return new WP_Error('manual_research_scope','An authenticated assigned canonical shop is required.');
        $sets=ShopAccessRestGuard::capabilitySets('/digiforge/v1/launch/research','POST');if(count($sets)!==1||$sets[0]===[])return new WP_Error('manual_research_scope','The governed research authorization contract is unavailable.');
        foreach($sets as $caps)foreach(ShopAccessPolicy::canonicalShops()as $key)foreach($caps as $cap){$decision=ShopAccess::authorize($key,$cap);if(is_wp_error($decision))return $decision;}
        return true;
    }
    private function input(array $body):array|WP_Error
    {
        $shop=$body['shop_key']??null;$phase=$body['phase']??null;
        if(!is_string($shop)||!in_array($shop,ShopAccessPolicy::canonicalShops(),true)||!is_string($phase)||!in_array($phase,['trend_scan','deep_research'],true))return new WP_Error('manual_research_input','A canonical shop and supported research phase are required.');
        $input=['shop'=>$shop,'phase'=>$phase,'run_context'=>['run_id'=>'portal-'.wp_generate_uuid4(),'started_at'=>gmdate('Y-m-d H:i:s')]];
        if($phase==='deep_research'){$id=$body['selected_candidate_id']??null;if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||(string)(int)$id!==$id)return new WP_Error('manual_research_input','Deep research requires an exact approved candidate ID.');$input['selected_candidate_id']=(int)$id;}
        else foreach(['market'=>512,'focus'=>2048,'query'=>4096]as $field=>$maximum){$value=$body[$field]??null;if(!is_string($value)||trim($value)===''||strlen($value)>$maximum)return new WP_Error('manual_research_input','Trend research requires bounded explicit market, focus and request fields.');$clean=sanitize_text_field($value);if($clean==='')return new WP_Error('manual_research_input','Research text must retain nonempty valid content.');$input[$field]=$clean;}
        return $input;
    }
    private function evidence(array $input):string|WP_Error
    {
        if(!Settings::is_enabled('research')||!Settings::is_enabled('ai'))return new WP_Error('manual_research_locked','STOP ALL / external safety lock or effective Research/AI controls block this request.');
        $contract=(new ExecutionEngine())->researchPreview($input);if(is_wp_error($contract))return $contract;
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare("SELECT policy,policy_hash,state FROM ".Tables::shop_ai_policies()." WHERE BINARY shop_key=BINARY %s AND environment='production'",$input['shop']),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($row)||$row['state']!=='ACTIVE'||!hash_equals((string)$row['policy_hash'],hash('sha256',(string)$row['policy'])))return new WP_Error('manual_research_policy','Current reviewed policy evidence is unavailable.');
        try{$policy=json_decode((string)$row['policy'],true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){return new WP_Error('manual_research_policy','Current policy is not valid typed evidence.');}if(!is_array($policy)||($policy['shop_key']??null)!==$input['shop'])return new WP_Error('manual_research_policy','Current policy shop identity is invalid.');
        $wpdb->last_error='';$usage=$wpdb->get_results($wpdb->prepare('SELECT BINARY stage stage,COUNT(*) records,MAX(id) latest,SUM(quantity) quantity,SUM(actual_cost) recorded_cost FROM '.Tables::shop_ai_usage().' WHERE BINARY shop_key=BINARY %s AND occurred_at>=%s GROUP BY BINARY stage ORDER BY BINARY stage',$input['shop'],gmdate('Y-m-01 00:00:00')),ARRAY_A);
        if($wpdb->last_error!==''||!is_array($usage))return new WP_Error('manual_research_usage','Current usage evidence is unavailable.');
        return hash('sha256',(string)wp_json_encode(['input'=>$input,'request'=>$contract,'policy_hash'=>$row['policy_hash'],'usage'=>$usage,'month'=>gmdate('Y-m'),'research_enabled'=>Settings::is_enabled('research'),'ai_enabled'=>Settings::is_enabled('ai')]));
    }
    private function pending(string $id):?array
    {
        $raw=get_option('digiforge_manual_research_pending_'.$id,false);if(!is_string($raw)||strlen($raw)>16384)return null;
        try{$value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){return null;}
        if(!is_array($value)||($value['version']??null)!==1||($value['operation_id']??null)!==$id||!is_int($value['actor_id']??null)||!is_int($value['issued_at']??null)||!is_int($value['expires_at']??null)||$value['expires_at']-$value['issued_at']!==self::TTL||$value['issued_at']>time()+5||$value['expires_at']<time()||!is_array($value['input']??null)||!is_string($value['evidence_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$value['evidence_hash']))return null;
        if(!is_string($value['seal']??null)||!hash_equals($this->signature($value),$value['seal']))return null;
        return $value;
    }
    private function nonce(string $action):void
    {
        $nonce=$_REQUEST['_wpnonce']??null;
        if(!is_string($nonce)||wp_verify_nonce($nonce,$action)===false){$ok=Logger::write('portal_manual_research_denied',['reason'=>'nonce','action'=>$action],'research_run','denied');wp_die($ok?'Invalid research request nonce.':'Access denial audit unavailable.','',['response'=>$ok?403:503]);}
        check_admin_referer($action);
    }
    private function signature(array $pending):string{unset($pending['seal']);return hash_hmac('sha256',(string)wp_json_encode($pending),wp_salt('auth'));}
    private function respond(string $shop,string $message,bool $error,array $extra=[]):never
    {
        if($error&&!Logger::write('portal_manual_research_denied',['shop'=>in_array($shop,ShopAccessPolicy::canonicalShops(),true)?$shop:'unassigned','action'=>current_action(),'reason_sha256'=>hash('sha256',$message)],'research_run','denied'))$message='Access denial audit unavailable.';
        $url=add_query_arg(['df_view'=>'research','df_shop'=>in_array($shop,ShopAccessPolicy::canonicalShops(),true)?$shop:'all','df_message'=>$message,'df_error'=>$error?'1':'0']+$extra,home_url('/'));
        wp_safe_redirect($url);exit;
    }
}
