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
        $normalized=[];
        foreach($contract['personalization_questions'] as $index=>$question){
            $answerKey=isset($question['question_id'])?'question_'.(int)$question['question_id']:'question_'.($index+1);
            $required=(bool)($question['required']??false);$value=$answers[$answerKey]??null;
            if(($value===null||$value===''||$value===[])&&$required)return new \WP_Error('personalization_answer_required','Required personalization answer is missing.');
            if($value===null||$value===''||$value===[])continue;
            $type=(string)$question['question_type'];
            if($type==='text_input'){
                if(!is_string($value))return new \WP_Error('personalization_answer_invalid','Text personalization answer must be text.');
                $value=trim($value);$max=(int)($question['max_allowed_characters']??1024);
                if($value===''||strlen($value)>$max)return new \WP_Error('personalization_answer_invalid','Text personalization answer is outside the allowed length.');
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
        $payload=['schema_version'=>1,'answers'=>$normalized];
        $json=wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $payload['canonical_hash']=hash('sha256',(string)$json);
        return $payload;
    }
}
