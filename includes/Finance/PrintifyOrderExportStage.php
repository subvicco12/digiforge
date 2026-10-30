<?php
declare(strict_types=1);
namespace DigiForge\Finance;

/** Observed header profile only. No verified row semantics, paid invoice, transaction or settlement contract. */
final class PrintifyOrderExportStage
{
    public const SCHEMA='digiforge-printify-order-export-observation-v1';
    public const HEADER_SHA256='8b5db86bf5a3855b24a3082e660c97d646743e48023b8d421dea0e65a882c78d';
    public const FORMAT='printify_order_export_v1';

    public static function preview(string $provider,array $documents,array $context,array $selected,int $asOf):array
    {
        if($provider!=='printify'||($context['shop_key']??null)!=='personalized_pod')self::invalid();
        EtsyNativeObservationStage::assertContext($context,$selected,self::HEADER_SHA256);
        $header=@file_get_contents(__DIR__.'/contracts/printify-order-export-header-v1.csv',false,null,0,307);
        if(!is_string($header)||strlen($header)!==306||!hash_equals(self::HEADER_SHA256,hash('sha256',$header)))self::invalid();
        $columns=str_getcsv(substr($header,0,-1),',','"','');
        if(count($columns)!==20||count(array_unique($columns))!==20)self::invalid();
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
        $packet['schema']=self::SCHEMA;$packet['state']='OBSERVED_HEADER_MATCHED_UNVERIFIED_ORDER_EXPORT';$packet['context']=$context;
        $packet['observed_header_sha256']=self::HEADER_SHA256;$packet['header_encoding']='ASCII_VALID_UTF8_NO_BOM_LF';$packet['publisher_encoding']='UNKNOWN';$packet['source_contract_kind']='OBSERVED_HEADER_ONLY';$packet['provider_schema']='OBSERVED_HEADER_ONLY';
        foreach(['financial_amounts','money_units','dates','order_status','invoices','transaction_identity']as$field)$packet[$field]='UNKNOWN';
        foreach($packet['sources']as$i=>&$source){
            $source['native_schema']='PRINTIFY_ORDER_EXPORT_HEADER_V1';$source['observed_header_sha256']=self::HEADER_SHA256;
            $source['source_reference_sha256']=hash('sha256',$documents[$i]['capture']['source_reference']);
            $source['capture_sha256']=Validator::hash($documents[$i]['capture']);
        }
        unset($source,$packet['stage_hash']);
        $packet['mapping_blockers']=['ORDER_EXPORT_NOT_PAID_INVOICE_TRANSACTION_OR_SETTLEMENT','MONEY_UNITS_DATES_STATUS_IDENTITY_UNKNOWN',
            'UNVERIFIED_PROVIDER_ACCOUNT_STORE_OWNERSHIP','UNKNOWN_SOURCE_COMPLETENESS_AND_FINALITY'];
        $packet['stage_hash']=Validator::hash($packet);return $packet;
    }
    private static function invalid():never{throw new \InvalidArgumentException('Exact observed Printify order-header profile and bounded declared scope required.');}
}
