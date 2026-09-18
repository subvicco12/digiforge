<?php

declare(strict_types=1);

namespace DigiForge\POD;

use WP_Error;

/**
 * Non-executing decision boundary from approved market opportunity to
 * supplier/template/personalization readiness.
 */
final class OpportunityRouting
{
    /** @return array<string,mixed>|WP_Error */
    public static function route(array $opportunity,array $supplierScores,array $template): array|WP_Error
    {
        $decision=strtoupper(trim((string)($opportunity['decision']??'')));
        if($decision!=='APPROVE_FOR_DEVELOPMENT'){
            return new WP_Error('digiforge_opportunity_not_approved','Opportunity must be APPROVE_FOR_DEVELOPMENT before supplier routing.',['status'=>409]);
        }
        $market=(float)($opportunity['market_opportunity']??-1);
        if(!is_finite($market)||$market<0||$market>100){
            return new WP_Error('digiforge_market_opportunity','A market opportunity score between 0 and 100 is required.',['status'=>400]);
        }

        $evaluated=[];
        foreach($supplierScores as $candidate){
            if(!is_array($candidate)) return new WP_Error('digiforge_supplier_candidate','Supplier candidates must be structured arrays.',['status'=>400]);
            $candidate['market_opportunity']=$market;
            try{$evaluated[]=SupplierScoring::evaluate($candidate);}catch(\InvalidArgumentException $e){
                return new WP_Error('digiforge_supplier_score_invalid',$e->getMessage(),['status'=>400]);
            }
        }
        if($evaluated===[]) return new WP_Error('digiforge_supplier_required','At least one supplier candidate is required.',['status'=>409]);

        usort($evaluated,static function(array $a,array $b):int{
            $rank=['SUPPLIER_SELECTED'=>3,'SUPPLIER_CANDIDATE'=>2,'ALTERNATIVE_PROVIDER_SEARCH'=>1,'REJECT'=>0];
            $d=($rank[$b['decision']]??-1)<=>($rank[$a['decision']]??-1);
            return $d!==0?$d:($b['score']<=>$a['score']);
        });
        $selected=$evaluated[0];
        if($selected['decision']!=='SUPPLIER_SELECTED'){
            return [
                'state'=>'SUPPLIER_REVIEW_REQUIRED',
                'supplier'=>$selected,
                'candidates'=>$evaluated,
                'template'=>null,
                'ready_for_qa'=>false,
            ];
        }

        try{$normalized=ProductionTemplateContract::normalize($template);}catch(\Throwable $e){
            return new WP_Error('digiforge_template_invalid',$e->getMessage(),['status'=>400]);
        }
        if(strtolower((string)$normalized['supplier'])!==$selected['provider']){
            return new WP_Error('digiforge_template_supplier_mismatch','Production template supplier must match the selected provider.',['status'=>409]);
        }
        if(!in_array($normalized['template_status'],['GEOMETRY_LOCKED','SAMPLE_REQUIRED','VALIDATED'],true)){
            return new WP_Error('digiforge_template_not_ready','Production template geometry must be locked before QA routing.',['status'=>409]);
        }

        return [
            'state'=>'QA_READY',
            'supplier'=>$selected,
            'candidates'=>$evaluated,
            'template'=>$normalized,
            'personalization_engine'=>$normalized['personalization_engine'],
            'personalization_pipeline'=>$normalized['personalization_pipeline'],
            'ready_for_qa'=>true,
        ];
    }
}
