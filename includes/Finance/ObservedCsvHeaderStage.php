<?php
declare(strict_types=1);
namespace DigiForge\Finance;

/** Shared bounded inspection for server-pinned header witnesses; no accounting or custody authority. */
final class ObservedCsvHeaderStage
{
    public static function preview(string $profile,string $provider,array $documents,array $context,array $selected,int $asOf):array
    {
        if(!in_array($profile,['printify','etsy'],true))self::invalid();
        $printify=$profile==='printify';$type=$printify?PrintifyOrderExportStage::class:EtsyMonthlyCsvStage::class;
        $shops=$printify?['personalized_pod']:['digital','personalized_pod'];
        if($provider!==($printify?'printify':'etsy')||!in_array($context['shop_key']??null,$shops,true))self::invalid();
        $file=$printify?'printify-order-export-header-v1.csv':'etsy-monthly-header-v1.csv';$length=$printify?306:73;$count=$printify?20:9;
        EtsyNativeObservationStage::assertContext($context,$selected,$type::HEADER_SHA256);
        $header=@file_get_contents(__DIR__.'/contracts/'.$file,false,null,0,$length+1);
        if(!is_string($header)||strlen($header)!==$length||!hash_equals($type::HEADER_SHA256,hash('sha256',$header)))self::invalid();
        $decoded=$printify?$header:substr($header,3);$columns=str_getcsv(substr($decoded,0,-1),',','"','');
        if(count($columns)!==$count||count(array_unique($columns))!==$count)self::invalid();
        if(!array_is_list($documents)||$documents===[]||count($documents)>EtsyNativeObservationStage::MAX_DOCUMENTS)self::invalid();
        $originals=[];$references=[];
        foreach($documents as$document){
            if(!is_array($document))self::invalid();$keys=array_keys($document);sort($keys);
            if($keys!==['capture','declared_rows','raw']||!is_string($document['raw'])||strlen($document['raw'])>Validator::MAX_BODY_BYTES
                ||!str_starts_with($document['raw'],$header)||!is_array($document['capture']))self::invalid();
            $reference=$document['capture']['source_reference']??null;$hash=hash('sha256',$document['raw']);
            if(!is_string($reference)||(isset($references[$reference])&&!hash_equals($references[$reference],$hash)))self::invalid();
            $references[$reference]=$hash;
            $originals[]=$document+['layout'=>['delimiter'=>',','headers'=>$columns]];
        }
        // Reuse the same strict byte-preserving syntax/population controls, without changing the caller's profile or bytes.
        $syntaxContext=$context;$syntaxContext['source_contract_sha256']=hash('sha256',CsvSourceStage::SCHEMA);
        $syntaxSelected=$selected;$syntaxSelected['source_contract_sha256']=$syntaxContext['source_contract_sha256'];
        $packet=CsvSourceStage::preview($provider,$originals,$syntaxContext,$syntaxSelected,$asOf);
        $packet['schema']=$type::SCHEMA;$packet['state']=$printify?'OBSERVED_HEADER_MATCHED_UNVERIFIED_ORDER_EXPORT':'OBSERVED_HEADER_MATCHED_UNVERIFIED_MONTHLY_CSV';$packet['context']=$context;
        $packet['observed_header_sha256']=$type::HEADER_SHA256;$packet['header_encoding']=$printify?'ASCII_VALID_UTF8_NO_BOM_LF':'UTF8_BOM_LF';$packet['publisher_encoding']='UNKNOWN';$packet['source_contract_kind']='OBSERVED_HEADER_ONLY';$packet['provider_schema']='OBSERVED_HEADER_ONLY';
        foreach($printify?['financial_amounts','money_units','dates','order_status','invoices','transaction_identity']:['financial_amounts','money_units','dates','type_values','details_encoding','transaction_identity','corrections','original_file_custody']as$field)$packet[$field]='UNKNOWN';
        foreach($packet['sources']as$i=>&$source){
            $source['native_schema']=$printify?'PRINTIFY_ORDER_EXPORT_HEADER_V1':'ETSY_MONTHLY_HEADER_V1';$source['observed_header_sha256']=$type::HEADER_SHA256;
            $source['source_reference_sha256']=hash('sha256',$documents[$i]['capture']['source_reference']);
            $source['capture_sha256']=Validator::hash($documents[$i]['capture']);
        }
        unset($source,$packet['stage_hash']);
        $packet['mapping_blockers']=[$printify?'ORDER_EXPORT_NOT_PAID_INVOICE_TRANSACTION_OR_SETTLEMENT':'MONTHLY_CSV_NOT_ACCOUNTING_RECOGNITION',
            $printify?'MONEY_UNITS_DATES_STATUS_IDENTITY_UNKNOWN':'UNITS_DATES_TYPES_IDENTITIES_CORRECTIONS_UNKNOWN',
            'UNVERIFIED_PROVIDER_ACCOUNT_STORE_OWNERSHIP','UNKNOWN_SOURCE_COMPLETENESS_AND_FINALITY'];
        $packet['stage_hash']=Validator::hash($packet);return $packet;
    }
    private static function invalid():never{throw new \InvalidArgumentException('Exact observed CSV header profile and bounded declared scope required.');}
}
