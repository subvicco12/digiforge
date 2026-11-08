<?php
/** Isolated transport orchestration fixture; no live acceptance or durable-storage claim. */
namespace {
 class WP_Error {public function __construct(public string $code='',public string $message='',public array $data=[]){}public function get_error_data(){return $this->data;}}
 function is_wp_error($x){return $x instanceof WP_Error;}function wp_json_encode($x){return json_encode($x);}function wp_remote_retrieve_response_code($x){return $x['response']['code'];}function wp_remote_retrieve_header($x,$h){return '';}function wp_remote_retrieve_body($x){return $x['body'];}
}
namespace DigiForge\POD {
 final class NativeTimingState {public static int $checks=0;public static bool $revoked=false,$claimed=false;public static string $scenario='';}
 final class PrintifyMutationRequest {public static function prepare($permit,$payload){if(($payload['native']??false)&&NativeTimingState::$revoked)return new \WP_Error('dependency_revoked');return ['action'=>$permit['action'],'request_fingerprint'=>str_repeat('a',64),'existing_external_reference'=>'','integration_id'=>1,'connection_scope'=>['external_account_reference'=>'fake'],'endpoint'=>'/shops/12/orders.json','body'=>['external_id'=>'fixture'],'reconciliation_identity'=>['external_reference'=>'fixture','native_configuration_references'=>($payload['native']??false)?[['configuration_receipt'=>str_repeat('d',64)]]:[]]];}}
 final class PrintifyLiveTransportInterlock {public static function authorize($p,$r){if(++NativeTimingState::$checks===3&&str_starts_with(NativeTimingState::$scenario,'before'))NativeTimingState::$revoked=true;return true;}}
 final class PrintifyDispatchClaim {public static function claim($p,$r){if(NativeTimingState::$claimed)return new \WP_Error('consumed');NativeTimingState::$claimed=true;return true;}}
 final class PrintifyOperationClaim {public static function claim(...$args){return true;}}
 final class PrintifyAccountQuota {public static function reserve(...$args){return true;}public static function observe(...$args){return true;}}
 final class PrintifyScopedCredentialRetriever {public function consume($id,$callback,$scope){return $callback('synthetic-only');}}
 require __DIR__.'/../../includes/POD/PrintifyControlledTransport.php';
 $out=[];
 foreach(['before_revocation','after_revocation','before_package','after_package','ordinary'] as $scenario){NativeTimingState::$scenario=$scenario;NativeTimingState::$checks=0;NativeTimingState::$revoked=false;NativeTimingState::$claimed=false;$calls=0;
  $t=new PrintifyControlledTransport(static function()use(&$calls,$scenario){$calls++;if(str_starts_with($scenario,'after'))NativeTimingState::$revoked=true;return ['response'=>['code'=>200],'body'=>'{"id":"provider-order"}'];});
  $permit=['action'=>'PROVIDER_ORDER_SUBMIT','request_fingerprint'=>str_repeat('a',64),'authorization_hash'=>str_repeat('b',64),'evidence_hash'=>str_repeat('c',64)];$payload=['native'=>$scenario!=='ordinary'];$r=$t->execute($permit,$payload);$firstCalls=$calls;$again=$t->execute($permit,$payload);
  $out[$scenario]=['status'=>\is_wp_error($r)?'DENIED':$r['status'],'calls'=>$firstCalls,'calls_after_replay'=>$calls,'replay_denied'=>\is_wp_error($again),'retry_permitted'=>\is_wp_error($r)?false:($r['retry_permitted']??false),'recovery_identity'=>!\is_wp_error($r)&&isset($r['reconciliation_identity'])];
 }
 echo json_encode($out);
}
