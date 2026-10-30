<?php
declare(strict_types=1);
namespace DigiForge\Finance;

/** CSV syntax inspection only. Caller-declared layouts/populations are never provider schemas or accounting authority. */
final class CsvSourceStage
{
    public const SCHEMA='digiforge-finance-csv-structure-v1';
    public const MAX_ROWS=500;
    public const MAX_COLUMNS=64;
    public const MAX_FIELD_BYTES=4096;

    public static function preview(string $provider,array $documents,array $context,array $selected,int $asOf):array
    {
        if(!in_array($provider,['etsy','printify','gelato','openai'],true)||$asOf<1)self::invalid();
        EtsyNativeObservationStage::assertContext($context,$selected,hash('sha256',self::SCHEMA));
        if(!array_is_list($documents)||$documents===[]||count($documents)>EtsyNativeObservationStage::MAX_DOCUMENTS)self::invalid();
        $sources=[];$seen=[];$rowsSeen=[];$bytes=0;$physical=0;$duplicates=0;$declared=0;$unknown=false;$conflict=false;
        foreach($documents as$document){
            if(!is_array($document))self::invalid();self::exact($document,['raw','layout','declared_rows','capture']);
            $raw=$document['raw'];$layout=$document['layout'];$capture=$document['capture'];$expected=$document['declared_rows'];
            if(!is_string($raw)||$raw===''||strlen($raw)>Validator::MAX_BODY_BYTES||preg_match('//u',$raw)!==1
                ||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$raw)!==0||!is_array($layout)||!is_array($capture))self::invalid();
            $bytes+=strlen($raw);if($bytes>EtsyNativeObservationStage::MAX_BYTES)self::invalid();
            $hash=hash('sha256',$raw);if(isset($seen[$hash]))self::invalid();$seen[$hash]=true;
            self::exact($layout,['delimiter','headers']);
            if(!in_array($layout['delimiter'],[',',';',"\t"],true)||!is_array($layout['headers'])||!array_is_list($layout['headers'])
                ||$layout['headers']===[]||count($layout['headers'])>self::MAX_COLUMNS)self::invalid();
            $headers=$layout['headers'];$unique=[];
            foreach($headers as$header){
                if(!is_string($header)||trim($header)===''||strlen($header)>256||preg_match('//u',$header)!==1||preg_match('/[\x00-\x1F\x7F]/',$header)!==0||isset($unique[$header]))self::invalid();
                $unique[$header]=true;
            }
            $scope=array_intersect_key($context,array_flip(['shop_key','environment','business_id','store_id','external_shop_id']));
            self::exact($capture,array_merge(array_keys($scope),['source_sha256','observed_at','source_reference']));
            foreach($scope as$key=>$value)if($capture[$key]!==$value)self::invalid();
            if($capture['source_sha256']!==$hash||!is_string($capture['source_reference'])
                ||preg_match('#^local://[A-Za-z0-9][A-Za-z0-9/_.:-]{0,230}$#D',$capture['source_reference'])!==1)self::invalid();
            foreach(explode('/',substr($capture['source_reference'],8))as$part)if(in_array($part,['.','..'],true))self::invalid();
            DeclarationContract::timestamp($capture['observed_at']);if(strtotime($capture['observed_at'])>$asOf)self::invalid();
            if($expected!==null&&(!is_int($expected)||$expected<0||$expected>2147483647))self::invalid();
            $bom=str_starts_with($raw,"\xef\xbb\xbf");$table=self::rows($bom?substr($raw,3):$raw,$layout['delimiter']);
            if(array_shift($table)!==$headers)self::invalid();
            $physical+=count($table);if($physical>self::MAX_ROWS)self::invalid();
            $dupes=0;$formula=0;
            foreach($table as$row){
                if(count($row)!==count($headers))self::invalid();
                $rowHash=self::digest([$headers,$row]);if(isset($rowsSeen[$rowHash]))++$dupes;$rowsSeen[$rowHash]=true;
                foreach($row as$cell)if(preg_match('/^[\x09\x20]*[=+@-]/u',$cell)===1)++$formula;
            }
            $duplicates+=$dupes;$control=$expected===null?'UNKNOWN':($expected===count($table)?'DECLARED_COUNTS_MATCH':'DECLARED_COUNTS_CONFLICT');
            $unknown=$unknown||$expected===null;$conflict=$conflict||$control==='DECLARED_COUNTS_CONFLICT';$declared+=$expected??0;
            $sources[]=['native_schema'=>'CSV_STRUCTURE_ONLY','observed_at'=>$capture['observed_at'],'raw_source_sha256'=>$hash,
                'canonical_source_sha256'=>self::digest([$headers,$table]),'layout_sha256'=>self::digest(['delimiter'=>$layout['delimiter'],'headers'=>$headers]),'column_count'=>count($headers),
                'data_rows'=>count($table),'declared_rows'=>$expected,'row_count_control'=>$control,'duplicate_rows'=>$dupes,
                'formula_cells'=>$formula,'utf8_bom_present'=>$bom];
        }
        $packet=['schema'=>self::SCHEMA,'state'=>'STRUCTURE_VALID_PROVIDER_FORMAT_BLOCKED','provider'=>$provider,'context'=>$context,
            'population'=>['state'=>$conflict?'DECLARED_COUNTS_CONFLICT':($duplicates>0?'DUPLICATE_ROWS_REQUIRE_REVIEW':($unknown?'UNKNOWN':'DECLARED_COUNTS_MATCH')),
                'physical_rows'=>$physical,'declared_rows'=>$unknown?null:$declared,'duplicate_rows'=>$duplicates],
            'sources'=>$sources,'shared_identity_references'=>0,'documents_without_transaction_identity'=>count($documents),
            'mapping_blockers'=>['PROVIDER_CSV_SCHEMA_UNVERIFIED','MISSING_NATIVE_IDENTITY_UNITS_AND_CATEGORY_RULES','UNKNOWN_SOURCE_COMPLETENESS_AND_FINALITY'],
            'authenticity'=>'UNKNOWN','ownership'=>'UNKNOWN','settlement'=>'UNKNOWN','source_completeness'=>'UNKNOWN','provider_schema'=>'UNKNOWN',
            'persistence_performed'=>false,'durable_replay_protection'=>false,'accounting_import_authorized'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false];
        $packet['stage_hash']=Validator::hash($packet);return $packet;
    }

    /** Strict quoted CSV: doubled quotes, LF/CRLF records; no permissive quote recovery or text normalization. */
    private static function rows(string $raw,string $delimiter):array
    {
        $rows=[];$row=[];$field='';$state=0;$open=false;$length=strlen($raw);
        for($i=0;$i<$length;++$i){
            $char=$raw[$i];$open=true;
            if($state===2){
                if($char==='"'){
                    if(($raw[$i+1]??'')==='"'){$field.='"';++$i;}else{$state=3;}
                }else{$field.=$char;}
                continue;
            }
            if($char===$delimiter||$char==="\n"||$char==="\r"){
                if(strlen($field)>self::MAX_FIELD_BYTES)self::invalid();$row[]=$field;$field='';$state=0;
                if(count($row)>self::MAX_COLUMNS)self::invalid();
                if($char===$delimiter)continue;
                if($char==="\r"){if(($raw[$i+1]??'')!=="\n")self::invalid();++$i;}
                $rows[]=$row;$row=[];$open=false;if(count($rows)>self::MAX_ROWS+1)self::invalid();continue;
            }
            if($char==='"'){if($state!==0)self::invalid();$state=2;continue;}
            if($state===3)self::invalid();$field.=$char;$state=1;
        }
        if($state===2||strlen($field)>self::MAX_FIELD_BYTES)self::invalid();
        if($open){$row[]=$field;if(count($row)>self::MAX_COLUMNS)self::invalid();$rows[]=$row;}
        if(count($rows)>self::MAX_ROWS+1)self::invalid();return $rows;
    }
    private static function digest(array $value):string{return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    private static function exact(array $value,array $expected):void{$keys=array_keys($value);sort($keys);sort($expected);if($keys!==$expected)self::invalid();}
    private static function invalid():never{throw new \InvalidArgumentException('Exact bounded unverified CSV structure and declared scope required.');}
}
