<?php
declare(strict_types=1);
namespace DigiForge\Portal;

/** Fixed redacted view of the established allocation packet; never a persistable full packet. */
final class FinanceImportPreviewProjection
{
    private const TARGET=['shop_key','business_id','store_id','order_id','order_line_item_id','product_id','product_version_id','listing_id','provider_mapping_id','variant_ref','environment','currency'];

    public static function fromPacket(array $packet,array $scope,string $sourceHash):array
    {
        $source=$packet['source']??null;
        if(($packet['schema']??null)!=='digiforge-finance-allocation-proposal-v1'||($packet['state']??null)!=='PROVISIONAL'
            ||($packet['review_status']??null)!=='UNREVIEWED'||!is_array($source)||($source['declaration_hash']??null)!==$sourceHash
            ||!is_int($packet['source_ledger_id']??null)||$packet['source_ledger_id']<1
            ||!in_array($packet['reconciliation_state']??null,['EXPLICIT_SUM_MATCH','EXPLICIT_REVERSAL_PROPOSAL'],true))self::invalid();
        self::hash($sourceHash);self::hash($packet['proposal_hash']??null);
        $reverse=$packet['reversal_of_proposal_hash']??null;if($reverse!=='')self::hash($reverse);
        foreach(['shop_key','business_id','store_id'] as $key)if(($source['scope'][$key]??null)!==($scope[$key]??null))self::invalid();
        if(($source['environment']??null)!==($scope['environment']??null))self::invalid();
        foreach(['currency','base_currency'] as $key)if(!is_string($source[$key]??null)||preg_match('/^[A-Z]{3}$/D',$source[$key])!==1)self::invalid();
        self::money($packet['allocated_amount']??null);self::money($packet['allocated_base_amount']??null);
        if(!is_array($packet['lines']??null)||!array_is_list($packet['lines'])||count($packet['lines'])<1||count($packet['lines'])>200)self::invalid();
        $lines=[];
        foreach($packet['lines'] as $line){
            if(!is_array($line)||!is_array($line['target']??null))self::invalid();$target=[];
            foreach(self::TARGET as $key){if(!array_key_exists($key,$line['target']))self::invalid();$target[$key]=$line['target'][$key];}
            foreach(['shop_key','business_id','store_id','environment'] as $key)if($target[$key]!==($scope[$key]??null))self::invalid();
            foreach(['business_id','store_id','order_id','order_line_item_id','product_id','product_version_id','listing_id','provider_mapping_id'] as $key)if(!is_int($target[$key])||$target[$key]<($key==='provider_mapping_id'?0:1))self::invalid();
            if($target['currency']!==$source['currency']||!is_string($target['variant_ref'])||strlen($target['variant_ref'])>191
                ||($target['variant_ref']!==''&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/D',$target['variant_ref'])!==1))self::invalid();
            self::hash($line['reference_hash']??null);self::money($line['amount']??null);self::money($line['base_amount']??null);
            $lines[]=['target'=>$target,'reference_hash'=>$line['reference_hash'],'amount'=>$line['amount'],'base_amount'=>$line['base_amount']];
        }
        return ['packet_schema'=>$packet['schema'],'state'=>'PROVISIONAL','review_status'=>'UNREVIEWED','scope'=>$scope,
            'source_ledger_id'=>$packet['source_ledger_id'],'source_hash'=>$sourceHash,'proposal_hash'=>$packet['proposal_hash'],
            'reversal_of_proposal_hash'=>$reverse,'reconciliation_state'=>$packet['reconciliation_state'],
            'currency'=>$source['currency'],'base_currency'=>$source['base_currency'],
            'allocated_amount'=>$packet['allocated_amount'],'allocated_base_amount'=>$packet['allocated_base_amount'],'lines'=>$lines,
            'authenticity'=>'UNKNOWN','source_completeness'=>'UNKNOWN','actual_settlement_verified'=>false,
            'period_approval_authorized'=>false,'external_execution_authorized'=>false,'money_movement_authorized'=>false,
            'tax_filing_authorized'=>false,'persistence_performed'=>false,'preview_only'=>true];
    }

    private static function money(mixed $value):void
    {
        if(!is_string($value)||strlen($value)>32||preg_match('/^-?(?:0|[1-9][0-9]*)\.[0-9]{4}$/D',$value)!==1)self::invalid();
    }
    private static function hash(mixed $value):void {if(!is_string($value)||preg_match('/^[a-f0-9]{64}$/D',$value)!==1)self::invalid();}
    private static function invalid():never {throw new \InvalidArgumentException('An exact current redacted allocation preview is required.');}
}
