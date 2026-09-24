<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/**
 * GET-only network boundary for Etsy reconciliation.
 * It cannot mutate, retry an uncertain mutation, publish, or interpret absence as failure.
 */
final class EtsyReconciliationLookupExecutor
{
    /** @var callable */
    private $sender;
    public function __construct(?callable $sender=null){$this->sender=$sender??static fn(string $u,array $a):mixed=>wp_remote_request($u,$a);}

    /** @return array<string,mixed>|WP_Error */
    public function execute(array $plan,array $scope):array|WP_Error
    {
        if (($plan['state']??'')!=='ETSY_RECONCILIATION_LOOKUP_PLANNED'
            || ($plan['method']??'')!=='GET'
            || ($plan['mutation_permitted']??null)!==false
            || ($plan['external_retry_permitted']??null)!==false
            || ($plan['automatic_retry_permitted']??null)!==false
            || ($plan['network_request_permitted']??null)!==false) return self::error('plan','A validated GET-only Etsy reconciliation lookup is required.');

        $operationId=(int)($plan['operation_id']??0);$integrationId=(int)($plan['integration_id']??0);
        if($operationId<1||$integrationId<1||(int)($scope['operation_id']??0)!==$operationId||(int)($scope['integration_id']??0)!==$integrationId) return self::error('identity','Lookup and credential identities must match.');
        if (($scope['state']??'')!=='ETSY_SCOPED_CREDENTIAL_ACCESS_AUTHORIZED'
            || ($scope['single_use']??null)!==true
            || ($scope['credential_retrieval_performed']??null)!==false) return self::error('scope','Unused scoped Etsy credential authorization is required.');

        if (\DigiForge\Core\Settings::safety_locked()
            || \DigiForge\Core\Settings::get('activation_authorized',false)!==true
            || \DigiForge\Core\Settings::get('automation_armed',false)!==true
            || \DigiForge\Core\Settings::get('stop_all',true)!==false
            || \DigiForge\Core\Settings::is_enabled('etsy_draft')!==true) return self::error('locked','Etsy reconciliation network lookup remains locked by production safety controls.');

        $credential=(new EtsyScopedCredentialRetriever())->retrieve($scope);
        if($credential instanceof WP_Error)return $credential;
        $endpoint=(string)($plan['endpoint']??'');
        if(!preg_match('#^/application/listings/[1-9][0-9]{0,18}$#',$endpoint))return self::error('endpoint','Only the verified Etsy listing GET endpoint is permitted.');
        $sender=$this->sender;
        $attempt=$credential->consume($integrationId,$operationId,static function(string $token)use($sender,$endpoint,$operationId):array{
            $attemptId=wp_generate_uuid4();$at=gmdate('c');
            $response=$sender('https://openapi.etsy.com/v3'.$endpoint,['method'=>'GET','headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],'timeout'=>15,'redirection'=>0,'sslverify'=>true]);
            $token='';
            return ['operation_id'=>$operationId,'attempt_id'=>$attemptId,'attempted_at'=>$at,'adapter_invoked'=>true,'external_request_attempted'=>true,'response'=>$response];
        });
        if($attempt instanceof WP_Error)return $attempt;
        $response=$attempt['response']??null;unset($attempt['response']);
        $result=EtsyReconciliationLookupResponse::normalize($plan,$response);
        if($result instanceof WP_Error)return $result;
        return ['state'=>'ETSY_RECONCILIATION_LOOKUP_COMPLETED','operation_id'=>$operationId,'attempt'=>$attempt,'result'=>$result,'mutation_performed'=>false,'external_retry_performed'=>false,'automatic_retry_performed'=>false,'network_request_attempted'=>true,'external_execution_performed'=>true];
    }
    private static function error(string $c,string $m):WP_Error{return new WP_Error('digiforge_etsy_reconciliation_executor_'.$c,$m,['status'=>409]);}
}
