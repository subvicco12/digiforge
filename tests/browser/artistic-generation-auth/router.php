<?php
declare(strict_types=1);
/** Private HTTP acceptance router. POST dispatches actual WP admin-post, never process() or forced actor. */
$evidence=getenv('PORTAL_CONTROLS_EVIDENCE');$variant=getenv('ARTISTIC_AUTH_VARIANT')?:'artistic-auth';if(!preg_match('/^artistic-auth(?:-v[0-9]+)?$/D',$variant)){http_response_code(403);exit;}if($evidence!=='/workspace/lane-a-artistic-http-20261117'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||!is_file($evidence.'/'.$variant.'/ready.marker')){http_response_code(403);exit;}$dir=$evidence.'/'.$variant;$GLOBALS['artistic_fixture_dir']=$dir;$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$repo=dirname(__DIR__,3);
if(preg_match('~/(?:assets|df-assets)/([a-z0-9-]+\.(?:css|js))$~D',(string)$path,$match)){$file=$repo.'/assets/'.$match[1];if(!is_file($file)){http_response_code(404);exit;}header('Content-Type: '.(str_ends_with($file,'.css')?'text/css':'application/javascript'));readfile($file);exit;}
if($path==='/wp-admin/admin-post.php'){
 if($_SERVER['REQUEST_METHOD']!=='POST'||($_POST['action']??null)!=='digiforge_commercial_research'){http_response_code(405);exit;}
 define('ABSPATH',$dir.'/core/');require ABSPATH.'wp-admin/admin-post.php';exit;
}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
require $dir.'/core/wp-config.php';
function image_auth_state():array {$p=$GLOBALS['artistic_fixture_dir'].'/state.json';$state=json_decode(file_get_contents($p),true);if(!is_array($state))throw new RuntimeException('Private fixture state absent');return $state;}
try{
 if($path==='/setup'){
  if(is_file($dir.'/state.json'))throw new RuntimeException('Setup already exists; refuses replacing synthetic evidence');
  require_once $repo.'/tests/wordpress/fixtures/ShopAccessFixture.php';require_once $repo.'/tests/wordpress/fixtures/ImagePanelFixture.php';
  $id=wp_create_user('synthetic_image_cookie_actor',wp_generate_password(40));if(is_wp_error($id))throw new RuntimeException($id->get_error_code());$u=new WP_User($id);$u->set_role('administrator');wp_set_current_user($id);ShopAccessFixture::reset();ShopAccessFixture::authorizeActor($id,['personalized_pod'],['manage_digiforge_ai','manage_digiforge_pod','manage_digiforge_research']);global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
  $prepared=ArtisticHttpFixture::inputs();
  copy(DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE,$dir.'/authority.json');chmod($dir.'/authority.json',0600);file_put_contents($dir.'/authority.php',"<?php\ndefine('DIGIFORGE_SHOP_ACCESS_TRUST_KEY',".var_export(DIGIFORGE_SHOP_ACCESS_TRUST_KEY,true).");\ndefine('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE',".var_export($dir.'/authority.json',true).");\n");chmod($dir.'/authority.php',0600);
  file_put_contents($dir.'/state.json',wp_json_encode((['id'=>$id]+$prepared)));chmod($dir.'/state.json',0600);
  wp_set_auth_cookie($id,false,false);wp_safe_redirect(home_url('/'));exit;
 }
 if($path==='/setup-reconciliation'){
  if(is_file($dir.'/statement-state.json'))throw new RuntimeException('Statement evidence already exists');$state=image_auth_state();wp_set_current_user($state['id']);require_once $repo.'/tests/wordpress/fixtures/ShopAccessFixture.php';$acl=(new DigiForge\Security\ShopAccessRepository())->read('personalized_pod');if(is_wp_error($acl))throw new RuntimeException($acl->get_error_code());$oldGrant=null;foreach($acl['grants']as $grant)if($grant['actor_id']===$state['id']&&!$grant['revoked']){$oldGrant=$grant;break;}if(!is_array($oldGrant))throw new RuntimeException('Synthetic grant absent');ShopAccessFixture::grant($oldGrant['grantor_id'],$state['id'],'personalized_pod',$oldGrant['caps'],$oldGrant['role']);require_once $repo.'/tests/browser/artistic-generation-auth/statement-fixture.php';$statement=StatementHttpFixture::create($state['policy']['policy']['binding']);file_put_contents($dir.'/statement-state.json',wp_json_encode($statement));chmod($dir.'/statement-state.json',0600);wp_set_auth_cookie($state['id'],false,false);wp_safe_redirect(add_query_arg(['df_research_version'=>$statement['source']],home_url('/')));exit;
 }
 $state=image_auth_state();
 if($path==='/statement-status'){
  if(get_current_user_id()!==$state['id']){http_response_code(403);exit;}$proof=json_decode(file_get_contents($dir.'/statement-state.json'),true);$cost=(new DigiForge\Research\ResearchExecution())->costEvidence('personalized_pod',$proof['job_id']);if(is_wp_error($cost)){http_response_code(403);exit;}header('Content-Type: application/json');echo wp_json_encode(['cost'=>$cost,'document'=>$proof['document']]);exit;
 }
 if($path==='/status'){
  if(get_current_user_id()!==$state['id']){http_response_code(403);exit;}$engine=$_GET['engine']??null;$case=$state['cases'][$engine]??null;if(!is_array($case)){http_response_code(400);exit;}$jobs=(new DigiForge\AI\ImageGenerationJournal())->forDesign($case['spec']['hash']);if(is_wp_error($jobs)){http_response_code(403);exit;}$job=$jobs[0]??null;header('Content-Type: application/json');echo wp_json_encode(['job'=>$job,'quote'=>$job?ImagePanelFixture::quote($job):null,'http_calls'=>$GLOBALS['image_auth_http_calls']]);exit;
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
 $selected=null;foreach($state['cases']as $engine=>$case){$url=add_query_arg(['df_research_version'=>$case['spec']['record']['commercial_hash'],'df_factory_version'=>$case['spec']['hash']],home_url('/'));echo '<p><a href="'.esc_url($url).'">'.esc_html($engine).'</a></p>';if(($_GET['df_research_version']??null)===$case['spec']['record']['commercial_hash'])$selected=$case;}if($selected!==null){if(!isset($_GET['df_factory_version']))$_GET['df_factory_version']=$selected['spec']['hash'];(new DigiForge\Portal\CommercialResearchPanel(null,ArtisticHttpFixture::execution()))->render('personalized_pod');}else (new DigiForge\Portal\CommercialResearchPanel())->render('personalized_pod');echo '</main></body></html>';
}catch(Throwable $e){http_response_code(500);header('Content-Type: application/json');echo wp_json_encode(['fixture_error'=>$e->getMessage()]);}
