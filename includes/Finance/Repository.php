<?php

declare(strict_types=1);

namespace DigiForge\Finance;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence as DatabaseEvidence;
use DigiForge\Security\Logger;
use WP_Error;

final class Repository
{
    /** Append an internal typed declaration; never authenticates a provider or reconciles a period. */
    public function importDeclaration(array $input):array|WP_Error
    {
        try{$declaration=DeclarationContract::normalize($input);}catch(\InvalidArgumentException){return $this->error('finance_declaration_invalid','Typed normalized DECLARATION-v1 input is required.',400);}
        $references=(new DeclarationReferences())->validate($declaration);if($references instanceof WP_Error)return $references;
        $key=DeclarationContract::identityKey($declaration).$declaration['revision'];
        if($declaration['revision']>1){
            $previous=$this->declarationByKey(DeclarationContract::identityKey($declaration).($declaration['revision']-1));
            if($previous instanceof WP_Error)return $previous;
            if(!is_array($previous)||!hash_equals($previous['declaration']['declaration_hash'],$declaration['previous_revision_hash']))return $this->error('finance_declaration_revision_missing','Exact preceding transaction revision is required.',409);
            if($previous['declaration']['scope']['business_id']!==$declaration['scope']['business_id']||$previous['declaration']['scope']['shop_key']!==$declaration['scope']['shop_key'])return $this->error('finance_declaration_scope_conflict','Transaction revisions cannot change immutable business/shop ownership.',409);
        }
        if($declaration['reversal_of_hash']!==''){
            $original=$this->declarationByHash($declaration['reversal_of_hash'],$declaration['environment']);if($original instanceof WP_Error)return $original;
            if(!DeclarationMatcher::isReversal($declaration,$original['declaration']))return $this->error('finance_declaration_reversal_conflict','Declared reversal must exactly negate its scoped original; partial refunds are separate declarations.',409);
            $history=$this->declarationHistory($original['declaration']);if($history instanceof WP_Error)return $history;
            foreach($history as $revision)if($revision['declaration']['revision']>$original['declaration']['revision'])return $this->error('finance_declaration_reversal_conflict','Reversal references a superseded transaction revision.',409);
        }
        $sourceType=$declaration['scope']['order_id']>0?'order':'manual';$sourceId=$declaration['scope']['order_id'];
        $metadata=['finance_declaration'=>$declaration,'fx_snapshot_hash'=>$references['fx_snapshot_hash'],'ownership_id'=>$references['ownership_id'],'product_id'=>$references['product_id']];
        if($declaration['scope']['shop_key']==='digital'){
            $metadata['ownership_hash']=$references['ownership_hash'];
            $metadata['ownership_revision']=$references['ownership_revision'];
        }
        $entryType=DeclarationContract::CATEGORIES[$declaration['category']];
        $canonical=['environment'=>$declaration['environment'],'source_type'=>$sourceType,'source_id'=>$sourceId,'entry_type'=>$entryType,
            'currency'=>$declaration['currency'],'amount'=>Validator::amount($declaration['amount']),'base_currency'=>$declaration['base_currency'],
            'base_amount'=>Validator::amount($declaration['base_amount']),'effective_date'=>substr($declaration['occurred_at'],0,10),'metadata'=>$metadata];
        // Decimal columns receive exact strings; their legacy canonical hash also binds the exact declaration strings in metadata.
        return $this->insert(Tables::finance_ledger(),$key,['environment'=>$declaration['environment'],'source_type'=>$sourceType,'source_id'=>$sourceId,
            'entry_type'=>$entryType,'currency'=>$declaration['currency'],'amount'=>$declaration['amount'],'base_currency'=>$declaration['base_currency'],
            'base_amount'=>$declaration['base_amount'],'effective_date'=>$canonical['effective_date'],'metadata'=>Validator::canonicalJson($metadata),
            'canonical_hash'=>Validator::hash($canonical),'reconciliation_state'=>'UNRECONCILED','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'finance_declaration');
    }

    /** Validate stored normalized evidence and current references; never infer actual-source completeness. */
    public function declarationEvidence(array $row):array|WP_Error
    {
        try{
            if(!$this->ledgerIntegrityValid($row))throw new \InvalidArgumentException('Ledger hash invalid.');
            $metadata=json_decode((string)$row['metadata'],true,512,JSON_THROW_ON_ERROR);
            $declaration=DeclarationContract::fromStored($metadata['finance_declaration']??[]);
            if(ExactDecimal::money((string)$row['amount'])!==$declaration['amount']||ExactDecimal::money((string)$row['base_amount'])!==$declaration['base_amount']
                ||$row['environment']!==$declaration['environment']||$row['currency']!==$declaration['currency']||$row['base_currency']!==$declaration['base_currency']
                ||$row['entry_type']!==DeclarationContract::CATEGORIES[$declaration['category']]||$row['effective_date']!==substr($declaration['occurred_at'],0,10)
                ||$row['source_type']!==($declaration['scope']['order_id']>0?'order':'manual')||(int)$row['source_id']!==$declaration['scope']['order_id'])throw new \InvalidArgumentException('Stored declaration differs from ledger.');
            $references=(new DeclarationReferences())->validate($declaration);if($references instanceof WP_Error)return $references;
            if($declaration['scope']['shop_key']==='digital'&&(($metadata['ownership_hash']??null)!==$references['ownership_hash']
                ||($metadata['ownership_revision']??null)!==$references['ownership_revision']))throw new \InvalidArgumentException('Exact reviewed Digital ownership revision changed.');
            if(($metadata['fx_snapshot_hash']??null)!==$references['fx_snapshot_hash']||($metadata['ownership_id']??null)!==$references['ownership_id']||($metadata['product_id']??null)!==$references['product_id']
                ||($row['idempotency_key']??null)!==DeclarationContract::identityKey($declaration).$declaration['revision'])throw new \InvalidArgumentException('Stored source links changed.');
            return ['ledger_id'=>(int)$row['id'],'declaration'=>$declaration,'product_id'=>$references['product_id']];
        }catch(\InvalidArgumentException|\JsonException|\TypeError){return $this->error('finance_declaration_integrity_invalid','Stored declaration evidence or immutable source links are invalid.',409);}
    }

    public function declarationByHash(string $hash,string $environment):array|WP_Error
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$hash)!==1)return $this->error('finance_declaration_reference_invalid','Exact declaration digest required.',409);
        global $wpdb;$wpdb->last_error='';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE environment=%s AND metadata LIKE %s LIMIT 2',
            $environment,'%"declaration_hash":"'.$hash.'"%'),ARRAY_A);
        if(DatabaseEvidence::failed()||!is_array($rows))return $this->error('finance_declaration_reference_unavailable','Exact original declaration evidence could not be read.',503);
        if(count($rows)!==1)return $this->error('finance_declaration_reversal_missing','One exact original declaration is required.',409);
        $evidence=$this->declarationEvidence($rows[0]);
        if($evidence instanceof WP_Error)return $evidence;
        return hash_equals($hash,$evidence['declaration']['declaration_hash'])?$evidence:$this->error('finance_declaration_reference_invalid','Original declaration digest differs.',409);
    }

    public function declarationHistory(array $declaration):array|WP_Error
    {
        global $wpdb;$wpdb->last_error='';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE idempotency_key LIKE %s ORDER BY id ASC LIMIT 51',$wpdb->esc_like(DeclarationContract::identityKey($declaration)).'%'),ARRAY_A);
        if(DatabaseEvidence::failed()||!is_array($rows))return $this->error('finance_declaration_reference_unavailable','Transaction history could not be read.',503);
        if(count($rows)>50)return $this->error('finance_declaration_history_truncated','Transaction revision history exceeds the bounded review window.',409);
        $result=[];foreach($rows as$row){$evidence=$this->declarationEvidence($row);if($evidence instanceof WP_Error)return $evidence;$result[]=$evidence;}
        return $result;
    }

    private function declarationByKey(string $key):array|WP_Error|null
    {
        global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE idempotency_key=%s LIMIT 1',$key),ARRAY_A);
        if(DatabaseEvidence::failed())return $this->error('finance_declaration_reference_unavailable','Transaction revision evidence could not be read.',503);
        return is_array($row)?$this->declarationEvidence($row):null;
    }

    public function createLedger(array $input, ?string $key = null): array|WP_Error
    {
        try {
            $environment=Validator::environment((string)($input['environment']??''));$entryType=Validator::entryType((string)($input['entry_type']??''));$currency=Validator::currency((string)($input['currency']??''));$amount=Validator::amount($input['amount']??null);$effective=Validator::date((string)($input['effective_date']??''));$metadata=Validator::structured((array)($input['metadata']??[]));
        } catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        $sourceType=sanitize_key((string)($input['source_type']??'manual'));$sourceId=absint($input['source_id']??0);$relationship=$this->validateSource($sourceType,$sourceId,$environment);if(is_wp_error($relationship))return $relationship;
        $baseCurrency=trim((string)($input['base_currency']??''));$baseAmount=null;if($baseCurrency!==''){try{$baseCurrency=Validator::currency($baseCurrency);if($baseCurrency===$currency){$baseAmount=Validator::amount($input['base_amount']??$amount);if($baseAmount!==$amount)return $this->error('fx_amount_conflict','Same-currency base amount must equal the original amount.',409);}elseif(!array_key_exists('base_amount',$input)){return $this->error('fx_evidence_required','Foreign-currency ledger entries require an explicit converted base_amount.',409);}else{$baseAmount=Validator::amount($input['base_amount']);}}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}}
        $canonical=['environment'=>$environment,'source_type'=>$sourceType,'source_id'=>$sourceId,'entry_type'=>$entryType,'currency'=>$currency,'amount'=>$amount,'base_currency'=>$baseCurrency,'base_amount'=>$baseAmount,'effective_date'=>$effective,'metadata'=>$metadata];
        return $this->insert(Tables::finance_ledger(),$key,['environment'=>$environment,'source_type'=>$sourceType,'source_id'=>$sourceId,'entry_type'=>$entryType,'currency'=>$currency,'amount'=>$amount,'base_currency'=>$baseCurrency,'base_amount'=>$baseAmount,'effective_date'=>$effective,'metadata'=>Validator::canonicalJson($metadata),'canonical_hash'=>Validator::hash($canonical),'reconciliation_state'=>'UNRECONCILED','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'finance_ledger');
    }

    public function createFx(array $input,?string $key=null):array|WP_Error
    {
        try{
            $environment=Validator::environment((string)($input['environment']??''));$base=Validator::currency((string)($input['base_currency']??''));$quote=Validator::currency((string)($input['quote_currency']??''));
            $rate=Validator::positiveRate($input['rate']??0);$metadata=Validator::structured((array)($input['source_metadata']??[]));
            $asOf=sanitize_text_field((string)($input['as_of_at']??$this->now()));$storedRate=ExactDecimal::rate(is_string($input['rate'])?$input['rate']:number_format($rate,10,'.',''));
            if(($metadata['kind']??null)==='DECLARATION'){
                if(isset($metadata['exact_fx_snapshot']))throw new \InvalidArgumentException('Exact FX binding is derived.');
                $storedRate=ExactDecimal::rate($input['rate']??null);
                $source=DeclarationContract::source($metadata);
                $metadata['exact_fx_snapshot']=['schema'=>'digiforge-finance-fx-declaration-v1','rate'=>$storedRate,
                    'source_hash'=>Validator::hash(['environment'=>$environment,'base_currency'=>$base,'quote_currency'=>$quote,'rate'=>$storedRate,'as_of_at'=>$asOf,'source'=>$source])];
            }
        }catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        if($base===$quote)return $this->error('validation','FX currency pair must contain different currencies.');
        $canonical=['environment'=>$environment,'base'=>$base,'quote'=>$quote,'rate'=>$rate,'as_of_at'=>$asOf,'source'=>$metadata];
        return $this->insert(Tables::fx_snapshots(),$key,['environment'=>$environment,'base_currency'=>$base,'quote_currency'=>$quote,'rate'=>$storedRate,
            'as_of_at'=>$asOf,'source_metadata'=>Validator::canonicalJson($metadata),'canonical_hash'=>Validator::hash($canonical),'created_by'=>get_current_user_id(),'created_at'=>$this->now()],'fx_snapshot');
    }

    public function createTaxClassification(array $input,?string $key=null):array|WP_Error
    {
        try{$environment=Validator::environment((string)($input['environment']??''));$currency=Validator::currency((string)($input['currency']??''));$amount=Validator::amount($input['amount_basis']??null);$evidence=Validator::structured((array)($input['evidence']??[]));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}$sourceType=sanitize_key((string)($input['source_type']??'manual'));$sourceId=absint($input['source_id']??0);$relationship=$this->validateSource($sourceType,$sourceId,$environment);if(is_wp_error($relationship))return $relationship;$classification=strtoupper(sanitize_key((string)($input['classification']??'REVIEW_REQUIRED')));if(!in_array($classification,['TAXABLE','NON_TAXABLE','REVIEW_REQUIRED'],true))return $this->error('validation','Invalid tax classification.');return $this->insert(Tables::tax_classifications(),$key,['environment'=>$environment,'source_type'=>$sourceType,'source_id'=>$sourceId,'jurisdiction'=>sanitize_text_field((string)($input['jurisdiction']??'')),'tax_category'=>sanitize_key((string)($input['tax_category']??'')),'classification'=>$classification,'currency'=>$currency,'amount_basis'=>$amount,'evidence'=>Validator::canonicalJson($evidence),'review_status'=>'UNREVIEWED','reviewed_by'=>0,'reviewed_at'=>null,'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'tax_classification');
    }

    public function reviewTaxClassification(int $id,string $decision):array|WP_Error
    { return $this->atomic(Tables::tax_classifications(),fn()=>$this->reviewTaxRecord($id,$decision)); }

    private function reviewTaxRecord(int $id,string $decision):array|WP_Error
    {
        $reviewerId=get_current_user_id();if($reviewerId<1)return $this->error('reviewer_required','Authenticated human reviewer required.',403);$decision=strtoupper(sanitize_key($decision));if(!in_array($decision,['APPROVED','REJECTED'],true))return $this->error('validation','Tax review decision must be APPROVED or REJECTED.');$row=$this->find(Tables::tax_classifications(),$id);if(is_wp_error($row))return $row;if(!is_array($row))return $this->error('not_found','Tax classification not found.',404);if((string)$row['review_status']===$decision)return $row+['idempotent_review'=>true];if((string)$row['review_status']!=='UNREVIEWED')return $this->error('review_conflict','Tax classification was already reviewed.',409);$now=$this->now();global $wpdb;$wpdb->last_error='';$ok=$wpdb->update(Tables::tax_classifications(),['review_status'=>$decision,'reviewed_by'=>$reviewerId,'reviewed_at'=>$now,'updated_at'=>$now],['id'=>$id,'review_status'=>'UNREVIEWED']);if($ok===false||!empty($wpdb->last_error))return $this->error('tax_review_persistence_failed','Tax review persistence is unavailable.',503);if($ok!==1)return $this->error('review_conflict','Tax review changed concurrently.',409);if(!Logger::write('tax_classification_reviewed',['decision'=>$decision],'tax_classification',(string)$id))return $this->error('finance_audit_unavailable','Finance audit evidence could not be persisted.',503);return $this->find(Tables::tax_classifications(),$id)?:[];
    }

    public function calculatePeriod(array $input,?string $key=null):array|WP_Error
    {
        try{$environment=Validator::environment((string)($input['environment']??''));$start=Validator::date((string)($input['period_start']??''));$end=Validator::date((string)($input['period_end']??''));$baseCurrency=Validator::currency((string)($input['base_currency']??'USD'));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}if($start>$end)return $this->error('validation','Period start must not be after period end.');global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($wpdb->prepare('SELECT source_type, source_id, entry_type, amount, base_amount, base_currency, currency, reconciliation_state, environment, effective_date, metadata, canonical_hash, id FROM '.Tables::finance_ledger().' WHERE environment=%s AND effective_date BETWEEN %s AND %s ORDER BY id ASC',$environment,$start,$end),ARRAY_A);if(!is_array($rows)||!empty($wpdb->last_error))return $this->error('ledger_evidence_unavailable','Finance ledger evidence could not be read; period calculation is blocked.',500);$metrics=['gross_revenue'=>0.0,'refund_reserves'=>0.0,'platform_payment_fees'=>0.0,'shipping_cost'=>0.0,'provider_cost'=>0.0,'advertising_cost'=>0.0,'tax_collected'=>0.0,'tax_expense'=>0.0,'other_income'=>0.0,'other_expense'=>0.0,'gross_profit'=>0.0,'contribution_profit'=>0.0,'net_operating_profit'=>0.0,'margin_percent'=>0.0,'order_count'=>0,'average_order_value'=>0.0,'unresolved_reconciliation_count'=>0];$revenueOrderIds=[];$sourceEntries=[];
        foreach($rows as$row){if(!is_array($row)||!$this->ledgerIntegrityValid($row)||!is_scalar($row['id']??null)||!ctype_digit((string)$row['id'])||(int)$row['id']<1)return $this->error('ledger_integrity_invalid','Finance ledger evidence hash is invalid; period calculation is blocked.',409);if(array_key_exists('finance_declaration',json_decode((string)$row['metadata'],true)))return $this->error('declaration_report_required','Normalized declarations require the provisional active-revision report; legacy period calculation is blocked.',409);$sourceEntries[]=['id'=>(string)$row['id'],'canonical_hash'=>(string)$row['canonical_hash'],'reconciliation_state'=>(string)$row['reconciliation_state']];if((string)($row['entry_type']??'')==='REVENUE'&&(string)($row['source_type']??'')==='order'&&(int)($row['source_id']??0)>0){$revenueOrderIds[(int)$row['source_id']]=true;}$currency=(string)$row['currency'];$rowBase=(string)($row['base_currency']??'');if($currency===$baseCurrency){$value=(float)$row['amount'];}elseif($row['base_amount']!==null&&$rowBase===$baseCurrency){$value=(float)$row['base_amount'];}else{return $this->error('currency_conversion_required','Period contains ledger entries without conversion into the requested base currency.',409);}switch((string)$row['entry_type']){case'REVENUE':$metrics['gross_revenue']+=$value;break;case'REFUND_RESERVE':$metrics['refund_reserves']+=abs($value);break;case'PLATFORM_FEE':case'PAYMENT_FEE':$metrics['platform_payment_fees']+=abs($value);break;case'SHIPPING_COST':$metrics['shipping_cost']+=abs($value);break;case'COGS':$metrics['provider_cost']+=abs($value);break;case'AD_SPEND':$metrics['advertising_cost']+=abs($value);break;case'TAX_COLLECTED':$metrics['tax_collected']+=$value;break;case'TAX_EXPENSE':$metrics['tax_expense']+=abs($value);break;case'OTHER_INCOME':$metrics['other_income']+=$value;break;case'OTHER_EXPENSE':$metrics['other_expense']+=abs($value);break;case'ADJUSTMENT':$metrics['other_income']+=$value;break;}if((string)$row['reconciliation_state']!=='RECONCILED')$metrics['unresolved_reconciliation_count']++;}
        $metrics['order_count']=count($revenueOrderIds);$metrics['average_order_value']=$metrics['order_count']>0?round($metrics['gross_revenue']/$metrics['order_count'],4):0.0;$metrics['gross_profit']=round($metrics['gross_revenue']-$metrics['refund_reserves']-$metrics['provider_cost'],4);$metrics['contribution_profit']=round($metrics['gross_profit']-$metrics['platform_payment_fees']-$metrics['shipping_cost']-$metrics['advertising_cost'],4);$metrics['net_operating_profit']=round($metrics['contribution_profit']+$metrics['other_income']-$metrics['other_expense']-$metrics['tax_expense'],4);$metrics['margin_percent']=$metrics['gross_revenue']==0.0?0.0:round(($metrics['net_operating_profit']/$metrics['gross_revenue'])*100,4);$version=Validator::periodSourceVersion(['formula_version'=>'v2','environment'=>$environment,'period_start'=>$start,'period_end'=>$end,'base_currency'=>$baseCurrency,'ledger'=>$sourceEntries]);$canonical=['environment'=>$environment,'period_start'=>$start,'period_end'=>$end,'base_currency'=>$baseCurrency,'metrics'=>$metrics,'calculation_version'=>$version];return $this->insert(Tables::finance_periods(),$key,['environment'=>$environment,'period_start'=>$start,'period_end'=>$end,'base_currency'=>$baseCurrency,'metrics'=>Validator::canonicalJson($metrics),'metrics_hash'=>Validator::hash($canonical),'calculation_version'=>$version,'state'=>'CALCULATED','approved_by'=>0,'approved_at'=>null,'created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'finance_period');
    }

    public function createAnalyticsSnapshot(array $input,?string $key=null):array|WP_Error
    {
        try{$environment=Validator::environment((string)($input['environment']??''));$start=Validator::date((string)($input['period_start']??''));$end=Validator::date((string)($input['period_end']??''));$metrics=Validator::structured((array)($input['metrics']??[]));$freshness=Validator::structured((array)($input['freshness_metadata']??[]));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}
        if($start>$end)return $this->error('validation','Period start must not be after period end.');
        $dimension=strtolower(trim((string)($input['dimension_type']??'')));if(!in_array($dimension,['product','listing','order','provider','shop','portfolio'],true))return $this->error('validation','Invalid analytics dimension.');
        $dimensionId=absint($input['dimension_id']??0);if($dimension==='portfolio'){if($dimensionId!==0)return $this->error('validation','Portfolio analytics dimension_id must be zero.');}elseif($dimensionId<1){return $this->error('validation','Analytics dimension requires a positive local identity.');}else{$relationship=$this->validateAnalyticsDimension($dimension,$dimensionId,$environment);if(is_wp_error($relationship))return $relationship;}
        if($metrics===[])return $this->error('validation','Analytics metrics must not be empty.');
        $version=sanitize_key((string)($input['calculation_version']??'v1'));if($version==='')return $this->error('validation','Analytics calculation version is required.');
        $metricsJson=Validator::canonicalJson($metrics);$canonical=['environment'=>$environment,'dimension_type'=>$dimension,'dimension_id'=>$dimensionId,'period_start'=>$start,'period_end'=>$end,'metrics'=>$metrics,'freshness_metadata'=>$freshness,'calculation_version'=>$version];
        return $this->insert(Tables::analytics_snapshots(),$key,['environment'=>$environment,'dimension_type'=>$dimension,'dimension_id'=>$dimensionId,'period_start'=>$start,'period_end'=>$end,'metrics'=>$metricsJson,'metrics_hash'=>hash('sha256',$metricsJson),'freshness_metadata'=>Validator::canonicalJson($freshness),'calculation_version'=>$version,'created_by'=>get_current_user_id(),'created_at'=>$this->now()],'analytics_snapshot');
    }

    public function createIntent(array $input,?string $key=null):array|WP_Error
    {
        try{$environment=Validator::environment((string)($input['environment']??''));$payload=Validator::structured((array)($input['input_payload']??[]));}catch(\InvalidArgumentException $e){return $this->error('validation',$e->getMessage());}$type=strtoupper((string)($input['intent_type']??''));if(!in_array($type,Lifecycle::INTENT_TYPES,true))return $this->error('validation','Invalid finance intent type.');if($type==='PREPARE_TAX_REVIEW'){$sourceType=sanitize_key((string)($input['source_type']??''));$sourceId=absint($input['source_id']??0);if($sourceType!=='tax_classification'||$sourceId<1)return $this->error('tax_review_required','Tax-review intent requires an approved tax classification.',409);$tax=$this->find(Tables::tax_classifications(),$sourceId);if(is_wp_error($tax))return $tax;if(!is_array($tax)||(string)$tax['environment']!==$environment||(string)$tax['review_status']!=='APPROVED'||(int)$tax['reviewed_by']<1||empty($tax['reviewed_at']))return $this->error('tax_review_required','Tax-review intent requires an approved human-reviewed tax classification.',409);}return $this->insert(Tables::finance_intents(),$key,['environment'=>$environment,'source_type'=>sanitize_key((string)($input['source_type']??'')),'source_id'=>absint($input['source_id']??0),'intent_type'=>$type,'input_payload'=>Validator::canonicalJson($payload),'state'=>'BLOCKED','created_by'=>get_current_user_id(),'created_at'=>$this->now(),'updated_at'=>$this->now()],'finance_intent');
    }

    public function transition(string $entity,int $id,string $to):array|WP_Error
    {
        $table=match($entity){'period'=>Tables::finance_periods(),'alert'=>Tables::operational_alerts(),'intent'=>Tables::finance_intents(),default=>''};
        if($table==='')return $this->error('validation','Unknown finance lifecycle entity.');
        return $this->atomic($table,fn()=>$this->transitionRecord($entity,$id,$to));
    }

    private function transitionRecord(string $entity,int $id,string $to):array|WP_Error
    {
        $table=match($entity){'period'=>Tables::finance_periods(),'alert'=>Tables::operational_alerts(),'intent'=>Tables::finance_intents(),default=>''};
        $reviewerId=get_current_user_id();
        if($table==='')return $this->error('validation','Unknown finance lifecycle entity.');
        $row=$this->find($table,$id);
        if(is_wp_error($row))return $row;
        $to=strtoupper(sanitize_key($to));
        if(!is_array($row))return $this->error('not_found','Finance record not found.',404);
        // Approval and closure both depend on current evidence, including idempotent requests.
        if($entity==='period'&&in_array($to,['APPROVED','CLOSED'],true)){
            if($reviewerId<1)return $this->error('reviewer_required','Authenticated human reviewer required.',403);
            if($to==='CLOSED'&&((int)($row['approved_by']??0)<1||empty($row['approved_at'])))return $this->error('period_approval_required','Period closure requires an existing human approval.',409);
            return $this->validatePeriodApproval($row);
        }
        if((string)$row['state']===$to){
            if($entity==='period'&&in_array($to,['APPROVED','CLOSED'],true)){
                global $wpdb;$wpdb->last_error='';
                $current=$wpdb->get_row($wpdb->prepare('SELECT * FROM `'.$table.'` WHERE id=%d AND '.$this->emptyPeriodSources($row).' FOR UPDATE',$id),ARRAY_A);
                if(DatabaseEvidence::failed())return $this->error('source_evidence_unavailable','Current period source eligibility could not be read.',503);
                if(!is_array($current)||$current!==$row)return $this->error('transition_conflict','State or source evidence changed concurrently.',409);
            }
            return $row+['idempotent_transition'=>true];
        }
        if(!Lifecycle::can($entity,(string)$row['state'],$to))return $this->error('invalid_transition','Finance lifecycle transition is not permitted.',409);
        if(in_array($to,['APPROVED','APPROVED_INTENT'],true)&&$reviewerId<1)return $this->error('reviewer_required','Authenticated human reviewer required.',403);
        $now=$this->now();global $wpdb;$data=['state'=>$to,'updated_at'=>$now];
        $where=['id'=>$id,'state'=>(string)$row['state']];
        if($entity==='period'&&in_array($to,['APPROVED','CLOSED'],true)){
            if($to==='APPROVED'){$data['approved_by']=$reviewerId;$data['approved_at']=$now;}
            if($to==='CLOSED'){$where['approved_by']=$row['approved_by'];$where['approved_at']=$row['approved_at'];}
            $where['metrics_hash']=$row['metrics_hash'];
            $where['metrics']=$row['metrics'];
            foreach(['environment','period_start','period_end','base_currency','calculation_version'] as $field)$where[$field]=$row[$field];
        }
        $wpdb->last_error='';
        if($entity==='period'&&in_array($to,['APPROVED','CLOSED'],true)){
            // Source eligibility belongs to the same current-read statement as the state CAS.
            $set=[];$conditions=[];
            foreach($data as$field=>$value)$set[]=$wpdb->prepare('`'.$field.'`=%s',$value);
            foreach($where as$field=>$value)$conditions[]=$wpdb->prepare('`'.$field.'`=%s',$value);
            $conditions[]=$this->emptyPeriodSources($row);
            $updated=$wpdb->query('UPDATE `'.$table.'` SET '.implode(',',$set).' WHERE '.implode(' AND ',$conditions));
        }else{$updated=$wpdb->update($table,$data,$where);}
        if($updated===false||!empty($wpdb->last_error))return $this->error('transition_failed','Finance lifecycle persistence is unavailable.',503);
        if($updated!==1)return $this->error('transition_conflict','State or immutable evidence changed concurrently.',409);
        if(!Logger::write('finance_state_changed',['from'=>$row['state'],'to'=>$to],$entity,(string)$id))return $this->error('finance_audit_unavailable','Finance audit evidence could not be persisted.',503);
        return $this->find($table,$id)?:[];
    }

    private function emptyPeriodSources(array $row):string
    {
        global $wpdb;
        return $wpdb->prepare('NOT EXISTS (SELECT 1 FROM '.Tables::orders().' WHERE environment=%s AND DATE(created_at) BETWEEN %s AND %s FOR UPDATE) AND NOT EXISTS (SELECT 1 FROM '.Tables::finance_ledger().' WHERE environment=%s AND effective_date BETWEEN %s AND %s FOR UPDATE)',
            $row['environment'],$row['period_start'],$row['period_end'],$row['environment'],$row['period_start'],$row['period_end']);
    }

    /**
     * Current helpers prove local coverage only, never authenticated fees/actual charges.
     * Until an authoritative import contract exists, active periods cannot be approved.
     * Empty local observations are not provider certification or external execution authority.
     */
    private function validatePeriodApproval(array $row):WP_Error
    {
        $metrics=json_decode((string)($row['metrics']??''),true);
        $canonical=['environment'=>(string)($row['environment']??''),'period_start'=>(string)($row['period_start']??''),'period_end'=>(string)($row['period_end']??''),'base_currency'=>(string)($row['base_currency']??''),'metrics'=>$metrics,'calculation_version'=>(string)($row['calculation_version']??'')];
        try{
            if(!is_array($metrics)||!preg_match('/^[a-f0-9]{64}$/',(string)($row['metrics_hash']??''))||!hash_equals((string)$row['metrics_hash'],Validator::hash($canonical)))return $this->error('period_integrity_invalid','Finance period evidence hash is invalid.',409);
            if(($metrics['unresolved_reconciliation_count']??null)!==0)return $this->error('reconciliation_required','Finance period has unresolved or unverified reconciliation evidence.',409);
            $coverage=(new SourceCompletenessReadModel())->snapshot($canonical['environment'],$canonical['period_start'],$canonical['period_end']);
        }catch(\Throwable){return $this->error('source_evidence_unavailable','Current source completeness could not be verified.',503);}
        if(($coverage['query_state']['orders']??null)!=='AVAILABLE'||($coverage['query_state']['revenue']??null)!=='AVAILABLE')return $this->error('source_evidence_unavailable','Current source completeness could not be verified.',503);
        if(($coverage['state']??null)!=='ZERO_ACTIVITY')return $this->error('source_completeness_required','Authenticated payment timing, fees and actual provider charges remain unverified.',409);
        // ZERO_ACTIVITY in the read model concerns orders/revenue only; manual costs are not empty activity.
        global $wpdb;$wpdb->last_error='';
        $ledger=$wpdb->get_results($wpdb->prepare('SELECT id FROM '.Tables::finance_ledger().' WHERE environment=%s AND effective_date BETWEEN %s AND %s LIMIT 1',$canonical['environment'],$canonical['period_start'],$canonical['period_end']),ARRAY_A);
        if(!is_array($ledger)||DatabaseEvidence::failed())return $this->error('source_evidence_unavailable','Current ledger coverage could not be verified.',503);
        if($ledger!==[])return $this->error('source_completeness_required','Nonempty ledger requires authoritative source certification.',409);
        $required=['gross_revenue','refund_reserves','platform_payment_fees','shipping_cost','provider_cost','advertising_cost','tax_collected','tax_expense','other_income','other_expense','gross_profit','contribution_profit','net_operating_profit','margin_percent','order_count','average_order_value','unresolved_reconciliation_count'];
        foreach($required as $key)if(!array_key_exists($key,$metrics))return $this->error('period_integrity_invalid','Period metrics are incomplete.',409);
        foreach($metrics as $value)if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||(float)$value!==0.0)return $this->error('source_completeness_required','Stored period contradicts empty current source evidence.',409);
        // P07: local zero orders/ledger cannot prove zero external expenses or complete sources.
        // No approved authoritative completeness/freshness/independent-control contract exists yet.
        return $this->error('source_controls_required','Independent source completeness, freshness and review controls are required even for an empty local period.',409);
    }

    public function list(string $entity,int $page=1,int $perPage=20):array
    {
        $tables=['ledger'=>Tables::finance_ledger(),'fx'=>Tables::fx_snapshots(),'tax'=>Tables::tax_classifications(),'periods'=>Tables::finance_periods(),'analytics'=>Tables::analytics_snapshots(),'alerts'=>Tables::operational_alerts(),'intents'=>Tables::finance_intents()];$table=$tables[$entity]??'';if($table==='')return['items'=>[],'pagination'=>['total_items'=>0,'total_pages'=>0]];global $wpdb;$page=max(1,$page);$perPage=min(100,max(1,$perPage));$offset=($page-1)*$perPage;$wpdb->last_error='';$items=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",$perPage,$offset),ARRAY_A);if(!is_array($items)||!empty($wpdb->last_error))return['items'=>null,'pagination'=>null,'query_state'=>'UNAVAILABLE'];$wpdb->last_error='';$total=$wpdb->get_var("SELECT COUNT(*) FROM $table");if(!is_numeric($total)||!empty($wpdb->last_error))return['items'=>null,'pagination'=>null,'query_state'=>'UNAVAILABLE'];$total=(int)$total;if(in_array($entity,['periods','analytics'],true))foreach($items as &$item){$item['financial_state']='UNKNOWN';$item['certified_profitability']=false;}unset($item);return['items'=>$items,'pagination'=>['total_items'=>$total,'total_pages'=>(int)ceil($total/max(1,$perPage))],'query_state'=>'AVAILABLE'];
    }

    private function validateAnalyticsDimension(string $type,int $id,string $environment):true|WP_Error
    {
        $table=match($type){'product'=>Tables::products(),'listing'=>Tables::listings(),'order'=>Tables::orders(),'provider'=>Tables::pod_mappings(),'shop'=>Tables::stores(),default=>''};
        if($table==='')return $this->error('invalid_analytics_dimension','Unsupported analytics dimension.',409);
        $row=$this->find($table,$id);if(is_wp_error($row))return $row;if(!is_array($row))return $this->error('invalid_analytics_dimension','Analytics dimension identity was not found.',409);
        if(isset($row['environment'])&&(string)$row['environment']!==$environment)return $this->error('environment_mismatch','Analytics dimension environment must match.',409);
        return true;
    }

    private function validateSource(string $type,int $id,string $environment):true|WP_Error
    {
        if($type==='manual'&&$id===0)return true;$table=match($type){'order'=>Tables::orders(),'listing'=>Tables::listings(),'pod_cost_snapshot'=>Tables::pod_cost_snapshots(),default=>''};if($table===''||$id<1)return $this->error('invalid_source','Unsupported or missing finance source.');$row=$this->find($table,$id);if(is_wp_error($row))return $row;if(!is_array($row))return $this->error('invalid_source','Finance source was not found.',409);if(isset($row['environment'])&&(string)$row['environment']!==$environment)return $this->error('environment_mismatch','Finance source environment must match.',409);return true;
    }

    private function insert(string $table,?string $key,array $data,string $objectType):array|WP_Error
    { return $this->atomic($table,fn()=>$this->insertRecord($table,$key,$data,$objectType)); }

    private function insertRecord(string $table,?string $key,array $data,string $objectType):array|WP_Error
    {
        global $wpdb;
        $periodRevision=$objectType==='finance_period';$declarationRecord=$objectType==='finance_declaration';
        $key=$key===null?null:sanitize_text_field($key);
        if($key==='')$key=null;
        if($key!==null&&strlen($key)>191)return $this->error('validation','Idempotency key is too long.');
        if($key!==null){
            $wpdb->last_error='';
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1",$key),ARRAY_A);
            if(!empty($wpdb->last_error))return $this->error('idempotency_evidence_unavailable','Idempotency evidence could not be read.',500);
            if(is_array($existing)){
                if(!$this->replayCompatible($existing,$data))return $this->error('idempotency_payload_conflict','Idempotency key was already used with different immutable finance data.',409);
                return $this->confirmedReplay($existing,$data,$declarationRecord,$objectType);
            }
            $data['idempotency_key']=$key;
        }
        if($periodRevision){
            $existing=$this->findPeriodRevision($data);
            if(is_wp_error($existing))return $existing;
            if(is_array($existing))return $this->replayPeriodRevision($existing,$data);
        }
        $wpdb->last_error='';
        if($wpdb->insert($table,$data)!==1||!empty($wpdb->last_error)){
            if($key!==null){
                $wpdb->last_error='';
                $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s LIMIT 1",$key),ARRAY_A);
                if($periodRevision&&!empty($wpdb->last_error))return $this->error('idempotency_evidence_unavailable','Idempotency evidence could not be read.',500);
                if(empty($wpdb->last_error)&&is_array($existing)){
                    if($this->replayCompatible($existing,$data))return $this->confirmedReplay($existing,$data,$declarationRecord,$objectType);
                    if($periodRevision)return $this->error('idempotency_payload_conflict','Idempotency key was already used with different immutable finance data.',409);
                }
            }
            if($periodRevision){
                $existing=$this->findPeriodRevision($data);
                if(is_wp_error($existing))return $existing;
                if(is_array($existing))return $this->replayPeriodRevision($existing,$data);
            }
            return $this->error('database_error','Unable to persist finance record.',500);
        }
        $id=(int)$wpdb->insert_id;
        if(!Logger::write($objectType.'_created',['idempotency_key'=>$key===null?'':'[PRESENT]'],$objectType,(string)$id))return $this->error('finance_audit_unavailable','Finance audit evidence could not be persisted.',503);
        $stored=$this->find($table,$id);
        if($periodRevision){
            if(is_wp_error($stored))return $stored;
            if(!is_array($stored))return $this->error('period_revision_evidence_unavailable','Persisted period revision could not be read.',503);
            if(!$this->replayCompatible($stored,$data))return $this->error('period_revision_conflict','Persisted period revision differs from calculated evidence.',409);
        }
        if($declarationRecord)return $this->confirmDeclarationRecord($stored,$data,false);
        if(!is_array($stored))return $this->error('finance_record_unavailable','Persisted Finance evidence could not be read.',503);
        return $stored;
    }

    /** Local persistence and its mandatory audit commit together; never commit a caller's transaction. */
    private function atomic(string $table,callable $work):array|WP_Error
    {
        global $wpdb;$started=false;$active=false;$savepoint='';
        try{
            $savepoint='df_finance_'.bin2hex(random_bytes(8));
            foreach(array_unique([$table,Tables::audit_log()])as$required){
                $wpdb->last_error='';$definition=$wpdb->get_row('SHOW CREATE TABLE `'.$required.'`',ARRAY_N);
                if(DatabaseEvidence::failed()||!is_array($definition)||!is_string($definition[1]??null)||preg_match('/\bENGINE=InnoDB\b/i',$definition[1])!==1)throw new \RuntimeException();
            }
            $wpdb->last_error='';$transaction=$wpdb->get_var('SELECT @@session.in_transaction');
            if(DatabaseEvidence::failed()||!in_array((string)$transaction,['0','1'],true))throw new \RuntimeException();
            $active=(string)$transaction==='1';
            $wpdb->last_error='';if($wpdb->query($active?'SAVEPOINT '.$savepoint:'START TRANSACTION')===false||DatabaseEvidence::failed())throw new \RuntimeException();$started=true;
            $result=$work();
            if($result instanceof WP_Error){
                if($wpdb->query($active?'ROLLBACK TO SAVEPOINT '.$savepoint:'ROLLBACK')===false)throw new \RuntimeException();
                if($active&&$wpdb->query('RELEASE SAVEPOINT '.$savepoint)===false)throw new \RuntimeException();$started=false;return $result;
            }
            $wpdb->last_error='';if($wpdb->query($active?'RELEASE SAVEPOINT '.$savepoint:'COMMIT')===false||DatabaseEvidence::failed())throw new \RuntimeException();
            $started=false;return $result;
        }catch(\Throwable){
            if($started){$wpdb->query($active?'ROLLBACK TO SAVEPOINT '.$savepoint:'ROLLBACK');if($active)$wpdb->query('RELEASE SAVEPOINT '.$savepoint);}
            return $this->error('finance_transaction_unavailable','Atomic Finance and audit persistence is unavailable.',503);
        }
    }

    private function creationAudit(array $row,string $type):true|WP_Error
    {
        global $wpdb;$wpdb->last_error='';
        $proof=$wpdb->get_results($wpdb->prepare('SELECT actor_id,context FROM '.Tables::audit_log().' WHERE object_type=%s AND object_id=%s AND event_type=%s LIMIT 2',$type,(string)$row['id'],$type.'_created'),ARRAY_A);
        if(DatabaseEvidence::failed()||!is_array($proof)||count($proof)!==1)return $this->error('finance_audit_unavailable','Unique original Finance audit evidence is required.',409);
        $context=json_decode((string)$proof[0]['context'],true);
        if((string)$proof[0]['actor_id']!==(string)$row['created_by']||$context!==['idempotency_key'=>($row['idempotency_key']??null)===null?'':'[PRESENT]'])return $this->error('finance_audit_conflict','Original Finance audit identity differs.',409);
        return true;
    }

    private function confirmedReplay(array $existing,array $data,bool $declaration,string $type):array|WP_Error
    {
        $audit=$this->creationAudit($existing,$type);if($audit instanceof WP_Error)return $audit;
        return $declaration?$this->confirmDeclarationRecord($existing,$data,true):$existing+['idempotent_replay'=>true];
    }

    private function confirmDeclarationRecord(array|WP_Error|null $stored,array $data,bool $replay):array|WP_Error
    {
        if($stored instanceof WP_Error)return $stored;
        if(!is_array($stored)||(int)($stored['id']??0)<1)return $this->error('finance_declaration_evidence_unavailable','Persisted declaration evidence could not be read.',503);
        if(!$this->replayCompatible($stored,$data))return $this->error('finance_declaration_integrity_invalid','Persisted declaration differs from immutable input.',409);
        $evidence=$this->declarationEvidence($stored);if($evidence instanceof WP_Error)return $evidence;
        return $replay?$stored+['idempotent_replay'=>true]:$stored;
    }

    private function findPeriodRevision(array $data):array|WP_Error|null
    {
        global $wpdb;
        $wpdb->last_error='';
        $row=$wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.Tables::finance_periods().' WHERE environment=%s AND period_start=%s AND period_end=%s AND calculation_version=%s LIMIT 1',
            $data['environment'],$data['period_start'],$data['period_end'],$data['calculation_version']
        ),ARRAY_A);
        if(!empty($wpdb->last_error))return $this->error('period_revision_evidence_unavailable','Period revision evidence could not be read.',503);
        return is_array($row)?$row:null;
    }

    private function replayPeriodRevision(array $existing,array $incoming):array|WP_Error
    {
        if(!$this->replayCompatible($existing,$incoming))return $this->error('period_revision_conflict','Existing period revision conflicts with calculated source evidence.',409);
        return $this->confirmedReplay($existing,$incoming,false,'finance_period');
    }

    private function ledgerIntegrityValid(array $row):bool
    {
        $fields=['environment','source_type','source_id','entry_type','currency','amount','base_currency','base_amount','effective_date','metadata','canonical_hash'];
        foreach($fields as $field)if(!array_key_exists($field,$row))return false;
        try{
            $metadata=json_decode((string)$row['metadata'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($metadata))return false;
            $canonical=[
                'environment'=>Validator::environment((string)$row['environment']),
                'source_type'=>(string)$row['source_type'],
                'source_id'=>(int)$row['source_id'],
                'entry_type'=>Validator::entryType((string)$row['entry_type']),
                'currency'=>Validator::currency((string)$row['currency']),
                'amount'=>Validator::amount($row['amount']),
                'base_currency'=>(string)$row['base_currency'],
                'base_amount'=>$row['base_amount']===null?null:Validator::amount($row['base_amount']),
                'effective_date'=>Validator::date((string)$row['effective_date']),
                'metadata'=>Validator::structured($metadata),
            ];
            return preg_match('/^[a-f0-9]{64}$/',(string)$row['canonical_hash'])===1
                &&hash_equals((string)$row['canonical_hash'],Validator::hash($canonical));
        }catch(\InvalidArgumentException|\JsonException){return false;}
    }

    private function replayCompatible(array $existing,array $incoming):bool
    {
        $ignore=['id','idempotency_key','state','created_at','updated_at','created_by','approved_by','approved_at','review_status','reviewed_by','reviewed_at','reconciliation_state'];foreach($incoming as$field=>$value){if(in_array($field,$ignore,true))continue;if(!array_key_exists($field,$existing))return false;$left=$existing[$field];if($value===null||$left===null){if($value!==null||$left!==null)return false;continue;}if(is_int($value)){if((string)$left!==(string)$value)return false;}elseif(is_float($value)){if(!is_numeric($left)||(float)$left!==$value)return false;}elseif((string)$left!==(string)$value)return false;}return true;
    }

    private function find(string $table,int $id):array|WP_Error|null{if($id<1)return null;global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id),ARRAY_A);if(!empty($wpdb->last_error))return $this->error('finance_evidence_unavailable','Finance authority evidence could not be read.',503);return is_array($row)?$row:null;}
    private function now():string{return current_time('mysql',true);}
    private function error(string $code,string $message,int $status=400):WP_Error{return new WP_Error($code,$message,['status'=>$status]);}
}
