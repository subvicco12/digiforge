<?php
declare(strict_types=1);
/** Private HTTP acceptance router. POST dispatches actual WP admin-post, never process() or forced actor. */
$evidence=getenv('PORTAL_CONTROLS_EVIDENCE');if($evidence!=='/workspace/lane-a-portal-controls-20261116'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!is_file($evidence.'/image-auth/ready.marker')){http_response_code(403);exit;}$dir=$evidence.'/image-auth';$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$repo=dirname(__DIR__,3);
if(preg_match('~/(?:assets|df-assets)/([a-z0-9-]+\.(?:css|js))$~D',(string)$path,$match)){$file=$repo.'/assets/'.$match[1];if(!is_file($file)){http_response_code(404);exit;}header('Content-Type: '.(str_ends_with($file,'.css')?'text/css':'application/javascript'));readfile($file);exit;}
if($path==='/wp-admin/admin-post.php'){
 if($_SERVER['REQUEST_METHOD']!=='POST'||($_POST['action']??null)!=='digiforge_commercial_research'){http_response_code(405);exit;}
 define('ABSPATH',$dir.'/core/');require ABSPATH.'wp-admin/admin-post.php';exit;
}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
require $dir.'/core/wp-config.php';
function image_auth_state():array {$p=getenv('PORTAL_CONTROLS_EVIDENCE').'/image-auth/state.json';$state=json_decode(file_get_contents($p),true);if(!is_array($state))throw new RuntimeException('Private fixture state absent');return $state;}
try{
 if($path==='/setup'){
  if(is_file($dir.'/state.json'))throw new RuntimeException('Setup already exists; refuses replacing synthetic evidence');
  require_once $repo.'/tests/wordpress/fixtures/ShopAccessFixture.php';require_once $repo.'/tests/wordpress/fixtures/ImagePanelFixture.php';
  $id=wp_create_user('synthetic_image_cookie_actor',wp_generate_password(40));if(is_wp_error($id))throw new RuntimeException($id->get_error_code());$u=new WP_User($id);$u->set_role('administrator');wp_set_current_user($id);ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($id,['personalized_pod'],['manage_digiforge_ai','manage_digiforge_pod','manage_digiforge_research']);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  $spec=ImagePanelFixture::specification();$policy=ImagePanelFixture::policy();$candidate=ImagePanelFixture::candidate($spec,$policy);$foreign=ImagePanelFixture::specification();
  copy(DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE,$dir.'/authority.json');chmod($dir.'/authority.json',0600);file_put_contents($dir.'/authority.php',"<?php\ndefine('DIGIFORGE_SHOP_ACCESS_TRUST_KEY',".var_export(DIGIFORGE_SHOP_ACCESS_TRUST_KEY,true).");\ndefine('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE',".var_export($dir.'/authority.json',true).");\n");chmod($dir.'/authority.php',0600);
  file_put_contents($dir.'/state.json',wp_json_encode(compact('id','spec','policy','candidate','foreign')));chmod($dir.'/state.json',0600);
  wp_set_auth_cookie($id,false,false);wp_safe_redirect(home_url('/'));exit;
 }
 $state=image_auth_state();
 if($path==='/counts'){
  if(get_current_user_id()!==$state['id']){http_response_code(403);exit;}global $wpdb;$raw=$wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_image_job_head_%'");$jobs=count($raw);$quotes=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_ai_money_quote_%'");$versions=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_design_personalized_pod_version_%'");header('Content-Type: application/json');echo wp_json_encode(['authenticated'=>is_user_logged_in(),'job_count'=>$jobs,'quote_count'=>$quotes,'version_count'=>$versions,'http_calls'=>$GLOBALS['image_auth_http_calls']]);exit;
 }
 if($path==='/status'){
  if(get_current_user_id()!==$state['id']){http_response_code(403);exit;}
  $jobs=(new DigiForge\AI\ImageGenerationJournal())->recent($state['spec']['hash']);if(is_wp_error($jobs)){http_response_code(403);echo wp_json_encode(['error'=>$jobs->get_error_code()]);exit;}
  global $wpdb;$quotes=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_ai_money_quote_%'");$versions=(new DigiForge\Research\DesignFactoryRepository())->recent('personalized_pod',$state['spec']['record']['commercial_hash']);require_once $repo.'/tests/wordpress/fixtures/ImagePanelFixture.php';$network=array_values(array_filter($jobs,static fn($j)=>$j['identity']['mode']==='NETWORK'));
  header('Content-Type: application/json');echo wp_json_encode(['actor_authenticated'=>is_user_logged_in(),'network_jobs'=>array_map(static fn($j)=>['id'=>$j['id'],'state'=>$j['state']],$network),'candidate'=>['id'=>$state['candidate']['id'],'source_sha256'=>$state['candidate']['result']['sha256']],'quote_document'=>$network!==[]?ImagePanelFixture::quote($network[0]):null,'quote_count'=>$quotes,'version_count'=>count($versions),'http_calls'=>$GLOBALS['image_auth_http_calls'],'foreign_factory'=>$state['foreign']['hash']]);exit;
 }
 if($path==='/revoke'){
  if(get_current_user_id()!==$state['id']){http_response_code(403);exit;}
  // Only this synthetic fixture actor is changed, through an authenticated GET fixture operation.
  wp_get_current_user()->add_cap('manage_digiforge_ai',false);header('Content-Type: application/json');echo wp_json_encode(['synthetic_ai_capability_revoked'=>true]);exit;
 }
 if($path!=='/'){http_response_code(404);exit;}
 if(!is_user_logged_in()){http_response_code(401);echo 'Private fixture requires an actual WordPress authentication cookie.';exit;}
 echo '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Private synthetic authenticated image acceptance</title><link rel="stylesheet" href="/df-assets/design-factory-controls.css"></head><body><main id="df-design-factory"><h1>Private synthetic authenticated image acceptance</h1>';
 if(isset($_GET['df_message']))echo '<p role="status">'.esc_html(is_string($_GET['df_message'])?$_GET['df_message']:'').'</p>';
 if(!isset($_GET['df_factory_version']))$_GET['df_factory_version']=$state['spec']['hash'];(new DigiForge\Portal\DesignFactoryPanel())->render('personalized_pod',$state['spec']['record']['commercial_hash']);echo '</main></body></html>';
}catch(Throwable $e){http_response_code(500);header('Content-Type: application/json');echo wp_json_encode(['fixture_error'=>$e->getMessage()]);}
