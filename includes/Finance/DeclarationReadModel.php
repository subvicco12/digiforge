<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence as DatabaseEvidence;
use WP_Error;

/** Bounded provisional declaration evidence. No provider authenticity, tax classification or financial authority. */
final class DeclarationReadModel
{
    public function snapshot(string $shop='all',string $environment='test',string $start='',string $end='',string $baseCurrency='USD',int $limit=100):array
    {
        try{$scope=self::scope($shop,$environment,$start,$end,$baseCurrency);}catch(\InvalidArgumentException){return self::blocked([], 'SCOPE_INVALID', 'Explicit canonical report scope is required.');}
        $limit=max(1,min(200,$limit));global $wpdb;
        $wpdb->last_error='';
        // Namespace OR payload marker keeps corrupted/unclassifiable declarations visible to integrity checks.
        // A valid foreign shop can be skipped only after typed source validation, never by parsing damaged JSON in SQL.
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE environment=%s AND effective_date BETWEEN %s AND %s AND (idempotency_key LIKE %s OR metadata LIKE %s) ORDER BY id DESC LIMIT %d',
            $scope['environment'],$scope['period_start'],$scope['period_end'],$wpdb->esc_like('finance_decl:').'%','%"finance_declaration":%',$limit+1),ARRAY_A);
        if(DatabaseEvidence::failed()||!is_array($rows))return self::blocked($scope,'SOURCE_UNAVAILABLE','Declaration ledger evidence could not be read.','UNAVAILABLE');
        $truncated=count($rows)>$limit;$entries=[];$repo=new Repository();$histories=[];
        foreach(array_slice($rows,0,$limit)as$row){
            $evidence=$repo->declarationEvidence($row);
            if($evidence instanceof WP_Error)return self::blocked($scope,'SOURCE_REFERENCE_INVALID','Current declaration source, ownership or FX links could not be verified.');
            if($shop!=='all'&&$evidence['declaration']['scope']['shop_key']!==$shop)continue;
            $key=DeclarationContract::identityKey($evidence['declaration']);
            if(isset($histories[$key]))continue;
            $histories[$key]=true;$history=$repo->declarationHistory($evidence['declaration']);
            if($history instanceof WP_Error)return self::blocked($scope,'HISTORY_UNAVAILABLE','Complete bounded transaction revision evidence could not be read.');
            if($history===[])return self::blocked($scope,'HISTORY_MISSING','Persisted declaration history is missing.');
            foreach($history as$entry)$entries[$entry['ledger_id']]=$entry;
            if(count($entries)>200){$truncated=true;break;}
        }
        // Reversals can occur in a different period from their original; resolve exact bounded histories before filtering totals.
        $resolved=[];$changed=true;
        while($changed&&!$truncated){
            $changed=false;$known=[];foreach($entries as$entry)$known[$entry['declaration']['declaration_hash']]=true;
            foreach($entries as$entry){$d=$entry['declaration'];$target=$d['reversal_of_hash'];
                if($target===''||isset($known[$target])||isset($resolved[$target]))continue;
                $resolved[$target]=true;$original=$repo->declarationByHash($target,$environment);
                if($original instanceof WP_Error||!DeclarationMatcher::isReversal($d,$original['declaration']))return self::blocked($scope,'REVERSAL_REFERENCE_INVALID','Exact compatible original reversal evidence is unavailable.');
                $history=$repo->declarationHistory($original['declaration']);
                if($history instanceof WP_Error||$history===[])return self::blocked($scope,'HISTORY_UNAVAILABLE','Complete bounded original transaction history is unavailable.');
                foreach($history as$related)$entries[$related['ledger_id']]=$related;
                $changed=true;
                if(count($entries)>200){$truncated=true;break;}
            }
        }
        ksort($entries,SORT_NUMERIC);
        return self::project(array_values($entries),$scope,$truncated);
    }

    /** Pure projection over reference-validated entries. Useful for offline review; never grants authority. */
    public static function project(array $entries,array $scope,bool $truncated=false):array
    {
        try{$scope=self::scope($scope['shop_key'],$scope['environment'],$scope['period_start'],$scope['period_end'],$scope['base_currency']);}
        catch(\InvalidArgumentException|\TypeError){return self::blocked([], 'SCOPE_INVALID','Explicit canonical report scope is required.');}
        $normalized=[];$identities=[];
        foreach($entries as$entry){
            try{$d=DeclarationContract::fromStored($entry['declaration']);}
            catch(\InvalidArgumentException|\TypeError){return self::blocked($scope,'CONTRACT_INVALID','Typed immutable declaration evidence could not be verified.');}
            if($d['environment']!==$scope['environment'])continue;
            $entry['declaration']=$d;$normalized[]=$entry;
            if($scope['shop_key']==='all'||$d['scope']['shop_key']===$scope['shop_key'])$identities[DeclarationContract::identityKey($d)]=true;
        }
        // Keep complete related histories until matching; filtering individual revisions first can resurrect superseded facts.
        $entries=array_values(array_filter($normalized,static fn(array$entry):bool=>isset($identities[DeclarationContract::identityKey($entry['declaration'])])));
        if(count($entries)>200){$truncated=true;$entries=array_slice($entries,0,200);}
        $matching=DeclarationMatcher::match($entries,$scope['period_end']);
        $active=array_values(array_filter($matching['active'],static fn(array$entry):bool=>($scope['shop_key']==='all'||$entry['declaration']['scope']['shop_key']===$scope['shop_key'])&&substr($entry['declaration']['occurred_at'],0,10)>=$scope['period_start']&&substr($entry['declaration']['occurred_at'],0,10)<=$scope['period_end']));
        $visible=[];foreach($entries as$entry)if($scope['shop_key']==='all'||$entry['declaration']['scope']['shop_key']===$scope['shop_key'])$visible[$entry['ledger_id']]=true;
        $matching['rows']=array_values(array_filter($matching['rows'],static fn(array$row):bool=>isset($visible[$row['ledger_id']])));
        $matching['issues']=array_values(array_filter($matching['issues'],static fn(array$issue):bool=>!isset($issue['ledger_id'])||isset($visible[$issue['ledger_id']])));
        $blocked=$truncated||in_array($matching['state'],['CONFLICT','MISSING'],true);
        if(in_array($matching['state'],['CONFLICT','MISSING'],true))$matching['issues'][]=['code'=>'HISTORY_CONFLICT','message'=>'Complete compatible transaction history is required.'];
        if($truncated)$matching['issues'][]=['code'=>'TRUNCATED','message'=>'Bounded evidence is incomplete; no complete totals are inferred.'];
        foreach($active as$entry)if($entry['declaration']['base_currency']!==$scope['base_currency']){
            $blocked=true;$matching['issues'][]=['code'=>'BASE_CURRENCY_CONFLICT','ledger_id'=>$entry['ledger_id'],'message'=>'An explicit matching-currency FX source is required.'];
        }
        $matching['issues'][]=['code'=>'AUTHENTICITY_UNKNOWN','message'=>'Internal declarations do not authenticate source statements, actual fees, charges or final settlement.'];
        unset($matching['active']);$matching['issues']=array_slice($matching['issues'],0,200);
        $groups=['business'=>[],'shop'=>[],'order'=>[],'product'=>[],'listing'=>[],'variant'=>[]];$totals=null;$gst=[];
        if(!$blocked&&$active!==[]){
            $totals=self::metrics($active);
            $buckets=[];
            foreach($active as$entry){
                $d=$entry['declaration'];$s=$d['scope'];
                $dimensions=['business'=>$s['business_id'],'shop'=>$s['shop_key'],'order'=>$s['order_id'],
                    'product'=>$entry['product_id']??0,'listing'=>$s['listing_id'],'variant'=>$s['variant_ref']===''?'':$s['provider_mapping_id'].':'.$s['variant_ref']];
                foreach($dimensions as$type=>$id){
                    if($id===0||$id===''){
                        if(count($matching['issues'])<200)$matching['issues'][]=['code'=>'ATTRIBUTION_UNKNOWN','ledger_id'=>$entry['ledger_id'],'message'=>'One direct dimension attribution is unspecified; no allocation is inferred.'];
                        continue;
                    }
                    $buckets[$type][(string)$id]['id']=$id;$buckets[$type][(string)$id]['entries'][]=$entry;
                }
                $gst[]=['ledger_id'=>$entry['ledger_id'],'declaration_hash'=>$d['declaration_hash'],'occurred_at'=>$d['occurred_at'],
                    'currency'=>$d['currency'],'amount'=>$d['amount'],'base_currency'=>$d['base_currency'],'base_amount'=>$d['base_amount'],
                    'category'=>$d['category'],'source_document_ref'=>$d['source']['document_ref'],'source_document_hash'=>$d['source']['document_hash'],
                    'fx_snapshot_id'=>$d['fx_snapshot_id'],'review_status'=>'UNREVIEWED','jurisdiction'=>'UNKNOWN'];
            }
            foreach($buckets as$type=>$values){ksort($values,SORT_STRING);foreach($values as$bucket){
                $group=['dimension_id'=>$bucket['id'],'entry_count'=>count($bucket['entries']),'metrics'=>self::metrics($bucket['entries'])];
                if($type==='product'){$versions=array_values(array_unique(array_map(static fn(array$entry):int=>$entry['declaration']['scope']['product_version_id'],$bucket['entries'])));sort($versions,SORT_NUMERIC);$group['product_version_ids']=$versions;}
                if($type==='variant'){$group['provider_mapping_id']=$bucket['entries'][0]['declaration']['scope']['provider_mapping_id'];$group['variant_ref']=$bucket['entries'][0]['declaration']['scope']['variant_ref'];}
                $groups[$type][]=$group;
            }}
        }
        return ['schema'=>'finance-declaration-report-v1','calculation_version'=>'declared-profit-v2-exact-1','accounting_convention'=>'signed-bucket-v1',
            'query_state'=>'AVAILABLE','state'=>$blocked?'BLOCKED':($active===[]?'NO_DECLARATIONS':'PROVISIONAL'),'scope'=>$scope,
            'authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','totals'=>$totals,'groups'=>$groups,'matching'=>$matching,
            'gst_review'=>['state'=>'EVIDENCE_ONLY','format'=>'ROW_ARRAYS_NOT_CSV','rows'=>$gst],'truncated'=>$truncated,
            'external_execution_authorized'=>false,'money_movement_authorized'=>false,'tax_filing_authorized'=>false,'period_approval_authorized'=>false];
    }

    private static function metrics(array $entries):array
    {
        return ProvisionalProfitMetrics::fromAmounts(array_map(static fn(array $entry): array =>
            ['category'=>$entry['declaration']['category'],'base_amount'=>$entry['declaration']['base_amount']],$entries));
    }

    private static function scope(string $shop,string $environment,string $start,string $end,string $base):array
    {
        if($shop!=='all'&&!in_array($shop,DeclarationContract::SHOPS,true))throw new \InvalidArgumentException('Invalid shop.');
        foreach([$start,$end]as$date)if($date!==''&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date)!==1)throw new \InvalidArgumentException('Canonical report dates required.');
        $environment=Validator::environment($environment);$base=Validator::currency($base);
        $start=Validator::date($start===''?gmdate('Y-m-01'):$start);$end=Validator::date($end===''?gmdate('Y-m-t'):$end);
        if($start>$end)throw new \InvalidArgumentException('Invalid period.');
        return ['shop_key'=>$shop,'environment'=>$environment,'period_start'=>$start,'period_end'=>$end,'base_currency'=>$base];
    }

    private static function blocked(array $scope,string $code,string $message,string $query='AVAILABLE'):array
    {
        return ['schema'=>'finance-declaration-report-v1','calculation_version'=>'declared-profit-v2-exact-1','accounting_convention'=>'signed-bucket-v1',
            'query_state'=>$query,'state'=>'BLOCKED','scope'=>$scope,'authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','totals'=>null,
            'groups'=>['business'=>[],'shop'=>[],'order'=>[],'product'=>[],'listing'=>[],'variant'=>[]],
            'matching'=>['state'=>'UNKNOWN','issues'=>[['code'=>$code,'message'=>$message]],'rows'=>[]],
            'gst_review'=>['state'=>'EVIDENCE_ONLY','format'=>'ROW_ARRAYS_NOT_CSV','rows'=>[]],'truncated'=>false,
            'external_execution_authorized'=>false,'money_movement_authorized'=>false,'tax_filing_authorized'=>false,'period_approval_authorized'=>false];
    }
}
