<?php
declare(strict_types=1);
namespace DigiForge\AI;
use WP_Error;

/** Admission of retained final-charge source assertions; never authenticates a provider or releases a hold. */
final class FinalProviderStatement
{
    public const POLICY_VERSION='shop2-final-charge-review-v1';
    public static function evidence(string $document,string $responseId):array|WP_Error
    {
        if(strlen($document)<2||strlen($document)>32768)return self::error();
        try{$doc=json_decode($document,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){return self::error();}
        if(!is_array($doc)||($doc['provider']??null)!=='openai'||!is_string($doc['invoice_id']??null)||!is_array($doc['lines']??null)||!array_is_list($doc['lines'])||count($doc['lines'])>100||($doc['finality']['status']??null)!=='FINAL'||($doc['finality']['additional_charges_possible']??null)!==false)return self::error();
        $matches=[];foreach($doc['lines']as $line)if(is_array($line)&&($line['response_id']??null)===$responseId)$matches[]=$line;
        if(count($matches)!==1)return self::error();
        $e=['source'=>'provider_statement','source_sha256'=>hash('sha256',$document),'source_document'=>$document,'invoice_id'=>$doc['invoice_id']];
        foreach(['line_id','response_id','model','currency','amount']as $key){if(!is_string($matches[0][$key]??null))return self::error();$e[$key]=$matches[0][$key];}
        return $e;
    }
    /** Original statement assertions are disclosed, never upgraded into provider qualification. */
    public static function finality(?array $settlement):string
    {
        if($settlement===null)return 'UNKNOWN';$raw=$settlement['source_document']??null;$charge=$settlement['charge']??null;
        if(!is_string($raw)||!is_array($charge)||!is_string($charge['response_id']??null))return 'UNVERIFIED_LEGACY_STATEMENT';
        $e=self::evidence($raw,$charge['response_id']);return $e instanceof WP_Error||!hash_equals((string)($charge['source_sha256']??''),$e['source_sha256'])?'UNVERIFIED_LEGACY_STATEMENT':'SOURCE_ASSERTS_FINAL';
    }
    private static function error():WP_Error{return new WP_Error('final_provider_statement_required','A compatible original final provider statement and exact human review are required. Missing, provisional, unsupported or uncertain charge evidence remains held. Do not rewrite originals to add finality claims.',['status'=>409,'retry_permitted'=>false,'refund_assumed'=>false]);}
}
