<?php
declare(strict_types=1);
namespace DigiForge\Finance;

/** Exact internal identities only. A declared match never proves payment, finality or provider authenticity. */
final class DeclarationMatcher
{
    public static function match(array $entries,string $periodEnd):array
    {
        Validator::date($periodEnd);
        $groups=[];$byHash=[];$states=[];$issues=[];$declarations=[];$conflict=false;$missing=false;
        $issue=static function(string $code,int $index,string $message)use(&$issues,$entries):void{
            if(count($issues)<200)$issues[]=['code'=>$code,'ledger_id'=>(int)($entries[$index]['ledger_id']??0),'message'=>$message];
        };
        foreach($entries as$i=>$entry){
            $states[$i]=[];
            try{$declaration=DeclarationContract::fromStored($entry['declaration']);}
            catch(\InvalidArgumentException|\TypeError){$states[$i]=['CONFLICT'];$conflict=true;$issue('INVALID_DECLARATION',$i,'Declaration hash or typed fields are invalid.');continue;}
            $declarations[$i]=$declaration;$hash=$declaration['declaration_hash'];
            if(isset($byHash[$hash])){$states[$i]=['DUPLICATE'];$issue('DUPLICATE',$i,'Repeated identical declaration is counted once.');continue;}
            $byHash[$hash]=$i;$key=DeclarationContract::identityKey($declaration);$revision=$declaration['revision'];
            if(isset($groups[$key][$revision])){
                $states[$i]=['CONFLICT'];$states[$groups[$key][$revision]]=['CONFLICT'];$conflict=true;
                $issue('REVISION_CONFLICT',$i,'One transaction revision has conflicting immutable evidence.');continue;
            }
            $groups[$key][$revision]=$i;
        }
        $active=[];$currentHashes=[];
        ksort($groups,SORT_STRING);
        foreach($groups as$revisions){
            ksort($revisions,SORT_NUMERIC);$previous=null;$complete=true;
            foreach($revisions as$revision=>$i){
                $declaration=$declarations[$i];
                if($previous!==null&&($declaration['scope']['business_id']!==$declarations[$previous]['scope']['business_id']||$declaration['scope']['shop_key']!==$declarations[$previous]['scope']['shop_key'])){
                    $states[$i][]='CONFLICT';$states[$previous][]='CONFLICT';$conflict=true;$complete=false;$issue('SCOPE_CONFLICT',$i,'Immutable business/shop scope changed across transaction revisions.');
                }
                if(($revision===1&&$previous!==null)||($revision>1&&($previous===null||$revision!==$declarations[$previous]['revision']+1||$declaration['previous_revision_hash']!==$declarations[$previous]['declaration_hash']))){
                    $states[$i][]='MISSING';$missing=true;$complete=false;$issue('PREDECESSOR_MISSING',$i,'Exact contiguous predecessor evidence is missing.');
                }
                $previous=$i;
            }
            $latest=end($revisions);
            foreach($revisions as$i)if($i!==$latest)$states[$i][]='SUPERSEDED';
            if($complete){$active[$latest]=$entries[$latest];$currentHashes[$declarations[$latest]['declaration_hash']]=true;}
        }
        $reversed=[];
        foreach($active as$i=>$entry){
            $declaration=$declarations[$i];$target=$declaration['reversal_of_hash'];
            if($target!==''){
                if(!isset($byHash[$target])){$missing=true;$states[$i][]='MISSING';$issue('REVERSAL_MISSING',$i,'Exact original declaration is missing.');}
                elseif(!isset($currentHashes[$target])||!self::isReversal($declaration,$declarations[$byHash[$target]])||isset($reversed[$target])){
                    $conflict=true;$states[$i][]='CONFLICT';$issue('REVERSAL_CONFLICT',$i,'Reversal scope, revision or signed amounts conflict.');
                }else{$reversed[$target]=true;$states[$i][]='REVERSED';$states[$byHash[$target]][]='REVERSED';}
            }
            if(substr($declaration['observed_at'],0,10)>$periodEnd){$states[$i][]='LATE';$issue('LATE',$i,'Evidence was observed after the inclusive period end.');}
        }
        $rows=[];$late=false;
        foreach($entries as$i=>$entry){
            if($states[$i]===[])$states[$i]=['DECLARED_MATCH'];
            if(in_array('LATE',$states[$i],true))$late=true;
            $rows[]=['ledger_id'=>(int)($entry['ledger_id']??0),'declaration_hash'=>(string)($entry['declaration']['declaration_hash']??''),'statuses'=>array_values(array_unique($states[$i]))];
        }
        return ['state'=>$conflict?'CONFLICT':($missing?'MISSING':($reversed!==[]?'REVERSED':($late?'LATE':($entries===[]?'UNKNOWN':'DECLARED_MATCH')))),
            'rows'=>$rows,'issues'=>$issues,'active'=>$conflict||$missing?[]:array_values($active),'authenticity'=>'UNKNOWN',
            'actual_settlement_verified'=>false,'period_approval_authorized'=>false];
    }

    public static function isReversal(array $reversal,array $original):bool
    {
        foreach(['provider','environment','category','currency','base_currency']as$field)if($reversal[$field]!==$original[$field])return false;
        $left=$reversal['scope'];$right=$original['scope'];ksort($left,SORT_STRING);ksort($right,SORT_STRING);
        if($left!==$right)return false;
        return $reversal['reversal_of_hash']===$original['declaration_hash']
            &&DeclarationContract::identityKey($reversal)!==DeclarationContract::identityKey($original)
            &&$original['amount']!=='0.0000'
            &&ExactDecimal::add($reversal['amount'],$original['amount'])==='0.0000'
            &&ExactDecimal::add($reversal['base_amount'],$original['base_amount'])==='0.0000';
    }
}
