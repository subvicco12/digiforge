<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Local draft QA gate. It produces evidence only and never authorizes publication. */
final class DraftQaGate
{
    /** @return array<string,mixed>|WP_Error */
    public static function evaluate(array $checks):array|WP_Error
    {
        $required=['title','description','seo','media','pricing','policy','personalization'];
        $normalized=[];foreach($required as $name){$status=strtoupper(trim((string)($checks[$name]??'')));if(!in_array($status,['PASS','WAIVED','FAIL'],true))return new WP_Error('digiforge_draft_qa_missing','Every required draft QA check must be PASS, WAIVED, or FAIL.',['status'=>409]);$normalized[$name]=$status;}
        $failed=array_keys(array_filter($normalized,static fn(string $status):bool=>$status==='FAIL'));
        return ['state'=>$failed===[]?'DRAFT_QA_PASSED':'DRAFT_QA_FAILED','passed'=>$failed===[],'checks'=>$normalized,'failed_checks'=>$failed,'publish_authorized'=>false,'etsy_api_invoked'=>false,'external_execution_performed'=>false];
    }
}
