<?php
declare(strict_types=1);
namespace DigiForge\POD;

final class EtsyPersonalizationContract {
 private const TYPES=['text_input','dropdown','unlabeled_upload','labeled_upload'];
 /** @return array{personalization_questions:list<array<string,mixed>>,supports_multiple_personalization_questions:bool} */
 public static function normalize(array $questions):array {
  $count=count($questions);if($count<1||$count>5)throw new \InvalidArgumentException('Etsy personalization requires 1 to 5 questions');
  $out=[];$uploads=0;
  foreach($questions as $q){
   if(!is_array($q))throw new \InvalidArgumentException('Personalization question must be structured');
   $type=(string)($q['question_type']??'');if(!in_array($type,self::TYPES,true))throw new \InvalidArgumentException('Unsupported Etsy personalization question type');
   $text=trim((string)($q['question_text']??''));if(strlen($text)<1||strlen($text)>45||!preg_match('/^[A-Za-z0-9]/',$text))throw new \InvalidArgumentException('Invalid Etsy personalization question text');
   $item=['question_type'=>$type,'question_text'=>$text,'required'=>(bool)($q['required']??false)];
   if(isset($q['question_id'])&&(int)$q['question_id']>0)$item['question_id']=(int)$q['question_id'];
   $instructions=trim((string)($q['instructions']??''));if(strlen($instructions)>120)throw new \InvalidArgumentException('Etsy personalization instructions exceed 120 characters');
   if($type==='dropdown'&&$instructions!=='')throw new \InvalidArgumentException('Dropdown instructions must be empty');
   if($instructions!=='')$item['instructions']=$instructions;
   if($type==='text_input'){
    $max=(int)($q['max_allowed_characters']??0);if($max<1||$max>1024)throw new \InvalidArgumentException('Text personalization character limit must be 1 to 1024');$item['max_allowed_characters']=$max;
    if(array_key_exists('add_on_price',$q)){$price=$q['add_on_price'];if($price!==null&&(float)$price!==0.0){if($item['required'])throw new \InvalidArgumentException('Add-on price requires optional text personalization');$price=(float)$price;if($price<0.20||$price>500.0)throw new \InvalidArgumentException('Personalization add-on price is outside Etsy bounds');$item['add_on_price']=$price;}else{$item['add_on_price']=$price;}}
   }
   if(in_array($type,['unlabeled_upload','labeled_upload'],true)){
    $uploads++;$max=(int)($q['max_allowed_files']??0);if($max<1||$max>10)throw new \InvalidArgumentException('Upload count must be 1 to 10');$item['max_allowed_files']=$max;
   }
   if(in_array($type,['dropdown','labeled_upload'],true)){
    $opts=array_values((array)($q['options']??[]));if($type==='dropdown'&&(count($opts)<1||count($opts)>30))throw new \InvalidArgumentException('Dropdown requires 1 to 30 options');
    if($type==='labeled_upload'&&count($opts)!==(int)$item['max_allowed_files'])throw new \InvalidArgumentException('Labeled upload options must match file count');
    $labels=[];foreach($opts as $o){$label=trim((string)(is_array($o)?($o['label']??''):$o));$limit=$type==='dropdown'?20:45;if($label===''||strlen($label)>$limit||isset($labels[$label]))throw new \InvalidArgumentException('Invalid or duplicate personalization option');$labels[$label]=true;$item['options'][]=['label'=>$label];}
   }
   $out[]=$item;
  }
  if($uploads>1)throw new \InvalidArgumentException('Etsy allows at most one upload personalization question');
  return ['personalization_questions'=>$out,'supports_multiple_personalization_questions'=>true];
 }
}