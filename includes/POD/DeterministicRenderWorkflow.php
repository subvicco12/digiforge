<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Database\Tables;
use WP_Error;

/** Portal/server local preparation; artifact review and external authorization remain separate. */
final class DeterministicRenderWorkflow
{
    public const ACTION='digiforge_local_render_prepare';
    public function register():void
    {
        add_action('admin_post_'.self::ACTION,[$this,'handle']);
        add_action('rest_api_init',function():void{register_rest_route('digiforge/v1','/pod/local-render/(?P<id>\d+)/preview/(?P<panel>[a-z][a-z0-9_-]{0,63})',['methods'=>'GET','permission_callback'=>static fn():bool=>current_user_can('manage_digiforge_pod'),'callback'=>function(\WP_REST_Request $r):\WP_REST_Response|WP_Error{$result=$this->preview((int)$r['id'],(string)$r['panel']);return $result instanceof WP_Error?$result:new \WP_REST_Response($result);}]);});
        add_action('rest_api_init',function():void{register_rest_route('digiforge/v1','/pod/local-render',['methods'=>'POST','permission_callback'=>static fn():bool=>current_user_can('manage_digiforge_pod'),'callback'=>function(\WP_REST_Request $r):\WP_REST_Response|WP_Error{if(strlen($r->get_body())>65536)return self::error('payload_invalid','Bounded request required.');$p=$r->get_json_params();if(!is_array($p))return self::error('payload_invalid','JSON object required.');$result=$this->submit($p);return $result instanceof WP_Error?$result:new \WP_REST_Response($result);}]);});
    }
    public function submit(array $input):array|WP_Error
    {
        if(($input['decision']??'')==='CONFIRM_LOCAL_RENDER_RECIPE'){
            if(($input['shop_key']??'')!=='personalized_pod'||!is_array($input['recipe']??null))return self::error('scope_invalid','Exact personalized shop and recipe required.');return (new DeterministicPersonalizationRenderer())->approveRecipe((string)($input['template_key']??''),(int)($input['template_version']??0),$input['recipe']);
        }
        return $this->prepare($input);
    }
    public function prepare(array $input):array|WP_Error
    {
        if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('reviewer_required','Authorized POD operator required.',403);
        if(($input['shop_key']??'')!=='personalized_pod'||($input['decision']??'')!=='PREPARE_LOCAL_ARTIFACTS')return self::error('scope_invalid','Explicit personalized shop and local preparation decision required.');
        global $wpdb;$wpdb->last_error='';$submission=$wpdb->get_row($wpdb->prepare("SELECT ps.payload_hash,li.order_id,li.provider_mapping_id FROM ".Tables::personalization_submissions()." ps INNER JOIN ".Tables::order_line_items()." li ON li.id=ps.order_line_item_id WHERE ps.id=%d AND ps.review_status='APPROVED' AND ps.reviewed_by>0 AND ps.reviewed_at IS NOT NULL AND li.validation_status='VALIDATED'",(int)($input['submission_id']??0)),ARRAY_A);if($wpdb->last_error!==''||!is_array($submission))return self::error('personalization_required','Stored approved personalization required.');
        $wpdb->last_error='';$template=$wpdb->get_row($wpdb->prepare('SELECT template_id,template_version,fingerprint,print_areas FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=%d AND template_status=%s',(string)($input['template_key']??''),(int)($input['template_version']??0),'VALIDATED'),ARRAY_A);if($wpdb->last_error!==''||!is_array($template))return self::error('template_required','Exact stored VALIDATED template required.');
        $render=['order_id'=>(int)$submission['order_id'],'provider_mapping_id'=>(int)$submission['provider_mapping_id'],'template_key'=>$template['template_id'],'template_version'=>(string)$template['template_version'],'template_sha256'=>$template['fingerprint'],'personalization_evidence_hash'=>$submission['payload_hash'],'render_mode'=>'DETERMINISTIC'];
        $areas=json_decode($template['print_areas'],true);if(!is_array($areas)||$areas===[])return self::error('template_invalid','Complete template geometry required.');$paths=[];$renderer=new DeterministicPersonalizationRenderer();
        try{foreach($areas as $area){$expected=$renderer->expected($render,(string)$area['position']);if($expected instanceof WP_Error)return $expected;$file=tempnam(sys_get_temp_dir(),'df-local-render-');if($file===false)return self::error('storage_unavailable','Local render staging unavailable.');$paths[$area['position']]=$file;if(file_put_contents($file,$expected['svg'],LOCK_EX)!==strlen($expected['svg']))return self::error('storage_unavailable','Local render staging is uncertain.');}
            return (new RenderEvidenceRepository())->createFromPanelArtifacts($render,$paths,$paths);
        }finally{foreach($paths as $path)@unlink($path);}
    }
    public function preview(int $renderId,string $position):array|WP_Error
    {
        if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('reviewer_required','Authorized POD preview reviewer required.',403);
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_render_evidence().' WHERE id=%d',$renderId),ARRAY_A);if($wpdb->last_error!==''||!is_array($row))return self::error('preview_unavailable','Exact render evidence unavailable.');$v=new RenderArtifactVerifier();$receipt=$v->assertCertified($row);if($receipt instanceof WP_Error)return $receipt;
        if(isset($receipt['buyer_previews'])){$panels=array_keys($receipt['buyer_previews']);}else{$template=$v->certifiedTemplate($row);if($template instanceof WP_Error)return $template;$panels=array_column($template['print_areas'],'position');}
        sort($panels,SORT_STRING);if($position==='')$position=$panels[0]??'';if(isset($receipt['buyer_previews'])){$artifact=$receipt['buyer_previews'][$position]??null;}else{$artifact=count($panels)===1&&$panels[0]===$position?$receipt['buyer_preview']:null;}
        if(!is_array($artifact))return self::error('preview_unavailable','Exact certified preview panel required.');$path=\DigiForge\ProductFactory\AssetStorage::absolutePath($artifact['storage_reference']);if($path===null)return self::error('preview_unavailable','Stored preview artifact unavailable.');$bytes=@file_get_contents($path,false,null,0,16777217);if(!is_string($bytes)||strlen($bytes)!==$artifact['bytes']||!hash_equals($artifact['sha256'],hash('sha256',$bytes)))return self::error('preview_uncertain','Stored preview bytes changed.');unset($artifact['storage_reference']);return $artifact+['position'=>$position,'available_panels'=>$panels,'content_base64'=>base64_encode($bytes),'external_execution_authorized'=>false];
    }
    public function processBrowser(array $post):array|WP_Error
    {
        if(!is_string($post['_wpnonce']??null)||!wp_verify_nonce($post['_wpnonce'],self::ACTION))return self::error('nonce_required','Valid local render nonce required.',403);
        if(isset($post['recipe_json'])){$raw=$post['recipe_json'];if(!is_string($raw)||strlen($raw)>32768)return self::error('recipe_invalid','Bounded recipe JSON required.');$post['recipe']=json_decode($raw,true);}
        return $this->submit($post);
    }
    public function handle():void{$result=$this->processBrowser(wp_unslash($_POST));wp_safe_redirect(add_query_arg(['df_view'=>'pod_personalized','df_shop'=>'personalized_pod','df_render_id'=>$result instanceof WP_Error?0:(int)($result['id']??0),'df_message'=>$result instanceof WP_Error?$result->get_error_message():'Local render evidence prepared. Separate visual review required; no production authorized.','df_error'=>$result instanceof WP_Error?'1':'0'],\DigiForge\Portal\OperatorReturnUrl::resolve(isset($_POST['return_url'])?wp_unslash($_POST['return_url']):'')));exit;}
    public function render(string $shop):void
    {
        if($shop!=='personalized_pod'||!current_user_can('manage_digiforge_pod'))return;
        if(isset($_GET['df_render_id'])){$id=absint($_GET['df_render_id']);$position=isset($_GET['df_render_panel'])?sanitize_key(wp_unslash($_GET['df_render_panel'])):'';$preview=$this->preview($id,$position);echo '<section class="df-panel"><h2>Stored buyer preview</h2>';if($preview instanceof WP_Error){echo '<div role="alert" class="df-notice df-notice-error">'.esc_html($preview->get_error_message()).'</div>';}else{echo '<nav aria-label="Certified preview panels" class="df-nav">';foreach($preview['available_panels']as $panel){$url=add_query_arg(['df_view'=>'pod_personalized','df_shop'=>'personalized_pod','df_render_id'=>$id,'df_render_panel'=>$panel],\DigiForge\Portal\OperatorReturnUrl::current());echo '<a href="'.esc_url($url).'"'.($panel===$preview['position']?' aria-current="true"':'').'>'.esc_html($panel).'</a>';}echo '</nav>';if($preview['bytes']>2097152){echo '<p>Preview exceeds the bounded inline display size; authenticated artifact inspection is required before visual approval.</p>';}else{echo '<img style="max-width:100%;height:auto" alt="'.esc_attr('Buyer-specific '.$preview['position'].' artifact awaiting visual review').'" src="data:'.esc_attr($preview['mime']).';base64,'.esc_attr($preview['content_base64']).'">';}}echo '<p>Preview visibility does not approve artwork or authorize production.</p></section>';}
        echo '<section class="df-panel"><h2>Deterministic local render preparation</h2><p>Approved personalization and exact template versions determine the stored artifacts. Recipe review and visual render approval are separate human decisions. No provider or production execution occurs.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="shop_key" value="personalized_pod">';wp_nonce_field(self::ACTION);echo '<input type="hidden" name="return_url" value="'.esc_attr(\DigiForge\Portal\OperatorReturnUrl::current()).'">';
        echo '<p><label>Template identity <input name="template_key" maxlength="191" required></label></p><p><label>Exact template version <input type="number" min="1" name="template_version" required></label></p><p><label>Approved personalization submission ID <input type="number" min="1" name="submission_id"></label></p><p><label>Recipe JSON (recipe approval only) <textarea name="recipe_json" maxlength="32768" rows="6"></textarea></label></p><p><label>Explicit decision <select name="decision"><option value="">Choose after review</option><option value="CONFIRM_LOCAL_RENDER_RECIPE">Approve local recipe</option><option value="PREPARE_LOCAL_ARTIFACTS">Prepare local artifacts for visual review</option></select></label></p><button class="df-button" type="submit">Record local render workflow</button></form></section>';
    }
    private static function error(string $code,string $message,int $status=409):WP_Error{return new WP_Error('local_render_'.$code,$message,['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
