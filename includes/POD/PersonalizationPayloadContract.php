<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Revalidate approved stored values; approval flags never replace typed schema evidence. */
final class PersonalizationPayloadContract
{
 public static function validate(array $definitions,array $payload):void
 {
  if(!array_is_list($definitions)||$definitions===[]||count($definitions)>100||count($payload)>100)throw new \InvalidArgumentException('Bounded typed field-definition list required.');
  $seen=[];
  foreach($definitions as $field){
   if(!is_array($field)||!is_string($field['key']??null)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$field['key'])||isset($seen[$field['key']])||!in_array($field['type']??null,['text','choice','date','number','image_reference'],true))throw new \InvalidArgumentException('Exact unique typed personalization fields required.');
   if(array_diff(array_keys($field),['key','type','required','min_length','max_length','minimum','maximum','choices','options','format','label','description','help_text','placeholder','answer_key'])!==[])throw new \InvalidArgumentException('Unsupported schema constraint cannot be silently ignored.');
   if(array_key_exists('required',$field)&&!is_bool($field['required']))throw new \InvalidArgumentException('Required flag must be boolean.');
   $key=$field['key'];$seen[$key]=true;$type=$field['type'];$min=array_key_exists('min_length',$field)?$field['min_length']:0;$max=array_key_exists('max_length',$field)?$field['max_length']:10000;if($max===0)$max=10000;
   if(!is_int($min)||!is_int($max)||$min<0||$max<1||$min>$max||$max>10000)throw new \InvalidArgumentException('Exact bounded text length constraints required.');
   if(array_key_exists('format',$field)&&!(($type==='date'&&$field['format']==='date')||($type==='text'&&$field['format']==='plain_text')))throw new \InvalidArgumentException('Unsupported field format.');
   foreach(['minimum','maximum']as $bound)if(array_key_exists($bound,$field)&&(!is_int($field[$bound])&&!is_float($field[$bound])||!is_finite((float)$field[$bound])))throw new \InvalidArgumentException('Finite numeric bounds required.');
   if(isset($field['minimum'],$field['maximum'])&&$field['minimum']>$field['maximum'])throw new \InvalidArgumentException('Numeric bounds conflict.');
   if($type!=='number'&&(array_key_exists('minimum',$field)||array_key_exists('maximum',$field)))throw new \InvalidArgumentException('Numeric constraints require a number field.');
   if($type==='number'&&(array_key_exists('min_length',$field)||array_key_exists('max_length',$field)))throw new \InvalidArgumentException('Text length constraints cannot be applied to numbers.');
   if(array_key_exists('answer_key',$field)&&(!is_string($field['answer_key'])||!preg_match('/^question_[0-9]+$/D',$field['answer_key'])))throw new \InvalidArgumentException('Exact reviewed provider answer key required.');
   $choices=null;if($type==='choice'){$choices=$field['choices']??$field['options']??null;if((array_key_exists('choices',$field)&&array_key_exists('options',$field))||!is_array($choices)||!array_is_list($choices)||$choices===[]||count($choices)>100)throw new \InvalidArgumentException('Exact bounded choice options required.');foreach($choices as $choice)if(!is_string($choice)||$choice===''||strlen($choice)>4096||preg_match('//u',$choice)!==1)throw new \InvalidArgumentException('Choice options must be valid bounded text.');if(count(array_unique($choices))!==count($choices))throw new \InvalidArgumentException('Choice options must be unique.');}
   elseif(array_key_exists('choices',$field)||array_key_exists('options',$field))throw new \InvalidArgumentException('Choice constraints require a choice field.');
   if(!array_key_exists($key,$payload)){if($field['required']??false)throw new \InvalidArgumentException('Required personalization field missing.');continue;}
   $value=$payload[$key];if(($field['required']??false)&&($value===null||$value===''))throw new \InvalidArgumentException('Required personalization value missing.');
   if($type==='number'){if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||(isset($field['minimum'])&&$value<$field['minimum'])||(isset($field['maximum'])&&$value>$field['maximum']))throw new \InvalidArgumentException('Numeric personalization violates approved type/bounds.');continue;}
   if(!is_string($value)||strlen($value)>10000||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value))throw new \InvalidArgumentException('Bounded valid personalization text required.');
   $length=preg_match_all('/./us',$value);if($length===false||$length<$min||$length>$max)throw new \InvalidArgumentException('Personalization violates approved character bounds.');
   if($type==='choice'&&!in_array($value,$choices,true))throw new \InvalidArgumentException('Personalization is not an approved choice.');
   if($type==='date'){$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));if($date===false||$date->format('Y-m-d')!==$value)throw new \InvalidArgumentException('Exact valid ISO date required.');}
   if($type==='image_reference'&&(!preg_match('~^digiforge/product-factory/[a-zA-Z0-9_./-]+$~D',$value)||array_intersect(explode('/',$value),['.','..',''])!==[]))throw new \InvalidArgumentException('Protected local source reference required; remote/traversal references are unsupported.');
  }
  if(array_diff(array_keys($payload),array_keys($seen))!==[])throw new \InvalidArgumentException('Undeclared personalization values cannot be certified.');
 }
}
