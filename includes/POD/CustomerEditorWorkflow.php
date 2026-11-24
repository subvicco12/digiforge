<?php
declare(strict_types=1);
namespace DigiForge\POD;
use WP_Error;
/** Cookie-authenticated local customer drafts; production remains operator-gated. */
final class CustomerEditorWorkflow
{
 public function register():void {
  add_shortcode('digiforge_personalization_editor',[$this,'shortcode']);
  add_action('rest_api_init',function():void {
   register_rest_route('digiforge/v1','/customer-editor/access',['methods'=>'POST','permission_callback'=>fn($r)=>$this->permission($r,true),'callback'=>fn($r)=>$this->dispatch($r,'grant')]);
   register_rest_route('digiforge/v1','/customer-editor/mockup-evidence',['methods'=>'POST','permission_callback'=>fn($r)=>$this->permission($r,true),'callback'=>fn($r)=>$this->dispatch($r,'mockup')]);
   foreach(['asset'=>'mockupAsset','intake'=>'mockupIntake','review'=>'mockupReview','bind'=>'mockupBind']as $route=>$action)register_rest_route('digiforge/v1','/pod/product-mockup/'.$route,['methods'=>'POST','permission_callback'=>fn($r)=>$this->permission($r,true),'callback'=>fn($r)=>$this->dispatch($r,$action)]);
   foreach(['view'=>'GET','upload'=>'POST','fields'=>'POST','approve'=>'POST','revoke'=>'POST','intake'=>'POST','publish'=>'POST']as $action=>$verb)register_rest_route('digiforge/v1','/customer-editor/(?P<session>[A-Za-z0-9_-]{1,80})/'.$action,['methods'=>$verb,'permission_callback'=>fn($r)=>$this->permission($r,in_array($action,['revoke','intake','publish'],true)),'callback'=>fn($r)=>$this->dispatch($r,$action)]);
  });
 }
 private function permission(\WP_REST_Request $r,bool $operator):bool|WP_Error {
  if(get_current_user_id()<1||!current_user_can($operator?'manage_digiforge_pod':'read'))return new WP_Error('customer_editor_auth_required','Sign in with the authorized account.',['status'=>403]);
  if(!wp_verify_nonce($r->get_header('X-WP-Nonce'),'wp_rest'))return new WP_Error('customer_editor_nonce_required','Refresh this page before continuing.',['status'=>403]);return true;
 }
 public function dispatch(\WP_REST_Request $r,string $action):\WP_REST_Response|WP_Error {
  $allowed=$this->permission($r,in_array($action,['grant','mockup','revoke','intake','publish','mockupAsset','mockupIntake','mockupReview','mockupBind'],true));if($allowed instanceof WP_Error)return $allowed;
  if(strlen((string)$r->get_body())>(in_array($action,['upload','mockupAsset'],true)?8388608:65536))return new WP_Error('customer_editor_request_limit','The request exceeds the local limit.',['status'=>413]);
  $repo=new CustomerEditorRepository();$key=(string)$r->get_param('session');
  try{if($action==='view')$result=$repo->view($key);else{$p=$r->get_json_params();if(!is_array($p))throw new \InvalidArgumentException();$keys=match($action){'mockupAsset'=>['image_base64','sha256'],'mockupIntake'=>['reference','sha256','reference_render_id','parent_sha256'],'mockupReview'=>['recipe_sha256','outcome','flags','notes'],'mockupBind'=>['render_id','recipe_key','recipe_version','recipe_sha256'],'fields'=>['expected_head','fields'],'approve'=>['version_sha256','context_sha256'],'revoke'=>['access_sha256'],'intake'=>['version_sha256','image_field','rights','cutout_key'],'mockup'=>['reference','sha256'],default=>null};if($keys!==null&&(count($p)!==count($keys)||array_diff(array_keys($p),$keys)))throw new \InvalidArgumentException();$result=match($action){'mockupAsset'=>(new ProductMockupRegistry())->registerAsset($p['image_base64'],$p['sha256']),'mockupIntake'=>(new ProductMockupRegistry())->intake($p['reference'],$p['sha256'],$p['reference_render_id'],$p['parent_sha256']),'mockupReview'=>(new ProductMockupRegistry())->review($p['recipe_sha256'],$p['outcome'],$p['flags'],$p['notes']),'mockupBind'=>(new ProductMockupRegistry())->bind($p['render_id'],$p['recipe_key'],$p['recipe_version'],$p['recipe_sha256']),'grant'=>$repo->grant($p),'upload'=>$repo->upload($key,$p),'fields'=>$repo->saveFields($key,$p['expected_head'],$p['fields']),'approve'=>$repo->approve($key,$p['version_sha256'],$p['context_sha256']),'revoke'=>$repo->revoke($key,$p['access_sha256']),'intake'=>$repo->operatorIntake($key,$p['version_sha256'],$p['image_field'],$p['rights'],$p['cutout_key']),'publish'=>$repo->publish($key,$p),'mockup'=>(new CustomerProductMockup())->approveEvidence($p['reference'],$p['sha256']),default=>throw new \InvalidArgumentException()};}
   if($result instanceof WP_Error)return $result;$response=new \WP_REST_Response($result);$response->header('Cache-Control','private, no-store');return $response;
  }catch(\Throwable){return new WP_Error('customer_editor_typed_request','Exact typed input is required.',['status'=>400]);}
 }
 public function shortcode(array|string $attributes=[]):string {
  $a=shortcode_atts(['session'=>''],is_array($attributes)?$attributes:[]);$key=$a['session'];if($key==='')$key=isset($_GET['df_editor'])&&is_string($_GET['df_editor'])?wp_unslash($_GET['df_editor']):'';
  if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$key))return '<p>A personal order link is required.</p>';
  if(!is_user_logged_in())return '<p>Please <a href="'.esc_url(wp_login_url()).'">sign in</a> with your authorized order account.</p>';
  $access=(new CustomerEditorRepository())->access($key);if($access instanceof WP_Error)return '<p>This order link is unavailable for your account.</p>';
  wp_enqueue_style('digiforge-customer-editor',DIGIFORGE_URL.'assets/customer-editor.css',[],DIGIFORGE_VERSION);
  wp_enqueue_script('digiforge-customer-editor',DIGIFORGE_URL.'assets/customer-editor.js',[],DIGIFORGE_VERSION.'.'.substr(hash_file('sha256',DIGIFORGE_PATH.'assets/customer-editor.js'),0,12),true);
  return '<section class="df-editor" data-df-editor data-endpoint="'.esc_url(rest_url('digiforge/v1/customer-editor/'.rawurlencode($key).'/')).'" data-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'" aria-label="Personalize your artwork">'.self::markup().'</section>';
 }
 public static function markup():string {
  return <<<'HTML'
<h2>Personalize your artwork</h2><p>Local artwork review only. Your preference does not certify artwork or authorize production.</p>
<p data-status role="status" aria-live="polite">Loading your order…</p>
<form data-form><fieldset><legend>Your personalization</legend><div data-fields></div><label>Image field <select data-slot></select></label></fieldset>
<fieldset><legend>Photo preparation</legend><label><input type="radio" name="method" value="MANUAL" checked> Upload an already-cropped transparent PNG</label><label><input type="radio" name="method" value="AUTO"> Experimental offline background removal</label><p>PNG or JPEG; at most 4.5 MB and 1,048,576 pixels. Automatic extraction requires a selected head region and may need manual correction.</p><label>Photo <input data-file type="file" accept="image/png,image/jpeg"></label>
<div data-selection hidden><p>Select an interior rectangle around the head, then choose a foreground point inside it. Keep hair and ears within the rectangle. Numeric controls also support keyboard selection.</p><canvas data-source aria-label="Source photo for head selection"></canvas><div class="df-coordinates"><label>Left <input data-coordinate="x" type="number" min="1"></label><label>Top <input data-coordinate="y" type="number" min="1"></label><label>Width <input data-coordinate="width" type="number" min="1"></label><label>Height <input data-coordinate="height" type="number" min="1"></label><label>Foreground X <input data-coordinate="px" type="number" min="1"></label><label>Foreground Y <input data-coordinate="py" type="number" min="1"></label></div></div>
<label><input data-rights type="checkbox"> I declare I have permission to submit and locally review this image.</label><label><input data-consent type="checkbox"> I consent to local image processing for this order.</label><p>These declarations do not establish verified licensing or commercial certification.</p><button data-upload type="submit">Save new artwork version</button><button data-save-fields type="button">Save text changes</button></fieldset></form>
<section aria-label="Compare artwork"><h3>Original and cutout</h3><label>Cutout background <select data-background><option value="checker">Transparency checkerboard</option><option value="light">Light</option><option value="dark">Dark</option></select></label><label>Zoom <input data-zoom type="range" min="100" max="300" value="100"></label><div class="df-comparison"><figure><figcaption>Original</figcaption><div class="df-image"><img data-original alt="Your original uploaded image" hidden></div></figure><figure><figcaption>Cutout — review fine edges carefully</figcaption><div class="df-image checker" data-cutout-background><img data-cutout alt="Current transparent cutout" hidden></div></figure></div><p data-quality></p></section>
<section aria-label="Digital product preview"><h3>Digital product preview</h3><p>Separate from the cutout inspection. An approved display mapping and verified supplier geometry are required. This simulation is not a physical print guarantee.</p><p data-product-status></p><img data-product alt="Digital product mockup of the approved artwork" hidden><button data-refresh type="button">Refresh preview</button><button data-approve type="button" disabled>Approve this digital preview</button></section>
<section aria-label="Artwork version history"><h3>Version history</h3><ol data-history></ol></section>
HTML;
 }
}
