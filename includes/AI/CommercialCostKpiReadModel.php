<?php
declare(strict_types=1);
namespace DigiForge\AI;
use DigiForge\Database\Tables;
use WP_Error;

/** Reviewed-charge cohort KPIs. No global denominators, currency conversion or finance writes. */
final class CommercialCostKpiReadModel
{
    private const METRICS=['opportunity','qualified_opportunity','developed_product','approved_listing','sale'];
    public function snapshot(string $shop,string $start,string $end,string $currency):array
    {
        $result=['shop_key'=>$shop,'currency'=>$currency,'period'=>['start_utc'=>$start,'end_exclusive_utc'=>$end],'cohort_basis'=>'Usage occurred in period; attributable outcomes created (listing approved, sale verified) in the same period, with current reviewed lifecycle state. Distinct verified paid orders define sales.','query_state'=>'UNAVAILABLE','metrics'=>[],'provenance'=>[],'external_execution_authorized'=>false];
        if(!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true))return $this->unavailable($result,'SELECT_EXACT_SHOP');
        if(!$this->date($start)||!$this->date($end)||$start>=$end||!preg_match('/^[A-Z]{3}$/D',$currency))return $this->unavailable($result,'INVALID_PERIOD_OR_CURRENCY');
        global $wpdb;$lock=ShopAiGovernanceRepository::generationLockName($shop);$wpdb->last_error='';$held=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));if($wpdb->last_error!==''||(string)$held!=='1')return $this->unavailable($result,'ACCOUNTING_BUSY');
        try{$result=$this->project($result,$shop,$start,$end,$currency);}catch(\Throwable){$result=$this->unavailable($result,'UNAVAILABLE');}finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if($wpdb->last_error!==''||(string)$released!=='1')$result=$this->unavailable($result,'UNAVAILABLE');}return $result;
    }
    private function project(array $result,string $shop,string $start,string $end,string $currency):array
    {
        global $wpdb;$rows=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::shop_ai_usage().' WHERE shop_key=%s AND occurred_at>=%s AND occurred_at<%s ORDER BY id LIMIT 2001',$shop,$start,$end));
        if(count($rows)>2000)return $this->unavailable($result,'COHORT_EXCEEDS_BOUND');
        $metrics=[];foreach(self::METRICS as $name)$metrics[$name]=['micros'=>0,'ids'=>[],'state'=>null];
        foreach($rows as $usage){
            if($usage['workflow']==='production_template'&&$usage['stage']==='template_prepare'&&preg_match('/^template-[a-f0-9]{64}$/D',(string)$usage['idempotency_key'])&&preg_match('/^[a-f0-9]{64}$/D',(string)$usage['model_key'])&&(int)$usage['product_id']===0&&(int)$usage['order_id']===0&&(int)$usage['quantity']===1&&MonetaryReservationRepository::micros((string)$usage['estimated_cost'])===0&&MonetaryReservationRepository::micros((string)$usage['actual_cost'])===0){continue;}
            if(!str_starts_with((string)$usage['idempotency_key'],'generation-')){foreach($metrics as &$metric)$metric['state']='UNATTRIBUTABLE_USAGE';unset($metric);continue;}
            if($usage['currency']!==$currency){foreach($metrics as &$metric)$metric['state']='CURRENCY_CONFLICT';unset($metric);continue;}
            $proof=(new ProviderEvidenceRepository())->snapshot((int)$usage['id'],$shop);
            if($proof instanceof WP_Error)throw new \RuntimeException('Provider accounting evidence unavailable.');
            $charge=$proof['settlement']['charge']??null;$document=$proof['settlement']['source_document']??null;
            if($proof['cost_state']!=='REVIEWED_PROVIDER_CHARGE'||!is_array($charge)||!is_string($document)||!hash_equals((string)($charge['source_sha256']??''),hash('sha256',$document))){foreach($metrics as &$metric)if($metric['state']===null)$metric['state']='PENDING_COST_EVIDENCE';unset($metric);continue;}
            $amount=MonetaryReservationRepository::micros($charge['amount']);if($amount instanceof WP_Error||!$this->statementAgrees($document,$charge,$proof)){foreach($metrics as &$metric)$metric['state']='INVALID_COST_PROVENANCE';unset($metric);continue;}
            $attribution=$proof['attribution']??null;$target=['product_id'=>(int)$usage['product_id'],'order_id'=>(int)$usage['order_id']];
            if(is_array($attribution)){foreach($target as $field=>$value){if($value>0&&$value!==(int)$attribution[$field])throw new \RuntimeException('Attribution conflicts.');$target[$field]=(int)$attribution[$field];}if(isset($attribution['opportunity_id']))$target['opportunity_id']=(int)$attribution['opportunity_id'];}
            $owned=(new MonetaryReservationRepository())->validateAttribution($shop,$target);
            if($owned instanceof WP_Error){if($wpdb->last_error!=='')throw new \RuntimeException('Ownership query failed.');foreach($metrics as &$metric)if($metric['state']===null)$metric['state']='MISSING_LINEAGE';unset($metric);continue;}
            $entities=$this->lineage($shop,$target,$start,$end);
            foreach($entities as $name=>$entity){if(!is_array($entity)){if($metrics[$name]['state']===null)$metrics[$name]['state']=$entity;continue;}$metrics[$name]['micros']+=$amount;$metrics[$name]['ids'][$entity['id']]=true;}
            $result['provenance'][]=['usage_id'=>(int)$usage['id'],'occurred_at_utc'=>$usage['occurred_at'],'reviewed_by'=>(int)$proof['settlement']['reviewed_by'],'source_sha256'=>$charge['source_sha256'],'invoice_id'=>$charge['invoice_id'],'line_id'=>$charge['line_id'],'provider_response_id'=>$charge['response_id'],'amount'=>$charge['amount'],'currency'=>$currency,'attribution'=>$target,'entities'=>$entities];
        }
        foreach($metrics as $name=>$metric){$ids=array_keys($metric['ids']);sort($ids);$state=$metric['state']??($ids===[]?'NO_ATTRIBUTABLE_OUTCOMES':'AVAILABLE');$available=$state==='AVAILABLE';$count=count($ids);$result['metrics'][$name]=['state'=>$state,'cost'=>$available?MonetaryReservationRepository::decimal($metric['micros']):null,'denominator'=>$available?$count:($state==='NO_ATTRIBUTABLE_OUTCOMES'?0:null),'cost_per_outcome'=>$available?MonetaryReservationRepository::decimal(intdiv($metric['micros']+intdiv($count,2),$count)):null,'entity_ids'=>$ids];}
        foreach(['revenue_ratio','gross_profit_ratio','contribution_profit_ratio']as $name)$result['metrics'][$name]=['state'=>'FINANCE_DEFERRED','cost_per_outcome'=>null,'denominator'=>null,'cost'=>null,'entity_ids'=>[]];$result['query_state']='AVAILABLE';return $result;
    }
    private function lineage(string $shop,array $target,string $start,string $end):array
    {
        global $wpdb;$entities=array_fill_keys(self::METRICS,'MISSING_LINEAGE');$product=$target['product_id'];$order=null;
        if($target['order_id']>0){$orders=$this->rows($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE id=%d',$target['order_id']));if(count($orders)!==1)return $entities;$order=$orders[0];
            $lines=$this->rows($wpdb->prepare("SELECT DISTINCT pv.product_id FROM ".Tables::order_line_items()." li INNER JOIN ".Tables::product_versions()." pv ON pv.id=li.product_version_id WHERE li.order_id=%d AND li.validation_status='VALIDATED' AND li.environment=%s",$target['order_id'],$order['environment']));
            if($product===0&&count($lines)===1)$product=(int)$lines[0]['product_id'];
            if(!in_array($product,array_map(static fn(array $row):int=>(int)$row['product_id'],$lines),true))return $entities;
        }
        if($product<1){
            if(($target['opportunity_id']??0)<1)return $entities;$opportunities=$this->rows($wpdb->prepare('SELECT o.id,o.state,o.created_at,rc.id candidate_id,rc.review_status FROM '.Tables::opportunities().' o INNER JOIN '.Tables::research_candidates().' rc ON rc.opportunity_id=o.id WHERE o.id=%d',$target['opportunity_id']));if(count($opportunities)!==1)return $entities;$o=$opportunities[0];$owned=(new \DigiForge\Research\Repository())->assertCandidateShop((int)$o['candidate_id'],$shop);if($owned instanceof WP_Error){if($wpdb->last_error!=='')throw new \RuntimeException('Ownership query failed.');return $entities;}if(!$this->inside($o['created_at'],$start,$end)){$entities['opportunity']=$entities['qualified_opportunity']='OUTSIDE_PERIOD';return $entities;}$entities['opportunity']=['id'=>(int)$o['id'],'candidate_id'=>(int)$o['candidate_id']];if($o['state']==='QUALIFIED'&&$o['review_status']==='APPROVED'&&$this->reviewed((int)$o['candidate_id'],$start,$end))$entities['qualified_opportunity']=['id'=>(int)$o['id'],'candidate_id'=>(int)$o['candidate_id']];return $entities;
        }
        $owned=(new MonetaryReservationRepository())->validateAttribution($shop,['product_id'=>$product,'order_id'=>$target['order_id']]);if($owned instanceof WP_Error){if($wpdb->last_error!=='')throw new \RuntimeException('Ownership query failed.');return $entities;}
        $products=$this->rows($wpdb->prepare('SELECT p.id product_id,p.state product_state,p.created_at product_created,o.id opportunity_id,o.state opportunity_state,o.created_at opportunity_created,rc.id candidate_id,rc.review_status FROM '.Tables::products().' p INNER JOIN '.Tables::product_families().' pf ON pf.id=p.product_family_id INNER JOIN '.Tables::opportunities().' o ON o.id=pf.opportunity_id INNER JOIN '.Tables::research_candidates().' rc ON rc.opportunity_id=o.id WHERE p.id=%d',$product));if(count($products)!==1)return $entities;$p=$products[0];
        if(isset($target['opportunity_id'])&&$target['opportunity_id']!==(int)$p['opportunity_id'])return $entities;
        if($this->inside($p['opportunity_created'],$start,$end)){$entities['opportunity']=['id'=>(int)$p['opportunity_id'],'candidate_id'=>(int)$p['candidate_id']];if($p['opportunity_state']==='QUALIFIED'&&$p['review_status']==='APPROVED'&&$this->reviewed((int)$p['candidate_id'],$start,$end))$entities['qualified_opportunity']=['id'=>(int)$p['opportunity_id'],'candidate_id'=>(int)$p['candidate_id']];}else{$entities['opportunity']=$entities['qualified_opportunity']='OUTSIDE_PERIOD';}
        if($this->inside($p['product_created'],$start,$end)){if(in_array($p['product_state'],['READY','ACTIVE'],true))$entities['developed_product']=['id'=>$product];}else $entities['developed_product']='OUTSIDE_PERIOD';
        $listings=$this->rows($wpdb->prepare("SELECT l.id,l.shop_reference,l.environment,l.approved_at FROM ".Tables::listings()." l INNER JOIN ".Tables::product_versions()." pv ON pv.id=l.product_version_id WHERE pv.product_id=%d AND l.state='APPROVED' AND l.approved_by>0 AND l.approved_at IS NOT NULL",$product));
        $matches=[];foreach($listings as $listing){if($listing['shop_reference']!==$shop&&(!is_array($order)||$listing['shop_reference']!==$order['shop_reference']||$listing['environment']!==$order['environment']))continue;if(is_array($order)){$binding=$this->rows($wpdb->prepare("SELECT id FROM ".Tables::order_line_items()." WHERE order_id=%d AND listing_id=%d AND validation_status='VALIDATED' AND environment=%s",$target['order_id'],$listing['id'],$order['environment']));if($binding===[])continue;}$matches[]=$listing;}
        if(count($matches)===1)$entities['approved_listing']=$this->inside($matches[0]['approved_at'],$start,$end)?['id'=>(int)$matches[0]['id']]:'OUTSIDE_PERIOD';elseif(count($matches)>1)$entities['approved_listing']='AMBIGUOUS_LINEAGE';
        if(is_array($order)&&$order['channel']==='etsy'&&!in_array($order['state'],['REJECTED','SUPERSEDED'],true)&&str_starts_with((string)$order['idempotency_key'],'etsy_webhook:')){
            $events=$this->rows($wpdb->prepare("SELECT id,body_sha256,result_hash,received_at FROM ".Tables::webhook_evidence()." WHERE provider='etsy' AND event_id=%s AND event_type='ORDER.PAID' AND shop_reference=%s AND verification_status='VERIFIED' AND processing_status='PROCESSED'",substr($order['idempotency_key'],13),$order['shop_reference']));
            if(count($events)===1&&preg_match('/^[a-f0-9]{64}$/D',$events[0]['body_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$events[0]['result_hash']))$entities['sale']=$this->inside($order['created_at'],$start,$end)&&$this->inside($events[0]['received_at'],$start,$end)?['id'=>$target['order_id'],'verified_paid_event_id'=>(int)$events[0]['id']]:'OUTSIDE_PERIOD';
        }
        return $entities;
    }
    private function reviewed(int $candidate,string $start,string $end):bool
    {
        global $wpdb;$rows=$this->rows($wpdb->prepare("SELECT id FROM ".Tables::research_reviews()." WHERE candidate_id=%d AND decision='APPROVED' AND reviewed_by>0 AND reviewed_at>=%s AND reviewed_at<%s",$candidate,$start,$end));return count($rows)===1;
    }
    private function statementAgrees(string $document,array $charge,array $proof):bool
    {
        $doc=json_decode($document,true);if(!is_array($doc)||($doc['provider']??null)!=='openai'||($doc['invoice_id']??null)!==$charge['invoice_id']||($proof['settlement']['reviewed_by']??0)<1||($proof['metering']['response_id']??null)!==$charge['response_id']||($proof['metering']['model']??null)!==$charge['model']||!is_array($doc['lines']??null))return false;
        $matches=[];$ids=[];foreach($doc['lines']as $line){if(!is_array($line)||!is_string($line['line_id']??null)||isset($ids[$line['line_id']]))return false;$ids[$line['line_id']]=true;if(($line['response_id']??null)===$charge['response_id'])$matches[]=$line;}if(count($matches)!==1)return false;foreach(['line_id','response_id','model','currency']as $field)if(($matches[0][$field]??null)!==$charge[$field])return false;return MonetaryReservationRepository::micros((string)($matches[0]['amount']??''))===MonetaryReservationRepository::micros($charge['amount']);
    }
    private function rows(string $sql):array{global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);if($wpdb->last_error!==''||!is_array($rows))throw new \RuntimeException('Authoritative query failed.');return $rows;}
    private function date(string $value):bool{$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));return $d!==false&&$d->format('Y-m-d H:i:s')===$value;}
    private function inside(string $value,string $start,string $end):bool{return $this->date($value)&&$value>=$start&&$value<$end;}
    private function unavailable(array $result,string $state):array{foreach(array_merge(self::METRICS,['revenue_ratio','gross_profit_ratio','contribution_profit_ratio'])as $name)$result['metrics'][$name]=['state'=>str_ends_with($name,'ratio')?'FINANCE_DEFERRED':$state,'cost'=>null,'denominator'=>null,'cost_per_outcome'=>null,'entity_ids'=>[]];$result['query_state']='UNAVAILABLE';$result['provenance']=[];return $result;}
}
