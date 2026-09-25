<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** Pure read model for persisted digital download/QA checks. */
final class QaAdminSummary {
 /** @param array<int,mixed> $rows @return array<string,mixed> */
 public static function summarize(array $rows):array{
  $results=[];$reviews=[];$invalid=0;$attention=[];
  foreach($rows as $index=>$row){
   if(!is_array($row)){ $invalid++;$attention[]=['index'=>(int)$index,'id'=>0,'digital_product_id'=>0,'validation_result'=>'INVALID','review_status'=>'INVALID','reason'=>'INVALID_EVIDENCE'];continue; }
   $result=strtoupper(trim(is_string($row['validation_result']??null)?$row['validation_result']:''));
   $review=strtoupper(trim(is_string($row['review_status']??null)?$row['review_status']:''));
   if(!in_array($result,Validator::RESULTS,true)||!in_array($review,Validator::REVIEWS,true)){ $invalid++;$attention[]=['index'=>(int)$index,'id'=>self::id($row['id']??null),'digital_product_id'=>self::id($row['digital_product_id']??null),'validation_result'=>'INVALID','review_status'=>'INVALID','reason'=>'INVALID_EVIDENCE'];continue; }
   $results[$result]=($results[$result]??0)+1;$reviews[$review]=($reviews[$review]??0)+1;
   if(in_array($result,['FAIL','WARNING'],true)||in_array($review,['REVIEW_REQUIRED','REJECTED'],true)){ $attention[]=['index'=>(int)$index,'id'=>self::id($row['id']??null),'digital_product_id'=>self::id($row['digital_product_id']??null),'validation_result'=>$result,'review_status'=>$review,'reason'=>'QA_ATTENTION']; }
  }
  ksort($results);ksort($reviews);
  return ['total'=>count($rows),'validation_results'=>$results,'review_statuses'=>$reviews,'invalid'=>$invalid,'attention_total'=>count($attention),'attention'=>$attention,'readiness_inferred'=>false,'external_actions_performed'=>false];
 }
 private static function id(mixed $value):int{
  if(is_int($value)){return $value>0?$value:0;}
  if(is_string($value)&&preg_match('/^[1-9][0-9]*$/',$value)===1){$parsed=filter_var($value,FILTER_VALIDATE_INT);return is_int($parsed)&&$parsed>0?$parsed:0;}
  return 0;
 }
}
