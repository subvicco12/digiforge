<?php
declare(strict_types=1);
namespace DigiForge\POD;

use DigiForge\AI\MonetaryReservationRepository;
use DigiForge\Database\Tables;
use DigiForge\Orders\Validator;
use DigiForge\Security\{Logger,ShopAccess};
use WP_Error;

/** Executable offline response adapter. Replays synthetic bytes, never AI generation or real spend. */
final class TransformationFixtureExecution
{
    public function execute(string $shop,string $key,array $configuration,array $response):array|WP_Error
    {
        if($shop!=='personalized_pod'||get_current_user_id()<1||!current_user_can('manage_digiforge_pod')||!current_user_can('manage_digiforge_ai')||ShopAccess::authorize($shop,'manage_digiforge_pod')!==true||ShopAccess::authorize($shop,'manage_digiforge_ai')!==true)return self::error('scope_required');
        $workflow=new TransformationWorkflow();$job=$workflow->read($shop,$key);if($job instanceof WP_Error)return $job;
        if(($job['rights']['evidence_kind']??null)!=='SYNTHETIC_TEST_ONLY'||!in_array($job['state'],['INPUT_VERIFIED','GENERATED'],true))return self::error('verified_synthetic_job_required');
        $plan=(new TransformationProviderAdapter())->plan($shop,$job['request'],$configuration);if($plan instanceof WP_Error)return $plan;
        try {
            if($configuration['provider']!=='offline_fixture'||$configuration['max_retries']!==0)throw new \InvalidArgumentException();
            TransformationEngineContract::exact($response,['version','engine_id','request_sha256','source_sha256','model','currency','simulated_maximum_charge','output_sha256','png_base64','outcome']);
            if(count($response)!==10||$response['version']!=='offline-transformation-response-v1'||$response['engine_id']!==$job['request']['engine_id']||$response['model']!==$configuration['model']||$response['currency']!==$configuration['currency']||$response['request_sha256']!==$plan['request_sha256']||$response['source_sha256']!==$job['source']['sha256']||!in_array($response['outcome'],['SUCCEEDED','TIMEOUT','FAILED'],true))throw new \InvalidArgumentException();
            $charge=MonetaryReservationRepository::micros($response['simulated_maximum_charge']);$maximum=MonetaryReservationRepository::micros($configuration['maximum_job_charge']);if($charge instanceof WP_Error||$maximum instanceof WP_Error||$charge<1||$charge>$maximum)throw new \InvalidArgumentException();
            $limit=$job['request']['limits']['max_output_bytes'];if(!is_string($response['png_base64'])||strlen($response['png_base64'])>4*(int)ceil($limit/3))throw new \InvalidArgumentException();$bytes=base64_decode($response['png_base64'],true);
            if(!is_string($bytes)||strlen($bytes)>$limit||base64_encode($bytes)!==$response['png_base64']||hash('sha256',$bytes)!==$response['output_sha256']||$response['output_sha256']!==$job['rights']['result_asset_sha256'])throw new \InvalidArgumentException();
            $pixels=RasterArtworkCodec::decode($bytes);if($pixels['width_px']*$pixels['height_px']>$job['request']['limits']['max_pixels'])throw new \InvalidArgumentException();
        }catch(\Throwable){return self::error('response_contract_invalid');}
        $identity=['version'=>'offline-transformation-execution-v1','shop'=>$shop,'job_sha256'=>$job['job_hash'],'engine'=>$job['request']['engine_id'],'request_sha256'=>$plan['request_sha256'],'configuration_sha256'=>$plan['configuration_sha256'],'response_sha256'=>hash('sha256',Validator::canonicalJson($response)),'output_sha256'=>$response['output_sha256'],'currency'=>$response['currency'],'reserved_simulated_micros'=>$charge,'cost_evidence'=>'SIMULATED_NOT_PROVIDER_CHARGE'];
        global $wpdb;$lock='df-transform-fixture-shop2';$wpdb->last_error='';$got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));if($wpdb->last_error!==''||(string)$got!=='1')return self::error('busy');
        try {
            // Recheck source, access and cancellation after obtaining the accounting lock.
            if(ShopAccess::authorize($shop,'manage_digiforge_ai')!==true||ShopAccess::authorize($shop,'manage_digiforge_pod')!==true)return self::error('scope_required');
            $current=$workflow->read($shop,$key);if($current instanceof WP_Error)return $current;if($current['event_sha256']!==$job['event_sha256'])return self::error('stale_job');
            $records=$this->receipts();if($records instanceof WP_Error)return $records;$prior=null;$day=gmdate('Y-m-d');$month=gmdate('Y-m');$daily=0;$monthly=0;
            foreach($records as $r){if($r['job_sha256']===$job['job_hash']){if($prior!==null)return self::error('duplicate_receipt');$prior=$r;}if($r['currency']!==$configuration['currency'])return self::error('currency_conflict');if($r['day']===$day)$daily+=$r['reserved_simulated_micros'];if($r['month']===$month&&$r['engine']===$job['request']['engine_id'])$monthly+=$r['reserved_simulated_micros'];}
            $attribution=['evidence_kind'=>'SYNTHETIC_TEST_ONLY','provider'=>'offline_fixture','model'=>$configuration['model'],'prompt_version'=>$configuration['prompt_version'],'config_sha256'=>$plan['configuration_sha256']];
            if($prior!==null){$old=$prior;unset($old['sha256'],$old['day'],$old['month'],$old['actor']);if(Validator::canonicalJson($old)!==Validator::canonicalJson($identity)||$job['state']!=='GENERATED'||$job['result']['sha256']!==$response['output_sha256']||Validator::canonicalJson($job['attribution'])!==Validator::canonicalJson($attribution))return self::error('attempt_held');return $this->result($job,$prior);}
            // Missing one persistence root cannot reset a consumed attempt.
            $option='df_transform_fixture_'.$job['job_hash'];$wpdb->last_error='';$existing=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$option));if($wpdb->last_error!==''||$existing!==null)return self::error('receipt_uncertain');
            if($job['state']!=='INPUT_VERIFIED')return self::error('verified_synthetic_job_required');
            $dayLimit=MonetaryReservationRepository::micros($configuration['maximum_shop_day_charge']);$monthLimit=MonetaryReservationRepository::micros($configuration['maximum_engine_month_charge']);if($dayLimit instanceof WP_Error||$monthLimit instanceof WP_Error||$daily+$charge>$dayLimit||$monthly+$charge>$monthLimit)return self::error('budget_ceiling');
            $receipt=$identity+['day'=>$day,'month'=>$month,'actor'=>get_current_user_id()];$receipt['sha256']=hash('sha256',Validator::canonicalJson($receipt));$saved=$this->reserve($option,$receipt);if($saved instanceof WP_Error)return $saved;
            // Reservation is retained for timeout/failure. No retry or assumed refund.
            if($response['outcome']!=='SUCCEEDED')return self::error('provider_fixture_failed_held');
            $path=tempnam(sys_get_temp_dir(),'df-offline-transform-');if(!is_string($path))return self::error('local_output_unavailable');
            try{if(file_put_contents($path,$bytes)!==strlen($bytes))return self::error('local_output_unavailable');$generated=$workflow->importResult($shop,$key,$path,$attribution);}finally{unlink($path);}
            return $generated instanceof WP_Error?$generated:$this->result($generated,$receipt);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private function result(array $job,array $receipt):array {return ['job'=>$job,'receipt'=>$receipt,'mode'=>'OFFLINE_FIXTURE_RESPONSE','external_execution_performed'=>false,'external_execution_authorized'=>false,'production_ready'=>false];}
    private function receipts():array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_col("SELECT context FROM ".Tables::audit_log()." WHERE event_type='transformation_fixture_reserved' AND object_type='transformation_fixture' ORDER BY id LIMIT 1001");if($wpdb->last_error!==''||!is_array($rows)||count($rows)>1000)return self::error('accounting_unavailable');$wpdb->last_error='';$names=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_name LIMIT 1001',$wpdb->esc_like('df_transform_fixture_').'%'));if($wpdb->last_error!==''||!is_array($names)||count($names)!==count($rows)||count($names)>1000)return self::error('receipt_uncertain');$out=[];$seen=[];
        foreach($rows as $json){$r=json_decode((string)$json,true);if(!is_array($r)||!is_string($r['sha256']??null))return self::error('receipt_corrupt');$hash=$r['sha256'];unset($r['sha256']);if(!hash_equals($hash,hash('sha256',Validator::canonicalJson($r)))||($r['shop']??null)!=='personalized_pod'||($r['cost_evidence']??null)!=='SIMULATED_NOT_PROVIDER_CHARGE'||!is_int($r['reserved_simulated_micros']??null)||$r['reserved_simulated_micros']<1)return self::error('receipt_corrupt');$r['sha256']=$hash;if(isset($seen[$r['job_sha256']]))return self::error('duplicate_receipt');$seen[$r['job_sha256']]=true;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','df_transform_fixture_'.$r['job_sha256']));if($wpdb->last_error!==''||!is_string($raw)||$raw!==Validator::canonicalJson($r))return self::error('receipt_uncertain');$out[]=$r;}return $out;
    }
    private function reserve(string $option,array $receipt):true|WP_Error
    {
        global $wpdb;$tables=[$wpdb->options,Tables::audit_log()];$engines=$wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',...$tables));if(count($engines)!==2||array_filter($engines,static fn($e)=>strtoupper($e)!=='INNODB')||$wpdb->query('START TRANSACTION')===false)return self::error('transaction_required');
        $ok=$wpdb->insert($wpdb->options,['option_name'=>$option,'option_value'=>Validator::canonicalJson($receipt),'autoload'=>'no']);if($ok!==1||!Logger::write('transformation_fixture_reserved',$receipt,'transformation_fixture',$receipt['job_sha256'])){$wpdb->query('ROLLBACK');return self::error('reservation_uncertain');}if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return self::error('commit_unknown');}return true;
    }
    private static function error(string $reason):WP_Error {return new WP_Error('transformation_fixture_'.$reason,'Offline response, current synthetic job and bounded simulated reservation required. No provider call or automatic retry.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false,'production_ready'=>false]);}
}
