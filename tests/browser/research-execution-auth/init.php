<?php
declare(strict_types=1);
/** Creates one isolated WordPress table prefix in this task's private database. */
$evidence=getenv('PORTAL_CONTROLS_EVIDENCE');if(PHP_SAPI!=='cli'||$evidence!=='/workspace/lane-a-ai-research-http-20261117')throw new RuntimeException('Private CLI fixture only');
$prefix=getenv('RESEARCH_AUTH_PREFIX')?:'raut19_';if(!preg_match('/^raut[a-z0-9]{1,4}_$/D',$prefix))throw new RuntimeException('Private prefix invalid');
$dir=$evidence.'/research-auth';$repo=dirname(__DIR__,3);@mkdir($dir.'/core',0700,true);$source='/workspace/.onboarding/wordpress-develop/src';foreach(['wp-admin','wp-includes','wp-content','wp-settings.php','wp-load.php','wp-login.php']as $entry)if(!file_exists($dir.'/core/'.$entry))symlink($source.'/'.$entry,$dir.'/core/'.$entry);
$config=<<<'CONFIG'
<?php
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
define('DB_NAME','ai_research_http_20261117');define('DB_USER','root');define('DB_PASSWORD','');define('DB_HOST','localhost:/workspace/lane-a-ai-research-http-20261117/db.sock');define('DB_CHARSET','utf8mb4');define('DB_COLLATE','');$table_prefix='raut19_';
define('WP_HOME','http://127.0.0.1:18856');define('WP_SITEURL','http://127.0.0.1:18856');define('DISABLE_WP_CRON',true);define('WP_ENVIRONMENT_TYPE','local');define('WP_DEBUG',true);define('WP_DEBUG_DISPLAY',false);define('WP_DEBUG_LOG',dirname(__DIR__).'/wordpress.log');
foreach(['AUTH','SECURE_AUTH','LOGGED_IN','NONCE']as $scheme)foreach(['KEY','SALT']as $part)define($scheme.'_'.$part,hash('sha256','synthetic-private-research-auth-'.$scheme.'-'.$part));
define('DIGIFORGE_CREDENTIAL_KEY',hash('sha256','synthetic-private-research-auth-credential-cipher'));
if(is_file(dirname(__DIR__).'/authority.php')&&parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!=='/setup')require dirname(__DIR__).'/authority.php';
require ABSPATH.'wp-settings.php';
add_filter('pre_wp_mail',static fn()=>false,PHP_INT_MAX);
// Keep admin-post/auth/nonce intact; unrelated core update checks are outside this fixture.
foreach(['_maybe_update_core','_maybe_update_plugins','_maybe_update_themes']as $callback)remove_action('admin_init',$callback);
require_once '/workspace/lane-a-product-factory-1266/digiforge.php';
require_once '/workspace/lane-a-product-factory-1266/tests/browser/research-execution-auth/fixture.php';
(new DigiForge\Portal\CommercialResearchPanel(ResearchHttpFixture::service()))->register();
$GLOBALS['image_auth_http_calls']=0;add_filter('pre_http_request',static function(){$GLOBALS['image_auth_http_calls']++;return new WP_Error('private_image_http_denied','All outgoing HTTP forbidden in private image acceptance.');},PHP_INT_MAX);
register_shutdown_function(static function(){
 $entry=['method'=>$_SERVER['REQUEST_METHOD']??'CLI','path'=>parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH),'status'=>http_response_code(),'cookie_actor'=>get_current_user_id(),'operation'=>is_string($_POST['operation']??null)?$_POST['operation']:null,'http_calls'=>$GLOBALS['image_auth_http_calls']??-1];file_put_contents(dirname(__DIR__).'/requests.jsonl',json_encode($entry)."\n",FILE_APPEND|LOCK_EX);
});
CONFIG;
$database=new mysqli('localhost','root','','ai_research_http_20261117',0,$evidence.'/db.sock');$statement=$database->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='ai_research_http_20261117' AND LEFT(TABLE_NAME,?)=?");$length=strlen($prefix);$statement->bind_param('is',$length,$prefix);$statement->execute();$count=$statement->get_result()->fetch_row()[0];$database->close();if((int)$count>0)throw new RuntimeException('Private prefix already exists; refusing automatic reinstall.');
$config=str_replace("\$table_prefix='raut19_';","\$table_prefix='".$prefix."';",$config);file_put_contents($dir.'/core/wp-config.php',$config);chmod($dir.'/core/wp-config.php',0600);
// Refuse reinstalling a populated prefix. Existing evidence must be explicitly inspected.
define('WP_INSTALLING',true);$_SERVER['REQUEST_URI']='/init';require $dir.'/core/wp-config.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';$installed=wp_install('Private synthetic image acceptance','synthetic_private_admin','synthetic@example.invalid',false,'',wp_generate_password(40));if(!is_array($installed))throw new RuntimeException('WordPress fixture installation failed');DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();file_put_contents($dir.'/ready.marker','Private synthetic fixture only');echo "Private WordPress fixture initialized; no suite executed.\n";
