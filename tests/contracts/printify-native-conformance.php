<?php
declare(strict_types=1);
/** Explicit local artifact probe. No provider request, credential access or runtime pin update. */
if($argc!==3||!is_dir($argv[1])||!preg_match('/^[a-f0-9]{40}$/D',$argv[2]))exit(2);
$core=realpath($argv[1]);$companion=dirname(__DIR__,2).'/companion/digiforge-printify-connector/';
// Minimal isolated error shim: this probe is not WordPress runtime acceptance.
if(!class_exists('WP_Error')){class WP_Error {public function __construct(public string $code='',public string $message='',public mixed $data=null){}}}
if(!function_exists('is_wp_error')){function is_wp_error(mixed $v):bool{return $v instanceof WP_Error;}}
spl_autoload_register(static function(string $c)use($core,$companion):void {
 $prefix='DigiForgePrintifyConnector\\';if(str_starts_with($c,$prefix))$p=$companion.str_replace('\\','/',substr($c,strlen($prefix))).'.php';
 elseif(str_starts_with($c,'DigiForge\\'))$p=$core.'/includes/'.str_replace('\\','/',substr($c,10)).'.php';else return;
 if(is_file($p))require_once $p;
},true,true);
$checks=0;$ok=static function(bool $b)use(&$checks){$checks++;if(!$b)throw new RuntimeException('Native conformance denied');};
try {
 $ok(is_wp_error(DigiForge\Connector\Printify\NativeBridge::connect('9.0.0')));
 $port=DigiForge\Connector\Printify\NativeBridge::connect('1.0.0');$ok($port instanceof DigiForgePrintifyConnector\Preparation\NativeCorePortInterface);
 $m=$port->contractManifest();$ok(DigiForgePrintifyConnector\Preparation\NativePortSchema::valid('manifest',$m));$ok($m['core_source_sha']===$argv[2]);$ok($m['spec_sha256']===DigiForgePrintifyConnector\Preparation\NativePortSchema::digest());
 foreach(['version','inspectionVersion','publicationVersion','workflowVersion','operationsVersion']as $method)$ok($port->$method()==='1.0.0');
 $ok($m['external_execution_authorized']===false);
 // Exercise actual denial methods; never synthesize an authoritative journal or credential.
 foreach(['exchange','inspect','recordWorkflow','decideReview','recordFulfillment']as $method)$ok(is_wp_error($port->$method([])));
 $called=false;$scope=new DigiForge\Connector\Printify\ShopBinding(2,'personalized_pod',7,'28930593','test',1,new DigiForge\Connector\Printify\CredentialReference(7),str_repeat('a',64),time()+300);
 $ok(is_wp_error($port->withWebhookSecret($scope,static function()use(&$called){$called=true;return 'forbidden';})));$ok(!$called);
 echo json_encode(['status'=>'NATIVE_MANIFEST_AND_DENIAL_CONFORMANT','source_sha'=>$argv[2],'checks'=>$checks,'advertised_capabilities'=>$m['capabilities'],'positive_native_workflows'=>'NOT_VERIFIED','provider_requests'=>false,'runtime_pin_changed'=>false],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Native conformance failed: '.get_class($e).PHP_EOL);exit(1);}
