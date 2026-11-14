<?php
declare(strict_types=1);
namespace DigiForge\Finance;

/** Bounded, nonpersisting native-source workbench. Contexts are assertions, never ownership grants. */
final class EtsyNativeObservationStage
{
    public const MAX_DOCUMENTS=20;
    public const MAX_BYTES=262144;
    private const CONTEXT=['shop_key','environment','business_id','store_id','external_shop_id','external_account_id','integration_id','source_contract_sha256'];

    public static function preview(array $documents,array $context,array $selectedContext,int $asOf):array
    {
        self::exact($context,self::CONTEXT);self::exact($selectedContext,self::CONTEXT);
        foreach(self::CONTEXT as $key)if($context[$key]!==$selectedContext[$key])self::invalid();
        if(!in_array($context['shop_key'],['digital','personalized_pod'],true)
            ||!is_string($context['environment'])||Validator::environment($context['environment'])!==$context['environment']
            ||$context['source_contract_sha256']!==EtsyFinancialSourceParser::CONTRACT_SHA256||$asOf<1)self::invalid();
        foreach(['business_id','store_id','external_shop_id','external_account_id','integration_id']as$key)if(!is_int($context[$key])||$context[$key]<1)self::invalid();
        if(!array_is_list($documents)||count($documents)<1||count($documents)>self::MAX_DOCUMENTS)self::invalid();
        $scope=array_intersect_key($context,array_flip(['shop_key','environment','business_id','store_id','external_shop_id']));
        $bytes=0;$seen=[];$identities=[];$shared=0;$missing=0;$staged=[];
        foreach($documents as $document){
            if(!is_array($document))self::invalid();self::exact($document,['native_schema','raw','capture']);
            if(!is_string($document['raw'])||!is_string($document['native_schema'])||!is_array($document['capture']))self::invalid();
            $bytes+=strlen($document['raw']);if($bytes>self::MAX_BYTES)self::invalid();
            $observation=EtsyFinancialSourceParser::parse($document['native_schema'],$document['raw'],$document['capture'],$scope);
            if(strtotime($observation['capture']['observed_at'])>$asOf)self::invalid();
            $key=$observation['observation_key'];if(isset($seen[$key]))self::invalid();$seen[$key]=true;
            if($observation['source_identity_hashes']===[])++$missing;
            foreach($observation['source_identity_hashes']as$identity=>$hash){
                if(isset($identities[$identity])){if(!hash_equals($identities[$identity],$hash))self::invalid();++$shared;}
                $identities[$identity]=$hash;
            }
            $staged[]=['native_schema'=>$document['native_schema'],'raw'=>$document['raw'],'observation'=>$observation];
        }
        ksort($identities,SORT_STRING);
        $blockers=['UNVERIFIED_PROVIDER_OWNERSHIP','MISSING_REVIEWED_CATEGORY_AND_PRECISION_RULE',
            'UNVERIFIED_RECEIPT_ORDER_ALLOCATION','UNKNOWN_SOURCE_COMPLETENESS_AND_FINALITY',
            'NO_DURABLE_NATIVE_SOURCE_STORE','PAYMENT_LEDGER_REPRESENTATIONS_NOT_ADDITIVE'];
        if($missing>0)$blockers[]='MISSING_STABLE_TRANSACTION_ID';
        $packet=['schema'=>'digiforge-etsy-native-stage-v1','state'=>'SCHEMA_VALIDATED_UNVERIFIED_SOURCE',
            'context'=>$context,'as_of'=>$asOf,'documents'=>$staged,'identity_hashes'=>$identities,
            'shared_identity_references'=>$shared,'documents_without_transaction_identity'=>$missing,
            'mapping_blockers'=>$blockers,'authenticity'=>'UNKNOWN','ownership'=>'UNKNOWN','settlement'=>'UNKNOWN',
            'source_completeness'=>'UNKNOWN','finance_declarations'=>[],'persistence_performed'=>false,
            'durable_replay_protection'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false,
            'accounting_import_authorized'=>false];
        $packet['stage_hash']=Validator::hash($packet);return $packet;
    }

    /** Fixed redacted projection; neither raw source nor provider/customer text is displayed. */
    public static function projection(array $packet):array
    {
        try{
            if(!is_array($packet['documents']??null)||!is_array($packet['context']??null)||!is_int($packet['as_of']??null))self::invalid();
            $documents=[];
            foreach($packet['documents']as$document){
                if(!is_array($document)||!isset($document['native_schema'],$document['raw'],$document['observation']['capture']))self::invalid();
                $documents[]=['native_schema'=>$document['native_schema'],'raw'=>$document['raw'],'capture'=>$document['observation']['capture']];
            }
            $rebuilt=self::preview($documents,$packet['context'],$packet['context'],$packet['as_of']);
            if(Validator::canonicalJson($packet)!==Validator::canonicalJson($rebuilt))self::invalid();
            $rows=[];
            foreach($rebuilt['documents']as$document){
                $observation=$document['observation'];
                $rows[]=['native_schema'=>$document['native_schema'],'raw_source_sha256'=>$observation['raw_source_sha256'],
                    'canonical_source_sha256'=>$observation['canonical_source_sha256'],'observation_key'=>$observation['observation_key'],
                    'source_identity_keys'=>$observation['source_identity_keys'],'observed_at'=>$observation['capture']['observed_at']];
            }
            return array_intersect_key($rebuilt,array_flip(['schema','state','context','stage_hash','shared_identity_references',
                'documents_without_transaction_identity','mapping_blockers','authenticity','ownership','settlement','source_completeness',
                'persistence_performed','durable_replay_protection','period_approval_authorized','external_execution_authorized','accounting_import_authorized']))+['sources'=>$rows];
        }catch(\TypeError|\ErrorException){self::invalid();}
    }

    private static function exact(array $input,array $fields):void{$keys=array_keys($input);sort($keys);sort($fields);if($keys!==$fields)self::invalid();}
    private static function invalid():never{throw new \InvalidArgumentException('Exact bounded unverified native-source staging evidence is required.');}
}
