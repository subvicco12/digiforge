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
        // Match allocation preview's conservative environment bound: a forward reversal
        // must not disappear because it occurred outside the requested accounting period.
        // Namespace OR payload marker also keeps damaged declaration evidence visible.
        $sql=$wpdb->prepare('SELECT * FROM '.Tables::finance_ledger().' WHERE environment=%s AND (idempotency_key LIKE %s OR metadata LIKE %s) ORDER BY id ASC LIMIT 201',
            $scope['environment'],$wpdb->esc_like('finance_decl:').'%','%"finance_declaration":%');
        $rows=$wpdb->get_results($sql,ARRAY_A);
        if(DatabaseEvidence::failed()||!is_array($rows))return self::blocked($scope,'SOURCE_UNAVAILABLE','Declaration ledger evidence could not be read.','UNAVAILABLE');
        if(count($rows)>200){$report=self::blocked($scope,'TRUNCATED','Complete bounded environment declaration evidence is required.');$report['truncated']=true;return $report;}
        $entries=[];$seeds=[];$periodCount=0;$repo=new Repository();
        foreach($rows as$row){
            $evidence=$repo->declarationEvidence($row);
            if($evidence instanceof WP_Error)return self::blocked($scope,'SOURCE_REFERENCE_INVALID','Current declaration source, ownership or FX links could not be verified.');
            $entries[]=$evidence;$d=$evidence['declaration'];$date=substr($d['occurred_at'],0,10);
            if($date>=$scope['period_start']&&$date<=$scope['period_end']){
                ++$periodCount;
                if($shop==='all'||$d['scope']['shop_key']===$shop)$seeds[]=$evidence;
            }
        }
        $related=self::related($entries,$seeds);$historyCounts=[];
        foreach($related as$entry){
            $identity=DeclarationContract::identityKey($entry['declaration']);
            $historyCounts[$identity]=($historyCounts[$identity]??0)+1;
            // Retain Repository::declarationHistory's existing fifty-row review window.
            if($historyCounts[$identity]>50)return self::blocked($scope,'HISTORY_UNAVAILABLE','Complete bounded transaction revision evidence could not be read.');
        }
        $report=self::project($related,$scope,$periodCount>$limit);
        // Detect observed additions/removals/revisions during source/reference validation.
        $wpdb->last_error='';$again=$wpdb->get_results($sql,ARRAY_A);
        if(DatabaseEvidence::failed()||!is_array($again))return self::blocked($scope,'SOURCE_UNAVAILABLE','Declaration ledger evidence could not be reread.','UNAVAILABLE');
        if(Validator::hash($rows)!==Validator::hash($again))return self::blocked($scope,'SOURCE_CHANGED','Declaration evidence changed during reporting.');
        return $report;
    }

    /** Pure projection over reference-validated entries. Useful for offline review; never grants authority. */
    public static function project(array $entries,array $scope,bool $truncated=false):array
    {
        try{$scope=self::scope($scope['shop_key'],$scope['environment'],$scope['period_start'],$scope['period_end'],$scope['base_currency']);}
        catch(\InvalidArgumentException|\TypeError){return self::blocked([], 'SCOPE_INVALID','Explicit canonical report scope is required.');}
        $normalized=[];$seeds=[];
        foreach($entries as$entry){
            try{$d=DeclarationContract::fromStored($entry['declaration']);}
            catch(\InvalidArgumentException|\TypeError){return self::blocked($scope,'CONTRACT_INVALID','Typed immutable declaration evidence could not be verified.');}
            if($d['environment']!==$scope['environment'])continue;
            $entry['declaration']=$d;$normalized[]=$entry;
            if($scope['shop_key']==='all'||$d['scope']['shop_key']===$scope['shop_key'])$seeds[]=$entry;
        }
        // Keep connected revision/reversal histories until matching; mask foreign shop
        // rows only after they have had a chance to invalidate conflicting evidence.
        $entries=self::related($normalized,$seeds);
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

    /** Same connected identity/reversal traversal as allocation preview, before period arithmetic. */
    private static function related(array $entries,array $seeds):array
    {
        $identities=[];$hashes=[];$selected=[];$changed=true;
        foreach($seeds as$entry){$d=$entry['declaration'];$identities[DeclarationContract::identityKey($d)]=true;$hashes[$d['declaration_hash']]=true;}
        while($changed){
            $changed=false;
            foreach($entries as$i=>$entry){
                $d=$entry['declaration'];$identity=DeclarationContract::identityKey($d);$reverse=$d['reversal_of_hash'];
                if(isset($selected[$i]))continue;
                if(!isset($identities[$identity])&&!isset($hashes[$d['declaration_hash']])&&($reverse===''||!isset($hashes[$reverse])))continue;
                $selected[$i]=$entry;$identities[$identity]=true;$hashes[$d['declaration_hash']]=true;
                if($reverse!=='')$hashes[$reverse]=true;
                $changed=true;
            }
        }
        ksort($selected,SORT_NUMERIC);return array_values($selected);
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
