<?php
declare(strict_types=1);
namespace DigiForge\AI;

use DigiForge\Database\Tables;
use WP_Error;

/** Immutable provider metering and human-reviewed charge evidence, independent of Finance. */
final class ProviderEvidenceRepository
{
    public function recordOutcome(int $usageId, string $shop, array $response): array|WP_Error
    {
        return $this->locked($shop, function () use ($usageId, $shop, $response): array|WP_Error {
            $usage = $this->usage($usageId, $shop);
            if ($usage instanceof WP_Error) return $usage;
            $id = (string) ($response['response_id'] ?? '');
            if (!preg_match('/^resp_[A-Za-z0-9_-]{1,180}$/D', $id)) return $this->error('invalid', 'Exact provider response identity required.');
            $binding = ['provider'=>'openai', 'response_id'=>$id, 'usage_id'=>$usageId, 'shop_key'=>$shop];
            $bound = $this->put('response_' . hash('sha256', $id), $binding);
            if ($bound instanceof WP_Error) return $bound;
            if (in_array($response['status'] ?? '', ['queued', 'in_progress'], true)) return $binding + ['cost_state'=>'PENDING_PROVIDER_COMPLETION'];
            $model = $response['model'] ?? null;
            $tokens = $response['usage'] ?? null;
            if (!is_string($model) || $model === '' || strlen($model) > 100 || !is_array($tokens)) return $this->error('invalid', 'Completed response requires authoritative model and token metering.');
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
                if (!isset($tokens[$field]) || !is_int($tokens[$field]) || $tokens[$field] < 0 || $tokens[$field] > 1000000000) return $this->error('invalid', 'Provider token metering is invalid.');
            }
            if ($tokens['total_tokens'] !== $tokens['input_tokens'] + $tokens['output_tokens']) return $this->error('invalid', 'Provider token totals are inconsistent.');
            $meter = ['input_tokens'=>$tokens['input_tokens'],'output_tokens'=>$tokens['output_tokens'],'total_tokens'=>$tokens['total_tokens']];
            foreach (['input_tokens_details'=>'cached_tokens','output_tokens_details'=>'reasoning_tokens'] as $detail=>$field) {
                if (isset($tokens[$detail][$field])) {
                    $value=$tokens[$detail][$field];$ceiling=$tokens[$detail==='input_tokens_details'?'input_tokens':'output_tokens'];
                    if (!is_int($value) || $value < 0 || $value > $ceiling) return $this->error('invalid', 'Provider token details are invalid.');
                    $meter[$detail]=[$field=>$value];
                }
            }
            $receipt = $binding + ['workflow'=>(string)$usage['workflow'], 'task'=>(string)$usage['stage'], 'model'=>$model,
                'run_id'=>(string)$usage['run_id'], 'product_id'=>(int)$usage['product_id'], 'order_id'=>(int)$usage['order_id'],
                'usage'=>$meter, 'currency'=>(string)$usage['currency'], 'provider_response_sha256'=>hash('sha256',(string)wp_json_encode($response)), 'provenance'=>'NORMALIZED_PROVIDER_ENVELOPE', 'cost_state'=>'AWAITING_PROVIDER_CHARGE'];
            if (isset($response['provider_response_sha256'])) {
                if (!is_string($response['provider_response_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$response['provider_response_sha256'])) return $this->error('invalid','Provider response hash is invalid.');
                $receipt['provider_response_sha256']=$response['provider_response_sha256'];$receipt['provenance']='PROVIDER_HTTP_BODY';
            }
            // Preserve every transport observation; equivalent POST/GET serialization must not rewrite or conflict with metering.
            $observation=$this->put('observation_'.$usageId.'_'.$receipt['provider_response_sha256'],$receipt);
            if($observation instanceof WP_Error)return $observation;
            $existing=$this->read('completion_'.$usageId);if($existing instanceof WP_Error)return $existing;
            if($existing!==null){
                $identity=$receipt;$previous=$existing;
                unset($identity['provider_response_sha256'],$identity['provenance'],$previous['provider_response_sha256'],$previous['provenance']);
                if($identity!==$previous)return $this->error('conflict','Immutable provider metering identity conflict.',409);
                return $existing+['idempotent_replay'=>true];
            }
            return $this->put('completion_' . $usageId, $receipt);
        });
    }

    public function completeByResponse(array $response): array|WP_Error
    {
        $id=(string)($response['response_id']??'');
        $binding=$this->read('response_'.hash('sha256',$id));
        if ($binding instanceof WP_Error) return $binding;
        if ($binding===null || ($binding['response_id']??null)!==$id) return $this->error('binding_missing','Provider completion requires its original reservation binding.');
        return $this->recordOutcome((int)$binding['usage_id'],(string)$binding['shop_key'],$response);
    }

    /** No prices are derived from tokens: real provider charge provenance and a human review are mandatory. */
    public function settle(int $usageId, string $shop, array $evidence): array|WP_Error
    {
        if (!current_user_can('manage_digiforge_ai') || get_current_user_id()<1) return $this->error('reviewer_required','Authorized human AI charge reviewer required.',403);
        return $this->locked($shop,function()use($usageId,$shop,$evidence):array|WP_Error {
            global $wpdb;
            $usage=$this->usage($usageId,$shop);if($usage instanceof WP_Error)return $usage;
            $meter=$this->read('completion_'.$usageId);if($meter instanceof WP_Error)return $meter;
            if($meter===null)return $this->error('metering_missing','Provider completion evidence is required before charge settlement.');
            $amount=$evidence['amount']??null;$currency=$evidence['currency']??null;
            if(!is_string($amount)||!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,6})?$/D',$amount))return $this->error('invalid','Provider amount requires a bounded decimal string, never a derived token estimate.');
            $parts=explode('.',$amount);$amount=$parts[0].'.'.str_pad($parts[1]??'',6,'0');
            if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency)||$currency!==$usage['currency'])return $this->error('currency_conflict','Provider charge currency must match the immutable reservation; conversion is not inferred.');
            if(($evidence['source']??'')!=='provider_statement'||!preg_match('/^[a-f0-9]{64}$/D',(string)($evidence['source_sha256']??'')))return $this->error('invalid','Real provider statement provenance is required.');
            foreach(['invoice_id','line_id'] as $field)if(!is_string($evidence[$field]??null)||!preg_match('/^[A-Za-z0-9_.:-]{1,191}$/D',$evidence[$field]))return $this->error('invalid','Bounded provider invoice and line identities required.');
            if(($evidence['response_id']??null)!==$meter['response_id']||($evidence['model']??null)!==$meter['model'])return $this->error('identity_conflict','Provider charge must match the exact metered response and model.');
            $charge=['usage_id'=>$usageId,'shop_key'=>$shop,'provider'=>'openai','response_id'=>$meter['response_id'],'model'=>$meter['model'],
                'source'=>'provider_statement','source_sha256'=>$evidence['source_sha256'],'invoice_id'=>$evidence['invoice_id'],'line_id'=>$evidence['line_id'],'amount'=>$amount,'currency'=>$currency];
            $old=$this->read('settlement_'.$usageId);if($old instanceof WP_Error)return $old;
            if($old!==null){
                if(($old['charge']??null)!==$charge)return $this->error('conflict','Immutable charge evidence conflicts with an existing settlement.',409);
                if((string)$usage['actual_cost']!==$amount)return $this->error('reconciliation_required','Recorded charge and cost projection disagree; reconcile without automatically retrying.',503);
                return array_replace($old,['idempotent_replay'=>true,'cost_state'=>'REVIEWED_PROVIDER_CHARGE']);
            }
            if((float)$usage['actual_cost']!==0.0)return $this->error('reconciliation_required','Existing cost without matching provider settlement requires reconciliation.',503);
            $line=$this->put('charge_'.hash('sha256','openai|'.$charge['invoice_id'].'|'.$charge['line_id']),$charge);if($line instanceof WP_Error)return $line;
            // Preserve immutable proof BEFORE changing the cost projection. A partial write is visible and fail closed.
            $receipt=['charge'=>$charge,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>current_time('mysql',true),'cost_state'=>'PROVIDER_CHARGE_RECORDED'];
            $saved=$this->put('settlement_'.$usageId,$receipt);if($saved instanceof WP_Error)return $saved;
            $wpdb->last_error='';$ok=$wpdb->query($wpdb->prepare('UPDATE '.Tables::shop_ai_usage().' SET actual_cost=%s WHERE id=%d AND shop_key=%s AND currency=%s AND actual_cost=0',$amount,$usageId,$shop,$currency));
            if($ok===false||(string)$wpdb->last_error!=='')return $this->error('reconciliation_required','Charge proof is recorded but cost persistence is uncertain; no automatic retry.',503);
            $confirmed=$this->usage($usageId,$shop);if($confirmed instanceof WP_Error)return $confirmed;
            if((string)$confirmed['actual_cost']!==$amount)return $this->error('reconciliation_required','Recorded provider charge could not be confirmed against the cost projection.',503);
            return array_replace($saved,['cost_state'=>'REVIEWED_PROVIDER_CHARGE']);
        });
    }

    public function snapshot(int $usageId,string $shop):array|WP_Error
    {
        $usage=$this->usage($usageId,$shop);if($usage instanceof WP_Error)return $usage;
        $meter=$this->read('completion_'.$usageId);if($meter instanceof WP_Error)return $meter;
        $charge=$this->read('settlement_'.$usageId);if($charge instanceof WP_Error)return $charge;
        $state=$meter===null?'PENDING_PROVIDER_COMPLETION':'AWAITING_PROVIDER_CHARGE';
        if($charge!==null)$state=(string)$usage['actual_cost']===(string)$charge['charge']['amount']?'REVIEWED_PROVIDER_CHARGE':'RECONCILIATION_REQUIRED';
        return ['usage_id'=>$usageId,'shop_key'=>$shop,'cost_state'=>$state,'metering'=>$meter,'settlement'=>$charge,'external_execution_authorized'=>false];
    }

    private function usage(int $id,string $shop):array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_usage().' WHERE id=%d AND shop_key=%s',$id,$shop),ARRAY_A);
        if((string)$wpdb->last_error!==''||!is_array($row))return $this->error('reservation_unavailable','Exact shop reservation evidence is unavailable.',503);
        if(!str_starts_with((string)$row['idempotency_key'],'generation-')||!in_array($row['stage'],ShopAiPlan::STAGES,true))return $this->error('reservation_invalid','Only a reserved provider generation can carry provider charge evidence.');
        return $row;
    }
    private function locked(string $shop,callable $callback):array|WP_Error
    {
        global $wpdb;if($shop===''||sanitize_key($shop)!==$shop)return $this->error('invalid','Canonical explicit shop required.');
        $lock=ShopAiGovernanceRepository::generationLockName($shop);$wpdb->last_error='';$acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));
        if((string)$wpdb->last_error!==''||(string)$acquired!=='1')return $this->error('busy','Shop provider accounting is busy or unavailable.',503);
        try{$result=$callback();}catch(\Throwable $e){$result=$this->error('uncertain','Provider evidence persistence is uncertain; do not automatically retry.',503);}
        finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$uncertain=(string)$wpdb->last_error!==''||(string)$released!=='1';}
        return $uncertain?$this->error('lock_uncertain','Provider accounting lock release is uncertain.',503):$result;
    }
    private function read(string $key):array|WP_Error|null
    {
        global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','digiforge_ai_evidence_'.$key));
        if((string)$wpdb->last_error!=='')return $this->error('unavailable','Provider evidence store unavailable.',503);
        if($raw===null)return null;$decoded=json_decode((string)$raw,true);return is_array($decoded)?$decoded:$this->error('corrupt','Provider evidence is corrupt.',503);
    }
    private function put(string $key,array $data):array|WP_Error
    {
        global $wpdb;$old=$this->read($key);if($old instanceof WP_Error)return $old;
        if($old!==null)return $old===$data?$old+['idempotent_replay'=>true]:$this->error('conflict','Immutable provider evidence identity conflict.',409);
        $json=wp_json_encode($data);if(!is_string($json))return $this->error('invalid','Provider evidence encoding failed.');
        $wpdb->last_error='';$ok=$wpdb->insert($wpdb->options,['option_name'=>'digiforge_ai_evidence_'.$key,'option_value'=>$json,'autoload'=>'no']);
        $confirmed=$this->read($key);if($confirmed instanceof WP_Error)return $confirmed;
        if($confirmed!==$data)return $this->error('confirmation_unavailable','Provider evidence write could not be confirmed.',503);
        return $ok===1?$confirmed:$confirmed+['idempotent_replay'=>true];
    }
    private function error(string $code,string $message,int $status=409):WP_Error
    {
        return new WP_Error('ai_evidence_'.$code,$message,['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]);
    }
}
