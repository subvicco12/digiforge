<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
use DigiForge\Database\Tables;
use DigiForge\Security\ShopAccess;

/** Registered but fail-closed WordPress entry points. No OAuth/worker action on boot. */
final class DriveGoogleWordPress
{
    public function __construct(private ?\Closure $compose=null){}
    public function register():void
    {
        add_action('admin_post_digiforge_google_oauth_start',[$this,'start']);
        add_action('admin_post_digiforge_google_oauth_callback',[$this,'callback']);
        add_action('admin_post_digiforge_google_queue',[$this,'queue']);
        add_action('digiforge_google_drive_job',[$this,'run']);
    }
    public static function callbackUrl():string{return admin_url('admin-post.php?action=digiforge_google_oauth_callback','https');}
    /** Server configuration is ciphertext only; no settings write or managed-key provisioning. */
    public function services():?DriveGoogleWorkflow
    {
        try{
            if($this->compose!==null){$service=($this->compose)();return $service instanceof DriveGoogleWorkflow?$service:null;}
            if(!defined('DIGIFORGE_GOOGLE_CLIENT_ID')||!is_string(DIGIFORGE_GOOGLE_CLIENT_ID)
                ||!defined('DIGIFORGE_GOOGLE_CLIENT_SECRET_CIPHERTEXT')||!is_string(DIGIFORGE_GOOGLE_CLIENT_SECRET_CIPHERTEXT))return null;
            $s=new DriveWordPressGrantStore();$g=new DriveWordPressGoogleGate();$h=new DriveWordPressGoogleHttp($g);
            $o=new DriveGoogleOAuth($s,$g,$h,DIGIFORGE_GOOGLE_CLIENT_ID,self::callbackUrl(),DIGIFORGE_GOOGLE_CLIENT_SECRET_CIPHERTEXT);
            $a=new DriveGoogleAuthority($s,$g,$o);$d=new DriveGoogleDispatcher($s,$g,$o,$a,$h);$files=new DriveWordPressArtifacts();
            return new DriveGoogleWorkflow($s,$g,$o,$a,$d,new DriveWordPressQueueJobs(),$files->source(...),$files->export(...),$files->finalize(...),self::callbackUrl());
        }catch(\Throwable){return null;}
    }
    public function start():never
    {
        $this->authorize('digiforge_google_oauth_start');$workflow=$this->services();
        $b=$this->requestedPlan($_POST);$session=wp_get_session_token();
        $r=$workflow!==null&&$b!==null&&$session!==''?$workflow->begin($b,$session):null;
        if($r===null)$this->deny();$url=$r['authorization_url'];
        if(!str_starts_with($url,'https://accounts.google.com/o/oauth2/v2/auth?'))$this->deny();
        nocache_headers();if(!headers_sent())header('Referrer-Policy: no-referrer');wp_redirect($url,302,'DigiForge Google OAuth');exit;
    }
    public function callback():never
    {
        // WordPress admin-post validates the logged-in cookie. State supplies CSRF protection;
        // no REST nonce or account/plan identity is trusted from Google's query string.
        if(($_SERVER['REQUEST_METHOD']??'')!=='GET'||!is_user_logged_in()||!current_user_can('manage_digiforge_connections'))$this->deny();
        $workflow=$this->services();$session=wp_get_session_token();
        $r=$workflow!==null&&$session!==''?$workflow->callback(wp_unslash($_GET),$session):null;
        nocache_headers();if(!headers_sent())header('Referrer-Policy: no-referrer');
        if($r===null)$this->deny();
        // Fixed local destination; never reflect code, state, tokens or provider error text.
        wp_safe_redirect(home_url('/?df_view=digital&df_shop=digital&df_google=connected&df_google_plan='.rawurlencode($r['plan_id'])));exit;
    }
    public function queue():never
    {
        $this->authorize('digiforge_google_queue');$workflow=$this->services();$id=$_POST['plan_id']??null;
        if(!is_string($id)||preg_match('/^[a-f0-9]{64}$/D',$id)!==1||$workflow===null)$this->deny();
        $role=$_POST['reconcile']??null;
        if(($_POST['refresh']??null)==='connection'){if(!$workflow->refresh($id))$this->deny();nocache_headers();wp_safe_redirect(home_url('/?df_view=digital&df_shop=digital&df_google=credential_refreshed&df_google_plan='.rawurlencode($id)));exit;}
        if(($_POST['renew']??null)==='grant'){if(!$workflow->renew($id))$this->deny();nocache_headers();wp_safe_redirect(home_url('/?df_view=digital&df_shop=digital&df_google=grant_renewed&df_google_plan='.rawurlencode($id)));exit;}
        $r=$role===null?$workflow->submit($id):(is_string($role)?$workflow->reconcile($id,$role):null);
        if($r===null)$this->deny();$notice=is_array($r)?match($r['state']??null){'UNKNOWN'=>'unknown','RUNNING'=>'running','TECHNICAL_QA_COMPLETE'=>'technical_qa_complete',default=>'queued'}:'queued';
        nocache_headers();wp_safe_redirect(home_url('/?df_view=digital&df_shop=digital&df_google='.$notice.'&df_google_plan='.rawurlencode($id)));exit;
    }
    public function run(mixed $jobId):void
    {
        if(!is_int($jobId)||$jobId<1)return;$workflow=$this->services();if($workflow===null){(new DriveWordPressQueueJobs())->block($jobId);return;}
        $actor=$workflow->actorForJob($jobId);if($actor===null)return;$previous=get_current_user_id();
        try{wp_set_current_user($actor);$workflow->run($jobId);}finally{wp_set_current_user($previous);}
    }
    public function requestedPlan(array $input):?array
    {
        try{
            $revision=$input['revision_id']??null;$request=$input['request_id']??null;
            if(!is_string($revision)||preg_match('/^[1-9][0-9]{0,9}$/D',$revision)!==1
                ||!is_string($request)||preg_match('/^[a-f0-9]{64}$/D',$request)!==1)return null;
            global $wpdb;$wpdb->last_error='';$r=$wpdb->get_row($wpdb->prepare('SELECT provenance FROM '.Tables::asset_revisions().' WHERE id=%d',(int)$revision),ARRAY_A);
            if($wpdb->last_error!==''||!is_array($r))return null;$p=json_decode($r['provenance'],true,32,JSON_THROW_ON_ERROR);
            $b=['installation_sha256'=>hash('sha256',site_url()),'environment'=>wp_get_environment_type(),'shop_key'=>'digital','folder_id'=>DriveTransferRequest::FOLDER,
                'actor_id'=>get_current_user_id(),'product_id'=>$p['binding']['product_id'],'product_version_id'=>$p['binding']['product_version_id'],
                'revision_id'=>(int)$revision,'source_sha256'=>$p['source_sha256']];
            $b=['id'=>hash('sha256',DriveGoogleOAuth::digest($b).':'.$request)]+$b;
            return (new DriveWordPressGoogleGate())->allows($b)?$b:null;
        }catch(\Throwable){return null;}
    }
    private function authorize(string $nonce):void
    {
        if(($_POST['df_confirm_google_action']??null)!=='1'||($_SERVER['REQUEST_METHOD']??'')!=='POST'||!is_user_logged_in()||!current_user_can('manage_digiforge_connections')
            ||!current_user_can('manage_digiforge_settings')||ShopAccess::authorize('digital','manage_digiforge_products')!==true)$this->deny();
        check_admin_referer($nonce);
    }
    private function deny():never{nocache_headers();wp_die('Google workflow blocked. No external execution or commercial approval is granted.','',['response'=>403]);}
}
