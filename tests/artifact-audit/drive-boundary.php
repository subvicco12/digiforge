<?php
class WP_Error {}
$calls=0;function wp_remote_request($url,$args){global $calls;++$calls;throw new RuntimeException('No network in audit');}
spl_autoload_register(function($c){if(str_starts_with($c,'DigiForge\\')){$p=getenv('EXACT_PLUGIN_ROOT').'/includes/'.str_replace('\\','/',substr($c,10)).'.php';if(is_file($p))require $p;}});
use DigiForge\DigitalFactory\DriveWordPressGoogleHttp as Http;
$checks=0;function check($v){global $checks;if(!$v)throw new RuntimeException('Boundary assertion failed');$checks++;}
$url='https://www.googleapis.com/drive/v3/files/synthetic_file';$args=['method'=>'GET','timeout'=>20,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>1000,'headers'=>['Authorization'=>'Bearer synthetic-not-a-credential','Accept-Encoding'=>'identity']];
check(Http::valid($url,$args));
foreach(['https://evil.test/drive/v3/files/x','http://www.googleapis.com/drive/v3/files/x','https://www.googleapis.com.evil.test/drive/v3/files/x','https://www.googleapis.com:443/drive/v3/files/x','https://www.googleapis.com/drive/v3/files/x#fragment','https://user@www.googleapis.com/drive/v3/files/x','https://www.googleapis.com/drive/v3/files/../x','https://www.googleapis.com/drive/v3/permissions'] as $bad)check(!Http::valid($bad,$args));
foreach(['redirection'=>1,'sslverify'=>false,'reject_unsafe_urls'=>false,'timeout'=>21,'limit_response_size'=>10000002,'stream'=>true,'method'=>'DELETE','cookies'=>['forbidden']] as $key=>$val){$a=$args;$a[$key]=$val;check(!Http::valid($url,$a));}
foreach(['Cookie'=>'session=x','Authorization'=>"Bearer value\r\nInjected: true"] as $key=>$val){$a=$args;$a['headers'][$key]=$val;check(!Http::valid($url,$a));}
$gate=new class implements \DigiForge\DigitalFactory\DriveGoogleGate {public function now():int{return 0;}public function owns(array $b):bool{return false;}public function allows(array $b):bool{return false;}};
try{(new Http($gate))->exchange([],$url,$args);check(false);}catch(RuntimeException $e){check($e->getMessage()==='Google dispatch blocked.');}
check($calls===0);
echo json_encode(['artifact_sha256'=>(getenv('EXACT_ARTIFACT_SHA256')?:'aba29f64dd0a874635098492c4073012d219a404c676e2b237a346c6495b43e1'),'checks'=>$checks,'failed'=>0,'transport_calls'=>$calls,'scope'=>'Offline pure transport validation and denied gate; not approved production runtime authority'])."\n";
