<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\AI\ProviderEvidenceRepository;
use DigiForge\Portal\AiEvidenceWorkflow;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
final class AiEvidenceController
{
    public function register():void
    {
        add_action('rest_api_init',function():void{
            register_rest_route('digiforge/v1','/ai/evidence/(?P<operation>quotes|tariffs|charges|attributions)',['methods'=>'POST','permission_callback'=>[$this,'canReview'],'callback'=>[$this,'import']]);
            register_rest_route('digiforge/v1','/ai/evidence/usage/(?P<id>\d+)',['methods'=>'GET','permission_callback'=>[$this,'canReview'],'callback'=>[$this,'snapshot']]);
        });
    }
    public function canReview():bool{return current_user_can('manage_digiforge_ai')&&get_current_user_id()>0;}
    public function import(WP_REST_Request $request):WP_REST_Response|WP_Error
    {
        if(strlen($request->get_body())>65536)return new WP_Error('ai_review_payload_too_large','Evidence body exceeds 64 KiB.',['status'=>413]);$p=$request->get_json_params();if(!is_array($p))return new WP_Error('ai_review_json_required','JSON object required.',['status'=>400]);
        $p['operation']=['tariffs'=>'tariff','quotes'=>'quote','charges'=>'charge','attributions'=>'attribution'][(string)$request['operation']]??'';$result=(new AiEvidenceWorkflow())->submit($p);return $result instanceof WP_Error?$result:new WP_REST_Response($result);
    }
    public function snapshot(WP_REST_Request $request):WP_REST_Response|WP_Error
    {
        if(!$this->canReview())return new WP_Error('ai_review_forbidden','AI evidence reviewer required.',['status'=>403]);$shop=$request['shop_key'];if(!is_string($shop)||!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true))return new WP_Error('ai_review_shop_required','Exact concrete shop required.',['status'=>400]);
        $result=(new ProviderEvidenceRepository())->snapshot((int)$request['id'],$shop);return $result instanceof WP_Error?$result:new WP_REST_Response($result);
    }
}
