<?php
declare(strict_types=1);

namespace DigiForge\Finance;

/** Pure source observations only. Never a declaration, ownership grant or import dispatcher. */
final class EtsyFinancialSourceParser
{
    public const CONTRACT_SHA256='82e24b1123b1c3d3853d2f2734fa59d30a9595a36a46be6994306311f24ee91c';
    public const SOURCE_SPEC_SHA256='9f0fe8cf1352a444b6069ec04699809bcde41227cf16764e76a98d13e7eb7797';
    private const SCOPE=['shop_key','environment','business_id','store_id','external_shop_id'];
    private const IDENTITIES=['Payment'=>['payment_id'],'PaymentAccountLedgerEntry'=>['ledger_id','entry_id'],
        'PaymentAdjustment'=>['payment_id','payment_adjustment_id'],'PaymentAdjustmentItem'=>['payment_adjustment_id','payment_adjustment_item_id']];

    /** Both scopes are caller assertions, not proof of actual provider ownership. No persistence. */
    public static function parse(string $nativeSchema,string $raw,array $capture,array $selectedScope):array
    {
        try {
            self::scope($capture,$selectedScope);
            if(strlen($raw)<2||strlen($raw)>Validator::MAX_BODY_BYTES||!hash_equals($capture['source_sha256'],hash('sha256',$raw)))self::invalid();
            $decoded=json_decode($raw,false,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
            self::uniqueJsonMembers($raw);
            $bytes=@file_get_contents(__DIR__.'/contracts/etsy-openapi-finance-3.0.0.json',false,null,0,65537);
            if(!is_string($bytes)||!hash_equals(self::CONTRACT_SHA256,hash('sha256',$bytes)))self::invalid();
            $contract=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
            $schemas=$contract['components']['schemas'];
            if(!isset($schemas[$nativeSchema])||($contract['provenance']['full_snapshot_sha256']??null)!==self::SOURCE_SPEC_SHA256)self::invalid();
            $identities=[];
            $native=self::validate($decoded,['$ref'=>'#/components/schemas/'.$nativeSchema],$schemas,$selectedScope,strtotime($capture['observed_at']),$identities);
            Validator::structured($native);
            $canonical=Validator::hash($native);
            $identities=array_keys($identities);sort($identities,SORT_STRING);
            return ['schema'=>'digiforge-etsy-native-observation-v1','native_schema'=>$nativeSchema,'native'=>$native,
                'scope'=>$selectedScope,'capture'=>$capture,'raw_source_sha256'=>hash('sha256',$raw),'canonical_source_sha256'=>$canonical,
                'source_contract_sha256'=>self::CONTRACT_SHA256,'openapi_sha256'=>self::SOURCE_SPEC_SHA256,'source_identity_keys'=>$identities,
                'observation_key'=>Validator::hash(['schema'=>$nativeSchema,'scope'=>$selectedScope,'content'=>$canonical]),
                'evidence_class'=>'SCHEMA_VALIDATED_UNVERIFIED_SOURCE','authenticity'=>'UNKNOWN','ownership_verified'=>false,
                'settlement'=>'UNKNOWN','source_completeness'=>'UNKNOWN','finance_declarations'=>[],
                'accounting_policy'=>'NO_AUTOMATIC_LEDGER_PAYMENT_SUM','replay_protection'=>'CONTENT_ADDRESSED_ONLY_NOT_DURABLE_IMPORT',
                'period_approval_authorized'=>false,'external_execution_authorized'=>false,'persistence_performed'=>false];
        } catch(\JsonException|\InvalidArgumentException) {self::invalid();}
    }

    private static function scope(array $capture,array $scope):void
    {
        self::exact($scope,self::SCOPE);self::exact($capture,array_merge(self::SCOPE,['source_sha256','observed_at','source_reference']));
        foreach(self::SCOPE as $key)if($capture[$key]!==$scope[$key])self::invalid();
        if(!in_array($scope['shop_key'],['digital','personalized_pod'],true)||!is_string($scope['environment'])
            ||Validator::environment($scope['environment'])!==$scope['environment'])self::invalid();
        foreach(['business_id','store_id','external_shop_id'] as $key)if(!is_int($scope[$key])||$scope[$key]<1)self::invalid();
        if(!is_string($capture['source_sha256'])||preg_match('/^[a-f0-9]{64}$/D',$capture['source_sha256'])!==1)self::invalid();
        DeclarationContract::timestamp($capture['observed_at']);
        $ref=$capture['source_reference'];
        if(!is_string($ref)||preg_match('#^local://[A-Za-z0-9][A-Za-z0-9/_.:-]{0,230}$#D',$ref)!==1)self::invalid();
        foreach(explode('/',substr($ref,8)) as $part)if($part==='.'||$part==='..')self::invalid();
    }

    /** Supports only the eight pinned object schemas and their single-reference alternatives. */
    private static function validate(mixed $value,array $rule,array $schemas,array $scope,int $observed,array &$identities):mixed
    {
        if($value===null&&($rule['nullable']??false)===true)return null;
        if(isset($rule['oneOf'])) {
            if(count($rule['oneOf'])!==1)self::invalid();
            return self::validate($value,$rule['oneOf'][0],$schemas,$scope,$observed,$identities);
        }
        if(isset($rule['$ref'])) {
            $prefix='#/components/schemas/';$ref=$rule['$ref'];
            if(!is_string($ref)||!str_starts_with($ref,$prefix))self::invalid();
            $name=substr($ref,strlen($prefix));if(!isset($schemas[$name]))self::invalid();
            $result=self::validate($value,$schemas[$name],$schemas,$scope,$observed,$identities);
            self::relationships($name,$result,$scope,$identities);
            return $result;
        }
        switch($rule['type']??'') {
            case 'object':
                if(!$value instanceof \stdClass)self::invalid();
                $values=get_object_vars($value);$properties=$rule['properties']??[];
                if(array_diff(array_keys($values),array_keys($properties))!==[])self::invalid();
                $result=[];
                foreach($values as $key=>$item) {
                    $result[$key]=self::validate($item,$properties[$key],$schemas,$scope,$observed,$identities);
                    if(is_int($result[$key])&&($key==='create_date'||str_ends_with($key,'timestamp'))&&$result[$key]>$observed)self::invalid();
                    if($result[$key]!==null&&in_array($key,['currency','currency_code','shop_currency','buyer_currency'],true)
                        &&preg_match('/^[A-Z]{3}$/D',$result[$key])!==1)self::invalid();
                }
                return $result;
            case 'array':
                if(!is_array($value)||!array_is_list($value)||count($value)>100)self::invalid();
                $result=[];$seen=[];
                foreach($value as $item) {
                    $normalized=self::validate($item,$rule['items'],$schemas,$scope,$observed,$identities);
                    $key=Validator::hash($normalized);if(isset($seen[$key]))self::invalid();$seen[$key]=true;
                    $result[]=$normalized;
                }
                return $result;
            case 'integer':
                if(!is_int($value)||(isset($rule['minimum'])&&$value<$rule['minimum'])
                    ||(isset($rule['maximum'])&&$value>$rule['maximum']))self::invalid();
                return $value;
            case 'string':
                if(!is_string($value)||strlen($value)>4096)self::invalid();
                return $value;
            case 'boolean':
                if(!is_bool($value))self::invalid();
                return $value;
            default:self::invalid();
        }
    }

    private static function relationships(string $schema,array $data,array $scope,array &$identities):void
    {
        // Required operational profile fields are stricter than the upstream optional-property schema.
        foreach(self::IDENTITIES[$schema]??[] as $key)if(!isset($data[$key]))self::invalid();
        if(isset(self::IDENTITIES[$schema])) {
            $id=[];foreach(self::IDENTITIES[$schema] as $key)$id[$key]=$data[$key];
            $identity=Validator::hash(['scope'=>$scope,'type'=>$schema,'id'=>$id]);
            $content=Validator::hash($data);
            if(isset($identities[$identity])&&$identities[$identity]!==$content)self::invalid();
            $identities[$identity]=$content;
        }
        if($schema==='Money') {
            if(!isset($data['amount'],$data['divisor'],$data['currency_code'])||$data['divisor']<1
                ||preg_match('/^[A-Z]{3}$/D',$data['currency_code'])!==1)self::invalid();
            // Keep the exact signed integer/divisor; even thirds are not rounded into declarations.
        }
        if($schema==='Payment'&&($data['shop_id']??null)!==$scope['external_shop_id'])self::invalid();
        foreach([['create_date','created_timestamp'],['create_timestamp','created_timestamp'],['update_timestamp','updated_timestamp']] as [$a,$b]) {
            if(isset($data[$a],$data[$b])&&$data[$a]!==$data[$b])self::invalid();
        }
        $created=$data['created_timestamp']??$data['create_timestamp']??$data['create_date']??null;
        $updated=$data['updated_timestamp']??$data['update_timestamp']??null;
        if($created!==null&&$updated!==null&&$updated<$created)self::invalid();
        foreach($data['payment_adjustments']??[] as $child) {
            if($schema==='Payment'&&$child['payment_id']!==$data['payment_id'])self::invalid();
        }
        foreach($data['payment_adjustment_items']??[] as $child) {
            if($child['payment_adjustment_id']!==$data['payment_adjustment_id'])self::invalid();
        }
        if(in_array($schema,['Payments','PaymentAccountLedgerEntries'],true)) {
            if(!isset($data['count'],$data['results'])||$data['count']<count($data['results']))self::invalid();
        }
        if($schema==='Payment')foreach(['amount','posted','adjusted'] as $prefix) {
            $g=$data[$prefix.'_gross']??null;$f=$data[$prefix.'_fees']??null;$n=$data[$prefix.'_net']??null;
            if($g===null||$f===null||$n===null)continue;
            if($g['currency_code']!==$f['currency_code']||$g['currency_code']!==$n['currency_code']
                ||$g['divisor']!==$f['divisor']||$g['divisor']!==$n['divisor'])continue;
            $difference=ExactDecimal::add((string)$g['amount'].'.0000',ExactDecimal::negate((string)$f['amount'].'.0000'));
            if($difference!==ExactDecimal::add((string)$n['amount'].'.0000','0.0000'))self::invalid();
        }
    }

    /** json_decode validates syntax first; this bounded pass rejects duplicate decoded member names. */
    private static function uniqueJsonMembers(string $raw):void
    {
        $frames=[];$length=strlen($raw);
        for($i=0;$i<$length;++$i) {
            $char=$raw[$i];
            if($char==='{'||$char==='['){$frames[]=['object'=>$char==='{','key'=>$char==='{','seen'=>[]];continue;}
            if($char==='}'||$char===']'){array_pop($frames);continue;}
            $top=count($frames)-1;
            if($top<0)continue;
            if($char===','){$frames[$top]['key']=$frames[$top]['object'];continue;}
            if($char===':'){$frames[$top]['key']=false;continue;}
            if($char!=='"')continue;
            $start=$i;++$i;
            while($i<$length){if($raw[$i]==='\\'){$i+=2;continue;}if($raw[$i]==='"')break;++$i;}
            if($frames[$top]['object']&&$frames[$top]['key']) {
                $key=json_decode(substr($raw,$start,$i-$start+1),true,4,JSON_THROW_ON_ERROR);
                if(isset($frames[$top]['seen'][$key]))self::invalid();
                $frames[$top]['seen'][$key]=true;
            }
        }
    }

    private static function exact(array $input,array $expected):void
    {
        $keys=array_keys($input);sort($keys);sort($expected);if($keys!==$expected)self::invalid();
    }
    private static function invalid():never {throw new \InvalidArgumentException('Unsupported or inconsistent Etsy financial source.');}
}
