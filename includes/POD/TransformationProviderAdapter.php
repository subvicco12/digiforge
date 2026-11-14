<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\AI\MonetaryReservationRepository;
use WP_Error;

/** Bounded request planning only. No credentials, HTTP transport, retries or spending authority. */
final class TransformationProviderAdapter
{
    public function plan(string $shop,array $request,array $configuration):array|WP_Error
    {
        if(!current_user_can('manage_digiforge_pod')||get_current_user_id()<1)return self::error('reviewer_required');
        try{
            if(!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true))throw new \InvalidArgumentException();
            TransformationEngineContract::exact($configuration,['provider','model','prompt_version','config_sha256','terms_evidence_sha256','maximum_job_charge','maximum_shop_day_charge','maximum_engine_month_charge','currency','max_retries']);
            foreach(['provider','model','prompt_version'] as $field)TransformationEngineContract::token($configuration[$field]??null);
            foreach(['config_sha256','terms_evidence_sha256'] as $field)TransformationEngineContract::hash($configuration[$field]??null);
            $limits=[];foreach(['maximum_job_charge','maximum_shop_day_charge','maximum_engine_month_charge'] as $field){$value=MonetaryReservationRepository::micros($configuration[$field]??null);if($value instanceof WP_Error||$value<1)throw new \InvalidArgumentException();$limits[$field]=$value;}
            if($limits['maximum_job_charge']>min($limits['maximum_shop_day_charge'],$limits['maximum_engine_month_charge'])||!is_string($configuration['currency']??null)||!preg_match('/^[A-Z]{3}$/D',$configuration['currency'])||!is_int($configuration['max_retries']??null)||$configuration['max_retries']<0||$configuration['max_retries']>10)throw new \InvalidArgumentException();
            if(!in_array($request['engine_id']??null,TransformationEngineContract::ENGINES,true)||($request['contract_version']??null)!==TransformationEngineContract::VERSION)throw new \InvalidArgumentException();
            return ['state'=>'CONFIGURATION_PLAN_ONLY','shop_key'=>$shop,'engine_id'=>$request['engine_id'],'provider'=>$configuration['provider'],'model'=>$configuration['model'],'request_sha256'=>hash('sha256',\DigiForge\Orders\Validator::canonicalJson($request)),'configuration_sha256'=>hash('sha256',\DigiForge\Orders\Validator::canonicalJson($configuration)),'budget_reservation_required'=>true,'authoritative_inclusive_quote_required'=>true,'existing_reservation_boundary'=>MonetaryReservationRepository::class,'monetary_reservation_performed'=>false,'network_request_permitted'=>false,'external_execution_authorized'=>false];
        }catch(\InvalidArgumentException){return self::error('approved_provider_and_budget_configuration_required');}
    }
    public function execute(string $shop,array $request,array $configuration):WP_Error {return self::error('live_execution_forbidden');}
    private static function error(string $code):WP_Error{return new WP_Error('transformation_'.$code,'Reviewed provider/model/terms and owner ceilings cannot create live execution authority.',['status'=>409,'external_execution_authorized'=>false,'monetary_reservation_performed'=>false]);}
}
