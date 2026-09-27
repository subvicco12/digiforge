<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Provider-independent normalized projection of Etsy typed personalization answers. */
final class PersonalizationSubmissionNormalizer
{
    /** @return array{schema_version:int,answers:list<array<string,mixed>>,canonical_hash:string}|WP_Error */
    public static function normalize(array $questions,array $answers):array|\WP_Error
    {
        $contract=EtsyPersonalizationContract::validate($questions);
        if(is_wp_error($contract))return $contract;
        $normalized=[];
        foreach($contract['questions'] as $question){
            $key=(string)$question['key'];$required=(bool)($question['required']??false);
            $value=$answers[$key]??null;
            if(($value===null||$value===''||$value===[])&&$required)return new \WP_Error('personalization_answer_required','Required personalization answer is missing.');
            if($value===null||$value===''||$value===[])continue;
            $type=(string)$question['type'];
            if($type==='text_input'){
                if(!is_string($value))return new \WP_Error('personalization_answer_invalid','Text personalization answer must be text.');
                $value=trim($value);if($value===''||strlen($value)>1024)return new \WP_Error('personalization_answer_invalid','Text personalization answer is outside the allowed length.');
            }elseif($type==='dropdown'){
                if(!is_string($value)||!in_array($value,(array)($question['options']??[]),true))return new \WP_Error('personalization_answer_invalid','Dropdown personalization answer is not an allowed option.');
            }else{
                $files=is_array($value)?array_values($value):[];
                $max=(int)($question['max_files']??1);
                if($files===[]||count($files)>$max)return new \WP_Error('personalization_answer_invalid','Upload personalization answer has an invalid file count.');
                foreach($files as $file)if(!is_array($file)||trim((string)($file['provider_file_id']??''))==='')return new \WP_Error('personalization_answer_invalid','Upload evidence requires a provider file identifier.');
                $value=array_map(static fn(array $file):array=>[
                    'provider_file_id'=>sanitize_text_field((string)$file['provider_file_id']),
                    'filename'=>sanitize_file_name((string)($file['filename']??'')),
                    'mime_type'=>sanitize_mime_type((string)($file['mime_type']??'')),
                ],$files);
            }
            $normalized[]=['key'=>$key,'type'=>$type,'value'=>$value];
        }
        $payload=['schema_version'=>1,'answers'=>$normalized];
        $json=wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $payload['canonical_hash']=hash('sha256',(string)$json);
        return $payload;
    }
}
