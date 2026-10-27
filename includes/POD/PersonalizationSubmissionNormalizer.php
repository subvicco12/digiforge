<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Provider-independent normalized projection of Etsy typed personalization answers. */
final class PersonalizationSubmissionNormalizer
{
    /** @return array{schema_version:int,answers:list<array<string,mixed>>,canonical_hash:string}|\WP_Error */
    public static function normalize(array $questions,array $answers):array|\WP_Error
    {
        try{$contract=EtsyPersonalizationContract::normalize($questions);}
        catch(\InvalidArgumentException $e){return new \WP_Error('personalization_contract_invalid',$e->getMessage());}
        $normalized=[];$answerKeys=[];
        foreach($contract['personalization_questions'] as $index=>$question){
            $key=isset($question['question_id'])?'question_'.(int)$question['question_id']:'question_'.($index+1);
            if(isset($answerKeys[$key]))return new \WP_Error('personalization_contract_invalid','Personalization question identity must be unique.');
            $answerKeys[$key]=true;
        }
        if(array_diff(array_keys($answers),array_keys($answerKeys))!==[])return new \WP_Error('personalization_answer_invalid','Undeclared personalization answers cannot be silently discarded.');
        foreach($contract['personalization_questions'] as $index=>$question){
            $answerKey=isset($question['question_id'])?'question_'.(int)$question['question_id']:'question_'.($index+1);
            $required=(bool)($question['required']??false);$value=$answers[$answerKey]??null;
            if(($value===null||$value===''||$value===[])&&$required)return new \WP_Error('personalization_answer_required','Required personalization answer is missing.');
            if($value===null||$value===''||$value===[])continue;
            $type=(string)$question['question_type'];
            if($type==='text_input'){
                if(!is_string($value))return new \WP_Error('personalization_answer_invalid','Text personalization answer must be text.');
                $max=(int)($question['max_allowed_characters']??1024);
                if(strlen($value)>4096||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value))return new \WP_Error('personalization_answer_invalid','Bounded valid UTF-8 text without control characters is required.');
                // Never trim, normalize, truncate or case-fold buyer artwork input.
                $length=preg_match_all('/./us',$value);
                if($length===false||$length>$max)return new \WP_Error('personalization_answer_invalid','Text personalization answer is outside the allowed character length.');
                if(preg_match('/^[\s\p{Z}]*$/uD',$value)===1){
                    if($required)return new \WP_Error('personalization_answer_required','Required personalization answer is blank.');
                    continue;
                }
            }elseif($type==='dropdown'){
                $allowed=array_map(static fn(array $o):string=>(string)$o['label'],(array)($question['options']??[]));
                if(!is_string($value)||!in_array($value,$allowed,true))return new \WP_Error('personalization_answer_invalid','Dropdown personalization answer is not an allowed option.');
            }else{
                $files=is_array($value)?array_values($value):[];$max=(int)($question['max_allowed_files']??1);
                if($files===[]||count($files)>$max)return new \WP_Error('personalization_answer_invalid','Upload personalization answer has an invalid file count.');
                foreach($files as $file)if(!is_array($file)||trim((string)($file['provider_file_id']??''))==='')return new \WP_Error('personalization_answer_invalid','Upload evidence requires a provider file identifier.');
                $value=array_map(static fn(array $file):array=>[
                    'provider_file_id'=>sanitize_text_field((string)$file['provider_file_id']),
                    'filename'=>sanitize_file_name((string)($file['filename']??'')),
                    'mime_type'=>sanitize_mime_type((string)($file['mime_type']??'')),
                ],$files);
            }
            $normalized[]=['answer_key'=>$answerKey,'question_id'=>(int)($question['question_id']??0),'question_type'=>$type,'question_text'=>(string)$question['question_text'],'value'=>$value];
        }
        $payload=['schema_version'=>1,'answers'=>$normalized,'review_evidence'=>['question_count'=>count($contract['personalization_questions']),'answer_count'=>count($normalized),'required_questions_satisfied'=>true,'human_review_required'=>true,'external_execution_performed'=>false]];
        $json=wp_json_encode(['schema_version'=>$payload['schema_version'],'answers'=>$payload['answers']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json))return new \WP_Error('personalization_answer_invalid','Personalization cannot be encoded exactly.');
        $payload['canonical_hash']=hash('sha256',$json);
        return $payload;
    }
}
