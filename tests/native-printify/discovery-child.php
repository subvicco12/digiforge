<?php
declare(strict_types=1);
// No shared companion autoloader: prove discovery with companion missing or structurally incompatible.
class WP_Error {public function __construct(public string $code='',public string $message='',public mixed $data=null){}}
spl_autoload_register(static function(string $name):void{if(str_starts_with($name,'DigiForge\\')){$path=__DIR__.'/../../includes/'.str_replace('\\','/',substr($name,10)).'.php';if(is_file($path))require_once $path;}});
if(($argv[1]??'')==='incompatible')eval('namespace DigiForgePrintifyConnector\\Preparation; interface NativeCorePortInterface {public function unexpected():void;}');
if(($argv[1]??'')==='wrong-return')eval('namespace DigiForgePrintifyConnector\\Preparation; interface NativeCorePortInterface {public function version():int;}');
if(($argv[1]??'')==='return-reference'){
 foreach(['MutationCoreInterface','MutationInspectionCoreInterface','PublishingCoreInterface','WorkflowCoreInterface','OperationsCoreInterface']as $name)require __DIR__.'/../fixtures/native-printify-companion/'.$name.'.php';
 $code=file_get_contents(__DIR__.'/../fixtures/native-printify-companion/NativeCorePortInterface.php');$code=preg_replace('/^<\?php\s*/','',$code);eval(str_replace('function contractManifest','function &contractManifest',$code));
}
$result=DigiForge\Connector\Printify\NativeBridge::connect('1.0.0');
if(!$result instanceof WP_Error||$result->code!=='printify_native_companion_incompatible')exit(2);
if(class_exists('DigiForge\\Connector\\Printify\\NativeCorePort',false))exit(3);
echo "DENIED_WITHOUT_LOADING_NATIVE_IMPLEMENTATION\n";
