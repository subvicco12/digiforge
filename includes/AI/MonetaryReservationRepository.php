<?php
declare(strict_types=1);
namespace DigiForge\AI;
use DigiForge\Database\Tables;
use WP_Error;

/** Inclusive provider-issued request ceilings; no inferred pricing or execution authority. */
final class MonetaryReservationRepository
{
    public static function micros(mixed $amount):int|WP_Error
    {
        if(!is_string($amount)||!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,6})?$/D',$amount))return self::error('amount_invalid','A bounded decimal string with at most six places is required.');
        $parts=explode('.',$amount);return (int)$parts[0]*1000000+(int)str_pad($parts[1]??'',6,'0');
    }
    public static function decimal(int $micros):string{return intdiv($micros,1000000).'.'.str_pad((string)($micros%1000000),6,'0',STR_PAD_LEFT);}

    public function reviewQuote(string $shop,string $document,string $decision):array|WP_Error
    {
        if(!current_user_can('manage_digiforge_ai')||get_current_user_id()<1)return self::error('reviewer_required','Authorized human AI reviewer required.',403);
        if($decision!=='CONFIRM_PROVIDER_QUOTE'||!self::shop($shop)||strlen($document)>32768)return self::error('review_required','Explicit shop and provider quote review required.');
        $q=json_decode($document,true);if(!is_array($q))return self::error('quote_invalid','Provider quote must be a JSON object.');
        if(($q['provider']??'')!=='openai'||($q['inclusive_all_charges']??null)!==true)return self::error('quote_invalid','Quote must cover every provider charge for this exact request.');
        foreach(['quote_id','model']as $field)if(!is_string($q[$field]??null)||!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$q[$field]))return self::error('quote_invalid','Bounded exact quote and model identities required.');
        if(!is_string($q['request_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$q['request_sha256'])||!is_string($q['currency']??null)||!preg_match('/^[A-Z]{3}$/D',$q['currency']))return self::error('quote_invalid','Request fingerprint and original currency required.');
        $amount=self::micros($q['maximum_charge']??null);if($amount instanceof WP_Error||$amount<1)return self::error('quote_invalid','Positive inclusive maximum charge required.');
        $times=[];foreach(['valid_from','valid_until']as $field){$v=$q[$field]??null;$date=is_string($v)?\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new \DateTimeZone('UTC')):false;if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$v)return self::error('quote_invalid','Exact UTC validity boundaries required.');$times[$field]=$date->getTimestamp();}
        if($times['valid_from']>time()||$times['valid_until']<=time()||$times['valid_until']<=$times['valid_from'])return self::error('quote_expired','Quote is outside its validity interval.');
        $proof=['shop_key'=>$shop,'provider'=>'openai','quote_id'=>$q['quote_id'],'request_sha256'=>$q['request_sha256'],'model'=>$q['model'],'currency'=>$q['currency'],'maximum_charge'=>self::decimal($amount),'inclusive_all_charges'=>true]+$times+['source_sha256'=>hash('sha256',$document),'source_document'=>$document,'authority'=>'HUMAN_REVIEWED_PROVIDER_QUOTE','external_execution_authorized'=>false];
        $key='quote_'.hash('sha256','openai|'.$q['quote_id']);$old=$this->read($key);if($old instanceof WP_Error)return $old;
        if($old!==null){$identity=$old;unset($identity['reviewed_by'],$identity['reviewed_at']);return $identity===$proof?$old+['idempotent_replay'=>true]:self::error('quote_conflict','Immutable provider quote conflicts.',409);}
        return $this->put($key,$proof+['reviewed_by'=>get_current_user_id(),'reviewed_at'=>current_time('mysql',true)]);
    }

    /** Rates are reviewed evidence for a ceiling, never evidence of an actual charge. */
    public function reviewTariff(string $shop,string $document,string $decision):array|WP_Error
    {
        if(!current_user_can('manage_digiforge_ai')||get_current_user_id()<1)return self::error('reviewer_required','Authorized human AI reviewer required.',403);
        if($decision!=='CONFIRM_PROVIDER_TARIFF'||!self::shop($shop)||strlen($document)>32768)return self::error('review_required','Explicit shop and inclusive provider tariff review required.');
        $t=json_decode($document,true);if(!is_array($t)||($t['provider']??'')!=='openai'||($t['inclusive_all_charges']??null)!==true)return self::error('tariff_invalid','All token, tool and supplementary charges require an inclusive reviewed provider bound.');
        foreach(['tariff_id','model']as $field)if(!is_string($t[$field]??null)||!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$t[$field]))return self::error('tariff_invalid','Bounded provider tariff and model identities required.');
        if(!is_string($t['currency']??null)||!preg_match('/^[A-Z]{3}$/D',$t['currency'])||!is_int($t['maximum_billable_input_tokens']??null)||$t['maximum_billable_input_tokens']<1||$t['maximum_billable_input_tokens']>10000000)return self::error('tariff_invalid','Original currency and a finite provider-issued inclusive billable input limit required.');
        foreach(['input_per_million','output_per_million','search_per_call_maximum']as $field){$rate=self::micros($t[$field]??null);if($rate instanceof WP_Error||$rate>1000000000)return self::error('tariff_invalid','Bounded exact decimal token and tool rates required.');$t[$field]=self::decimal($rate);}
        $times=[];foreach(['valid_from','valid_until']as $field){$v=$t[$field]??null;$date=is_string($v)?\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$v,new \DateTimeZone('UTC')):false;if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$v)return self::error('tariff_invalid','Exact UTC tariff validity boundaries required.');$times[$field]=$date->getTimestamp();}
        if($times['valid_from']>time()||$times['valid_until']<=time()||$times['valid_until']<=$times['valid_from'])return self::error('tariff_expired','Tariff is outside its validity interval.');
        $proof=['shop_key'=>$shop,'provider'=>'openai','tariff_id'=>$t['tariff_id'],'model'=>$t['model'],'currency'=>$t['currency'],'input_per_million'=>$t['input_per_million'],'output_per_million'=>$t['output_per_million'],'search_per_call_maximum'=>$t['search_per_call_maximum'],'maximum_billable_input_tokens'=>$t['maximum_billable_input_tokens'],'inclusive_all_charges'=>true]+$times+['source_sha256'=>hash('sha256',$document),'source_document'=>$document,'authority'=>'HUMAN_REVIEWED_PROVIDER_TARIFF','external_execution_authorized'=>false];
        $key='tariff_'.hash('sha256','openai|'.$t['tariff_id']);$old=$this->read($key);if($old instanceof WP_Error)return $old;
        if($old!==null){$identity=$old;unset($identity['reviewed_by'],$identity['reviewed_at']);return $identity===$proof?$old+['idempotent_replay'=>true]:self::error('tariff_conflict','Immutable provider tariff conflicts.',409);}
        return $this->put($key,$proof+['reviewed_by'=>get_current_user_id(),'reviewed_at'=>current_time('mysql',true)]);
    }
    private function deriveTariffQuote(string $shop,array $contract):array|WP_Error
    {
        $output=$contract['output_token_limit']??null;$tools=$contract['web_search_call_limit']??null;
        if(($contract['provider']??'')!=='openai'||!is_int($output)||$output<1000||$output>16000||!is_int($tools)||$tools<0||$tools>1||!is_string($contract['request_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$contract['request_sha256']))return self::error('quote_required','A finite server request contract and reviewed inclusive tariff or exact-request quote are required.');
        global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_col($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like('digiforge_ai_money_tariff_').'%'));if($wpdb->last_error!==''||!is_array($raw))return self::error('unavailable','Provider tariff evidence unavailable.',503);$matches=[];
        foreach($raw as $json){$t=json_decode((string)$json,true);if(!is_array($t))return self::error('corrupt','Provider tariff evidence corrupt.',503);if($t['shop_key']===$shop&&$t['model']===($contract['model']??null)&&$t['valid_from']<=time()&&$t['valid_until']>time())$matches[]=$t;}
        if(count($matches)!==1)return self::error('quote_required','Exactly one current inclusive reviewed tariff or request quote is required.');$t=$matches[0];
        $inputRate=self::micros($t['input_per_million']);$outputRate=self::micros($t['output_per_million']);$toolRate=self::micros($t['search_per_call_maximum']);if($inputRate instanceof WP_Error||$outputRate instanceof WP_Error||$toolRate instanceof WP_Error)return self::error('corrupt','Tariff rates corrupt.',503);
        // Integer arithmetic rounds each token component upward; no prompt token estimate or currency conversion.
        $amount=intdiv($t['maximum_billable_input_tokens']*$inputRate+999999,1000000)+intdiv($output*$outputRate+999999,1000000)+$tools*$toolRate;
        if($amount<1)return self::error('quote_required','A positive inclusive reservation bound is required.');
        $id='tariff-'.hash('sha256',$t['source_sha256'].'|'.$contract['request_sha256']);
        $proof=['shop_key'=>$shop,'provider'=>'openai','quote_id'=>$id,'request_sha256'=>$contract['request_sha256'],'model'=>$t['model'],'currency'=>$t['currency'],'maximum_charge'=>self::decimal($amount),'inclusive_all_charges'=>true,'valid_from'=>$t['valid_from'],'valid_until'=>$t['valid_until'],'source_sha256'=>$t['source_sha256'],'tariff_id'=>$t['tariff_id'],'maximum_billable_input_tokens'=>$t['maximum_billable_input_tokens'],'output_token_limit'=>$output,'web_search_call_limit'=>$tools,'authority'=>'DERIVED_RESERVATION_CEILING_FROM_REVIEWED_PROVIDER_TARIFF','external_execution_authorized'=>false,'reviewed_by'=>$t['reviewed_by'],'reviewed_at'=>$t['reviewed_at']];
        $saved=$this->put('quote_'.hash('sha256','openai|'.$id),$proof);return $saved instanceof WP_Error?$saved:$contract+['quote_id'=>$id];
    }

    public function findQuote(string $shop,array $contract):array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_col($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like('digiforge_ai_money_quote_').'%'));
        if($wpdb->last_error!==''||!is_array($raw))return self::error('unavailable','Provider quote evidence unavailable.',503);$matches=[];
        foreach($raw as $json){$q=json_decode((string)$json,true);if(!is_array($q))return self::error('corrupt','Provider quote evidence corrupt.',503);if($q['shop_key']===$shop&&$q['request_sha256']===($contract['request_sha256']??null)&&$q['model']===($contract['model']??null)&&$q['valid_from']<=time()&&$q['valid_until']>time())$matches[]=$q;}
        if(count($matches)===0)return $this->deriveTariffQuote($shop,$contract);if(count($matches)!==1)return self::error('quote_required','Exactly one current reviewed provider quote must match this request.');return $contract+['quote_id'=>$matches[0]['quote_id']];
    }

    /** Called only inside the existing shop generation lock; holds survive all partial writes. */
    public function reserveLocked(string $shop,string $stage,string $attemptKey,array $projection,?array $runContext,array $contract):array|WP_Error
    {
        if(!self::shop($shop))return self::error('scope_invalid','Canonical shop required.');
        $quote=$this->read('quote_'.hash('sha256','openai|'.(string)($contract['quote_id']??'')));if($quote instanceof WP_Error)return $quote;
        if($quote===null||$quote['shop_key']!==$shop||$quote['currency']!==$projection['currency']||$quote['request_sha256']!==($contract['request_sha256']??null)||$quote['model']!==($contract['model']??null))return self::error('quote_required','Exact shop, model, request and currency require an immutable reviewed provider quote.');
        if($quote['valid_from']>time()||$quote['valid_until']<=time())return self::error('quote_expired','Provider quote expired.');
        $runId=(string)($runContext['run_id']??'');$attribution=$this->validateAttribution($shop,$contract);if($attribution instanceof WP_Error)return $attribution;
        $identity=['shop_key'=>$shop,'stage'=>$stage,'attempt_key'=>$attemptKey,'quote_id'=>$quote['quote_id'],'quote_sha256'=>$quote['source_sha256'],'request_sha256'=>$quote['request_sha256'],'model'=>$quote['model'],'currency'=>$quote['currency'],'maximum_charge'=>$quote['maximum_charge'],'run_id'=>$runId,'run_started_at'=>(string)($runContext['started_at']??'')]+$attribution;
        foreach(['maximum_billable_input_tokens','output_token_limit','web_search_call_limit']as $field)if(isset($quote[$field]))$identity[$field]=$quote[$field];
        $key='hold_'.hash('sha256',$shop.'|'.$attemptKey);$old=$this->read($key);if($old instanceof WP_Error)return $old;
        if($old!==null){$previous=$old;unset($previous['occurred_at']);return $previous===$identity?$old+['idempotent_replay'=>true]:self::error('reservation_conflict','Reservation replay cannot change request, quote or attribution.');}
        $exposure=$this->exposure($shop,$projection['currency'],$runId);if($exposure instanceof WP_Error)return $exposure;
        $amount=self::micros($quote['maximum_charge']);
        foreach(['run','day','month']as $period){$limit=$projection['budgets'][$period]??0;$decimal=is_string($limit)?$limit:sprintf('%.6F',(float)$limit);$budget=self::micros($decimal);if($budget instanceof WP_Error)return $budget;if($budget>0&&($exposure[$period]+$amount>$budget))return self::error('budget_ceiling','Outstanding authoritative holds and recorded charges exceed the '.$period.' budget.');}
        return $this->put($key,$identity+['occurred_at'=>current_time('mysql',true)]);
    }
    public function link(array $usage,array $hold):array|WP_Error
    {
        unset($hold['idempotent_replay']);return $this->put('usage_'.(int)$usage['id'],['usage_id'=>(int)$usage['id'],'reservation'=>$hold]);
    }
    public function reservation(int $usageId):array|WP_Error|null{$value=$this->read('usage_'.$usageId);return $value instanceof WP_Error||$value===null?$value:$value['reservation'];}

    /** Unknown old outcomes are not zero; pending holds carry across UTC period rollover. */
    public function exposure(string $shop,string $currency,string $runId=''):array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s',$shop),ARRAY_A);if($wpdb->last_error!==''||!is_array($rows))return self::error('unavailable','AI cost projections unavailable.',503);
        $costs=['run'=>0,'day'=>0,'month'=>0];$linked=[];
        foreach($rows as $usage){$cost=self::micros((string)$usage['actual_cost']);if($cost instanceof WP_Error)return $cost;
            if($usage['currency']!==$currency&&($cost>0||str_starts_with((string)$usage['idempotency_key'],'generation-')))return self::error('currency_conflict','Currency changes require explicit reconciliation; conversion is forbidden.');
            if(!str_starts_with((string)$usage['idempotency_key'],'generation-')){if($cost>0)$this->add($costs,$cost,$usage,$runId,false);continue;}
            $hold=$this->reservation((int)$usage['id']);if($hold instanceof WP_Error)return $hold;
            if($hold===null)return self::error('unbounded_history','Historical provider reservations lack authoritative maximums; reconciliation required.');
            $linked[$hold['attempt_key']]=true;$ceiling=self::micros($hold['maximum_charge']);if($ceiling instanceof WP_Error)return $ceiling;
            $state=(new ProviderEvidenceRepository())->snapshot((int)$usage['id'],$shop);if($state instanceof WP_Error)return $state;
            if($state['cost_state']==='RECONCILIATION_REQUIRED'||$cost>$ceiling)return self::error('reconciliation_required','Provider charge or projection conflicts with its reservation; generation blocked.');
            $settled=$state['cost_state']==='REVIEWED_PROVIDER_CHARGE';$this->add($costs,$settled?$cost:$ceiling,$usage,$runId,!$settled);
        }
        $wpdb->last_error='';$raw=$wpdb->get_col($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s',$wpdb->esc_like('digiforge_ai_money_hold_').'%'));if($wpdb->last_error!==''||!is_array($raw))return self::error('unavailable','Reservation holds unavailable.',503);
        foreach($raw as $json){$hold=json_decode((string)$json,true);if(!is_array($hold))return self::error('corrupt','Reservation hold is corrupt.',503);if($hold['shop_key']!==$shop||isset($linked[$hold['attempt_key']]))continue;if($hold['currency']!==$currency)return self::error('currency_conflict','Pending hold currency requires reconciliation.');$amount=self::micros($hold['maximum_charge']);if($amount instanceof WP_Error)return $amount;$this->add($costs,$amount,$hold,$runId,true);}
        return $costs;
    }
    private function add(array &$costs,int $amount,array $row,string $runId,bool $pending):void
    {
        if($runId!==''&&$row['run_id']===$runId)$costs['run']=min(100000000000000,$costs['run']+$amount);
        if($pending||$row['occurred_at']>=gmdate('Y-m-d 00:00:00'))$costs['day']=min(100000000000000,$costs['day']+$amount);
        if($pending||$row['occurred_at']>=gmdate('Y-m-01 00:00:00'))$costs['month']=min(100000000000000,$costs['month']+$amount);
    }
    public function validateAttribution(string $shop,array $contract):array|WP_Error
    {
        global $wpdb;$product=(int)($contract['product_id']??0);$order=(int)($contract['order_id']??0);if($product<0||$order<0)return self::error('attribution_invalid','Nonnegative stored identities required.');
        if($order>0){$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT shop_reference,channel,environment FROM '.Tables::orders().' WHERE id=%d',$order),ARRAY_A);if($wpdb->last_error!==''||!is_array($row))return self::error('attribution_scope','Order ownership evidence unavailable.');
            if($row['shop_reference']!==$shop){$alias=['digital'=>'DigiCraftifyDigital','personalized_pod'=>'DigiCraftifyGoods'][$shop]??null;if($alias===null||$row['channel']!=='etsy'||!ctype_digit((string)$row['shop_reference']))return self::error('attribution_scope','Order must have exact stored shop ownership.');$wpdb->last_error='';$ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM ".Tables::integrations()." WHERE provider='etsy' AND status='CONFIGURED' AND environment=%s",$row['environment']));if($wpdb->last_error!==''||!is_array($ids))return self::error('attribution_scope','Verified shop identity unavailable.');$verified=false;foreach($ids as $id){$identity=\DigiForge\Listings\EtsyVerifiedShopIdentity::resolve((int)$id,$alias,(int)$row['shop_reference']);if(!($identity instanceof WP_Error)){$verified=true;break;}}if(!$verified)return self::error('attribution_scope','Exact verified business identity required.');}
}
        if($product>0){$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT rc.id FROM '.Tables::products().' p INNER JOIN '.Tables::product_families().' pf ON pf.id=p.product_family_id INNER JOIN '.Tables::research_candidates().' rc ON rc.opportunity_id=pf.opportunity_id WHERE p.id=%d',$product),ARRAY_A);if($wpdb->last_error!==''||!is_array($rows)||count($rows)!==1)return self::error('attribution_scope','Exact product research ownership required.');$owned=(new \DigiForge\Research\Repository())->assertCandidateShop((int)$rows[0]['id'],$shop);if($owned instanceof WP_Error)return $owned;
            if($order>0){$wpdb->last_error='';$count=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::order_line_items().' li INNER JOIN '.Tables::product_versions().' pv ON pv.id=li.product_version_id WHERE li.order_id=%d AND pv.product_id=%d',$order,$product));if($wpdb->last_error!==''||!is_numeric($count)||(int)$count<1)return self::error('attribution_scope','Product must belong to its attributed order.');}
        }
        return ['product_id'=>$product,'order_id'=>$order];
    }
    private function read(string $key):array|WP_Error|null
    {
        global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s','digiforge_ai_money_'.$key));if($wpdb->last_error!=='')return self::error('unavailable','Monetary evidence unavailable.',503);if($raw===null)return null;$value=json_decode((string)$raw,true);return is_array($value)?$value:self::error('corrupt','Monetary evidence corrupt.',503);
    }
    private function put(string $key,array $value):array|WP_Error
    {
        global $wpdb;$old=$this->read($key);if($old instanceof WP_Error)return $old;if($old!==null)return $old===$value?$old+['idempotent_replay'=>true]:self::error('conflict','Immutable monetary evidence conflicts.');$json=wp_json_encode($value);if(!is_string($json))return self::error('invalid','Monetary evidence encoding failed.');$wpdb->last_error='';$wpdb->insert($wpdb->options,['option_name'=>'digiforge_ai_money_'.$key,'option_value'=>$json,'autoload'=>'no']);$confirmed=$this->read($key);return $confirmed===$value?$confirmed:self::error('confirmation_unavailable','Monetary hold persistence is uncertain; execution is withheld.',503);
    }
    private static function shop(string $shop):bool{return $shop!==''&&strlen($shop)<=100&&sanitize_key($shop)===$shop;}
    private static function error(string $code,string $message,int $status=409):WP_Error{return new WP_Error('ai_money_'.$code,$message,['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
