<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** Pure read model for persisted digital download/QA checks. */
final class QaAdminSummary {
    /** @param array<int,mixed> $rows @return array<string,mixed> */
    public static function summarize(array $rows): array {
        $results=[];$reviews=[];$invalid=0;$attention=0;
        foreach($rows as $row){
            if(!is_array($row)){ $invalid++; continue; }
            $result=strtoupper(trim(is_string($row['validation_result']??null)?$row['validation_result']:''));
            $review=strtoupper(trim(is_string($row['review_status']??null)?$row['review_status']:''));
            if(!in_array($result,Validator::RESULTS,true)||!in_array($review,Validator::REVIEWS,true)){ $invalid++; continue; }
            $results[$result]=($results[$result]??0)+1;
            $reviews[$review]=($reviews[$review]??0)+1;
            if(in_array($result,['FAIL','WARNING'],true)||in_array($review,['REVIEW_REQUIRED','REJECTED'],true)){ $attention++; }
        }
        ksort($results);ksort($reviews);
        return ['total'=>count($rows),'validation_results'=>$results,'review_statuses'=>$reviews,'invalid'=>$invalid,'attention'=>$attention,'readiness_inferred'=>false,'external_actions_performed'=>false];
    }
}
