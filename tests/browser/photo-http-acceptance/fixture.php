<?php
// Disposable synthetic fixture only; workflow mutations run over HTTP in journey.py.
require '/workspace/lane-a-product-factory-1266/vendor/autoload.php';
define('WP_INSTALLING',true);
require '/workspace/lane-a-photo-http-20261116/site/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
$credentialPath='/workspace/lane-a-photo-http-20261116/private/credentials.json';if(!file_exists($credentialPath))file_put_contents($credentialPath,json_encode(['password'=>bin2hex(random_bytes(24))]));$password=json_decode(file_get_contents($credentialPath),true)['password'];
wp_install('Synthetic photo HTTP','http_admin','http@example.invalid',true,'',$password);
DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
class WP_UnitTestCase extends PHPUnit\Framework\TestCase {
 static function factory(){return (object)['user'=>new class {function create($x){$password=json_decode(file_get_contents('/workspace/lane-a-photo-http-20261116/private/credentials.json'),true)['password'];$x+=['user_login'=>'fixture_'.wp_generate_uuid4(),'user_pass'=>$password];return wp_insert_user($x);}}];}
 static function assertNotWPError($x,$m=''):void {if(is_wp_error($x))throw new RuntimeException($x->get_error_code().':'.$x->get_error_message());}
}
require '/workspace/lane-a-product-factory-1266/tests/wordpress/Support/PrintifyTemplateFixture.php';
require '/workspace/lane-a-product-factory-1266/tests/wordpress/RenderArtifactCertificationTest.php';
ShopAccessFixture::reset();$f=new RenderArtifactCertificationTest();$invoke=fn($m,$a=[])=>(new ReflectionMethod($f,$m))->invokeArgs($f,$a);
$profile=getenv('PHOTO_HTTP_PROFILE')?:'SINGLE_PHOTO';$count=$profile==='PHOTO_COLLAGE'?4:1;
$input=$invoke('deterministicFixture',[$profile,[['position'=>'front','decoration_method'=>'dtg','width_px'=>640,'height_px'=>480,'dpi'=>300,'qa_geometry'=>['safe_zone'=>['x'=>20,'y'=>20,'width'=>600,'height'=>440],'seam_exclusions'=>[],'min_image_dpi'=>72,'bleed'=>['trim_box'=>['x'=>10,'y'=>10,'width'=>620,'height'=>460],'margins_px'=>['left'=>10,'right'=>10,'top'=>10,'bottom'=>10]]]]]]);
$schema=[];$seed=[];for($i=0;$i<$count;$i++){$k=$i?'photo_'.$i:'photo';$schema[]=['key'=>$k,'type'=>'image_reference','required'=>true];$seed[$k]='digiforge/product-factory/source.png';}$args=[$input,$schema,$seed];(new ReflectionMethod($f,'familyPayload'))->invokeArgs($f,[&$args[0],$args[1],$args[2]]);$input=$args[0];$operator=get_current_user_id();ShopAccessFixture::authorizeActor($operator,['personalized_pod'],['manage_digiforge_pod','manage_digiforge_orders']);
$customer=wp_insert_user(['user_login'=>'http_buyer','user_pass'=>$password,'role'=>'subscriber']);$foreign=wp_insert_user(['user_login'=>'http_foreign','user_pass'=>$password,'role'=>'subscriber']);$grant=$invoke('editorGrant',[$input,$customer]);
wp_insert_post(['post_title'=>'Synthetic editor','post_status'=>'publish','post_type'=>'page','post_content'=>'[digiforge_personalization_editor]']);
global $wpdb;$wpdb->query('COMMIT');
copy(DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE,'/workspace/lane-a-photo-http-20261116/private/authority.json');file_put_contents('/workspace/lane-a-photo-http-20261116/private/trust.php',"<?php define('DIGIFORGE_SHOP_ACCESS_TRUST_KEY',".var_export(DIGIFORGE_SHOP_ACCESS_TRUST_KEY,true).");define('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE','/workspace/lane-a-photo-http-20261116/private/authority.json');");
file_put_contents('/workspace/lane-a-photo-http-20261116/private/fixture.json',json_encode(['input'=>$input,'grant'=>$grant,'operator_login'=>get_userdata($operator)->user_login]));
$safety=[];foreach(['stop_all','automation_armed','activation_authorized','printify_activation_authorized','gelato_activation_authorized','etsy_publish_activation_authorized','order_automation_activation_authorized']as $switch)$safety[$switch]=DigiForge\Core\Settings::get($switch);$safety['external_safety_locked']=DigiForge\Core\Settings::safety_locked();file_put_contents('/workspace/lane-a-photo-http-20261116/receipt/safety-settings.json',json_encode($safety,JSON_PRETTY_PRINT));
echo "Synthetic fixture prepared; private identities retained.\n";
