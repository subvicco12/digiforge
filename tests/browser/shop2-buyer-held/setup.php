<?php
declare(strict_types=1);
$dir=getenv('DF_LOCAL_BROWSER_DIR');$repo=getenv('DF_LOCAL_BROWSER_REPO');
if(PHP_SAPI!=='cli'||getenv('DF_LOCAL_BROWSER_DISPOSABLE')!=='1'||!is_string($dir)||!is_file($dir.'/disposable.marker'))throw new RuntimeException('Explicit disposable CLI fixture required.');
require $dir.'/test-config.php';
if(!preg_match('/^(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$/D',DB_HOST)||!str_contains(DB_NAME,'tests')||!preg_match('/^b[a-f0-9]{3}_$/D',$table_prefix))throw new RuntimeException('Isolated loopback test database and fresh prefix required.');
$parts=explode(':',DB_HOST,2);$db=new mysqli($parts[0],DB_USER,DB_PASSWORD,DB_NAME,isset($parts[1])?(int)$parts[1]:3306);
$q=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND LEFT(table_name,?)=?');$name=DB_NAME;$length=strlen($table_prefix);$q->bind_param('sis',$name,$length,$table_prefix);$q->execute();if((int)$q->get_result()->fetch_row()[0]!==0)throw new RuntimeException('Existing prefix cannot be reused.');$db->close();
require $repo.'/vendor/autoload.php';
define('WP_TESTS_CONFIG_FILE_PATH',$dir.'/test-config.php');
define('DF_SHOP2_BROWSER_SETUP',true);
$GLOBALS['wp_tests_options']['upload_path']=$dir.'/content/uploads';
require $repo.'/tests/wordpress/bootstrap.php';
\DigiForge\Core\Activator::activate();
require __DIR__.'/fixture.php';
$fixture=new Shop2BuyerHeldBrowserFixture('testActualSelectedPhotoFlowsToCanonicalPlanHeldBundleAndRejectsSamePixelOldEpoch');
$journeys=$fixture->seed();
global $wpdb;
$operator=get_userdata(1);
$foreign=wp_insert_user(['user_login'=>'shop2_foreign_buyer','user_pass'=>wp_generate_password(40,false),'role'=>'subscriber']);
if(is_wp_error($foreign))throw new RuntimeException('Foreign fixture actor unavailable.');
$foreignPassword=wp_generate_password(40,false);wp_set_password($foreignPassword,$foreign);
$public="<?php\n";
foreach(['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT','DIGIFORGE_SHOP_ACCESS_TRUST_KEY','DIGIFORGE_CREDENTIAL_KEY']as $key)if(defined($key))$public.="if(!defined(".var_export($key,true)."))define(".var_export($key,true).",".var_export(constant($key),true).");\n";
foreach(['DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE'=>'authority.json','DIGIFORGE_PORTAL_OWNER_APPROVAL_FILE'=>'owner-approval.json']as $key=>$file){
    if(!defined($key)||!is_file(constant($key))||!copy(constant($key),$dir.'/'.$file))throw new RuntimeException('Existing fixture authority bytes unavailable.');
    chmod($dir.'/'.$file,0600);$public.="if(!defined(".var_export($key,true)."))define(".var_export($key,true).",".var_export($dir.'/'.$file,true).");\n";
}
file_put_contents($dir.'/public-authority.php',$public);chmod($dir.'/public-authority.php',0600);
update_option('upload_path',$dir.'/content/uploads');update_option('show_avatars',0);update_option('home',WP_HOME);update_option('siteurl',WP_SITEURL);update_option('template','df-shop2-browser');update_option('stylesheet','df-shop2-browser');update_option('permalink_structure','');
\DigiForge\Core\Settings::protectProduction();$wpdb->query('COMMIT');
$data=['schema'=>1,'source_sha'=>trim((string)shell_exec('git -C '.escapeshellarg($repo).' rev-parse HEAD')),'journeys'=>$journeys,'operator'=>['id'=>1,'email'=>$operator->user_email],'foreign'=>['id'=>(int)$foreign,'login'=>'shop2_foreign_buyer','password'=>$foreignPassword],'buyer_identity_authority'=>'DECLARED_LOCAL_FIXTURE_ONLY','native_order_version'=>null,'native_allocation_execution_authorized'=>false];
file_put_contents($dir.'/journeys.json',wp_json_encode($data));chmod($dir.'/journeys.json',0600);
