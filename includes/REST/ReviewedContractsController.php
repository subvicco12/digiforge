<?php
declare(strict_types=1);
namespace DigiForge\REST;

use DigiForge\POD\ReviewedOwnershipBinding;
use DigiForge\Portal\ReviewedScopedPolicy;
use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Bounded local review surface. No provider calls, activation, settings or execution. */
final class ReviewedContractsController
{
    public function register(): void
    {
        add_action('rest_api_init', function(): void {
            register_rest_route('digiforge/v1','/reviewed-contracts/(?P<shop>digital|personalized_pod|standard_pod|jewelry)/acceptance',['methods'=>'GET','permission_callback'=>[$this,'canReadAcceptance'],'callback'=>[$this,'readAcceptance']]);
            register_rest_route('digiforge/v1','/reviewed-contracts/(?P<shop>digital|personalized_pod)/acceptance',[
                'methods'=>'POST','permission_callback'=>[$this,'canWriteAcceptance'],'callback'=>[$this,'proposeAcceptance'],
            ]);
            register_rest_route('digiforge/v1','/reviewed-contracts/(?P<shop>digital|personalized_pod)/acceptance/decision',[
                'methods'=>'POST','permission_callback'=>[$this,'canWriteAcceptance'],'callback'=>[$this,'decideAcceptance'],
            ]);
            register_rest_route('digiforge/v1','/reviewed-contracts/(?P<shop>digital|personalized_pod)/acceptance/proposal',[
                'methods'=>'GET','permission_callback'=>[$this,'canWriteAcceptance'],'callback'=>[$this,'inspectAcceptance'],
            ]);
            $route='/reviewed-contracts/(?P<shop>digital|personalized_pod|standard_pod|jewelry)/(?P<kind>policy|ownership)';
            register_rest_route('digiforge/v1',$route,[
                ['methods'=>'GET','permission_callback'=>[$this,'canRead'],'callback'=>[$this,'read']],
                ['methods'=>'POST','permission_callback'=>[$this,'canWrite'],'callback'=>[$this,'propose']],
            ]);
            register_rest_route('digiforge/v1',$route.'/decision',['methods'=>'POST','permission_callback'=>[$this,'canDecide'],'callback'=>[$this,'decide']]);
        });
    }

    public function canReadAcceptance(WP_REST_Request $request): true|WP_Error
    {
        if(get_current_user_id()<1)return self::error('authentication',401);
        $shop=$request->get_url_params()['shop']??null;
        if(!is_string($shop)||!in_array($shop,['digital','personalized_pod'],true))return self::error('scope',403);
        return ShopAccess::authorize($shop,'manage_digiforge');
    }

    public function canWriteAcceptance(WP_REST_Request $request): true|WP_Error
    {
        $access=$this->canReadAcceptance($request);
        if (is_wp_error($access)) return $access;
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'),'wp_rest')) return self::error('nonce',403);
        return ShopAccess::authorize($request->get_url_params()['shop'],'manage_digiforge_settings');
    }

    public function inspectAcceptance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $access=$this->canWriteAcceptance($request);if (is_wp_error($access)) return $access;
        $q=$this->query($request);if (is_wp_error($q)) return $q;
        if ($request->get_method()!=='GET' || $request->get_body()!=='' || !self::exact($q,['criterion','scope'])
            || !is_string($q['criterion']) || !is_string($q['scope'])) return self::error('typed',400);
        $state=\DigiForge\Portal\ReviewedAcceptanceContract::inspect($request->get_url_params()['shop'],$q['criterion'],$q['scope']);
        return is_wp_error($state)?$state:new WP_REST_Response($state,200,['Cache-Control'=>'private, no-store']);
    }

    private function acceptanceBody(WP_REST_Request $request,bool $decision): array|WP_Error
    {
        $access=$this->canWriteAcceptance($request);
        if (is_wp_error($access)) return $access;
        if ($request->get_method()!=='POST' || $this->query($request)!==[] || !$request->is_json_content_type()) return self::error('typed',400);
        $body=\DigiForge\Portal\AcceptanceReviewRequest::parse($request->get_body(),$decision);
        return $body??self::error('typed',400);
    }

    public function proposeAcceptance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body=$this->acceptanceBody($request,false);
        if (is_wp_error($body)) return $body;
        $shop=$request->get_url_params()['shop'];
        $state=\DigiForge\Portal\ReviewedAcceptanceContract::propose($shop,$body['input'],$body['effective_at'],$body['expires_at'],$body['request_id'],$body['expected_revision']);
        return is_wp_error($state)?$state:new WP_REST_Response($this->acceptanceResult($shop,$state),201,['Cache-Control'=>'private, no-store']);
    }

    public function decideAcceptance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body=$this->acceptanceBody($request,true);
        if (is_wp_error($body)) return $body;
        $shop=$request->get_url_params()['shop'];
        $state=\DigiForge\Portal\ReviewedAcceptanceContract::decide($shop,$body['criterion'],$body['scope'],$body['expected_revision'],$body['proposal_hash'],$body['decision'],$body['request_id']);
        return is_wp_error($state)?$state:new WP_REST_Response($this->acceptanceResult($shop,$state),200,['Cache-Control'=>'private, no-store']);
    }

    private function acceptanceResult(string $shop,array $state): array
    {
        return $state+['shop_key'=>$shop,'criterion'=>$state['payload']['criterion'],'scope'=>$state['payload']['scope'],
            'evidence_state'=>'UNVERIFIED','production_authorized'=>false,'supplier_approved'=>false];
    }

    public function readAcceptance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $access=$this->canReadAcceptance($request);if(is_wp_error($access))return $access;
        if($request->get_method()!=='GET'||(string)$request->get_body()!=='')return self::error('typed',400);
        $query=$this->query($request);
        if(is_wp_error($query))return $query;
        $fields=['criterion','scope'];$environment=$query['environment']??null;
        if(array_key_exists('environment',$query)){
            $fields[]='environment';
            if(!is_string($environment)||!in_array($environment,\DigiForge\Portal\ReviewedScopedPolicy::ENVIRONMENTS,true))return self::error('typed',400);
        }
        if(!self::exact($query,$fields)||!is_string($query['criterion'])||!is_string($query['scope']))return self::error('typed',400);
        $shop=$request->get_url_params()['shop'];
        $state=\DigiForge\Portal\ReviewedAcceptanceContract::status($shop,$query['criterion'],$query['scope']);
        if(is_wp_error($state))return $state;
        $resolution=['shop_key'=>$shop,'criterion'=>$query['criterion'],'scope'=>$query['scope'],'environment'=>'UNKNOWN','resolution_state'=>'UNKNOWN','matched_record_count'=>0,'references'=>[],'value_state'=>'UNKNOWN','evidence_state'=>'UNVERIFIED','read_only'=>true,'external_execution_authorized'=>false,'supplier_approved'=>false,'publishing_allowed'=>false,'ai_execution_allowed'=>false];
        if($environment!==null){
            $resolution=\DigiForge\Portal\AcceptanceEvidenceResolver::resolve($shop,$query['criterion'],$query['scope'],$environment);
            if(is_wp_error($resolution))return $resolution;
            $latest=\DigiForge\Portal\ReviewedAcceptanceContract::status($shop,$query['criterion'],$query['scope']);
            if(is_wp_error($latest))return $latest;
            if($latest!==$state)return self::error('stale',409);
        }
        $evaluation=\DigiForge\Portal\ApprovedAcceptancePolicy::unknown();
        if($environment!==null){
            $evaluation=\DigiForge\Portal\ReviewedAcceptanceContract::evaluate($shop,$query['criterion'],$query['scope'],$environment);
            if(is_wp_error($evaluation))return $evaluation;
            $latest=\DigiForge\Portal\ReviewedAcceptanceContract::status($shop,$query['criterion'],$query['scope']);
            if(is_wp_error($latest))return $latest;
            if($latest!==$state)return self::error('stale',409);
        }
        return new WP_REST_Response($state+['evidence_resolution'=>$resolution,'policy_evaluation'=>$evaluation],200);
    }

    public function canRead(WP_REST_Request $request): true|WP_Error
    {
        if (get_current_user_id()<1) { return self::error('authentication',401); }
        $identity=$this->identity($request);
        if (is_wp_error($identity)) { return $identity; }
        return ShopAccess::authorize($identity['shop'],'manage_digiforge');
    }

    public function canWrite(WP_REST_Request $request): true|WP_Error
    {
        return $this->writePermission($request,false);
    }

    public function canDecide(WP_REST_Request $request): true|WP_Error
    {
        return $this->writePermission($request,true);
    }

    public function read(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $access=$this->canRead($request);if (is_wp_error($access)) { return $access; }
        $query=$this->query($request);
        if (is_wp_error($query)) { return $query; }
        if (!self::exact($query,['key']) || !self::key($query['key'])) { return self::error('typed',400); }
        $identity=$this->identity($request);
        $state=$identity['kind']==='policy' ? ReviewedScopedPolicy::current($identity['shop'],$query['key'])
            : (new ReviewedOwnershipBinding())->current($identity['shop'],$query['key']);
        if (is_wp_error($state)) { return $state; }
        if (is_array($state) && $identity['kind']==='ownership') {
            $plan=(new \DigiForge\POD\NativePlacementPlan())->prepare($identity['shop'],$query['key']);
            $state['native_placement_plan']=is_wp_error($plan)?['classification'=>'REVIEW_REQUIRED','current_evidence_available'=>false,'external_execution_authorized'=>false]:$plan;
        }
        return $state===null ? self::error('missing',404) : new WP_REST_Response($state,200);
    }

    public function propose(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body=$this->body($request,['input','effective_at','expires_at','request_id','expected_revision'],false);
        if (is_wp_error($body)) { return $body; }
        if (!is_array($body['input']) || array_is_list($body['input']) || !is_int($body['effective_at']) || !is_int($body['expires_at'])) { return self::error('typed',400); }
        $identity=$this->identity($request);
        if ($identity['kind']==='ownership' && !$this->ownershipInput($body['input'])) { return self::error('typed',400); }
        $args=[$identity['shop'],$body['input'],$body['effective_at'],$body['expires_at'],$body['request_id'],$body['expected_revision']];
        $result=$identity['kind']==='policy' ? ReviewedScopedPolicy::propose(...$args) : (new ReviewedOwnershipBinding())->propose(...$args);
        return is_wp_error($result) ? $result : new WP_REST_Response($result,201);
    }

    public function decide(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body=$this->body($request,['key','expected_revision','proposal_hash','decision','request_id'],true);
        if (is_wp_error($body)) { return $body; }
        if (!self::key($body['key']) || !is_string($body['proposal_hash']) || preg_match('/^[a-f0-9]{64}$/D',$body['proposal_hash'])!==1
            || !is_string($body['decision']) || !in_array($body['decision'],['APPROVED','REJECTED','REVOKED'],true)) { return self::error('typed',400); }
        $identity=$this->identity($request);
        $args=[$identity['shop'],$body['key'],$body['expected_revision'],$body['proposal_hash'],$body['decision'],$body['request_id']];
        $result=$identity['kind']==='policy' ? ReviewedScopedPolicy::decide(...$args) : (new ReviewedOwnershipBinding())->decide(...$args);
        return is_wp_error($result) ? $result : new WP_REST_Response($result,200);
    }

    private function writePermission(WP_REST_Request $request,bool $decision): true|WP_Error
    {
        $read=$this->canRead($request);if (is_wp_error($read)) { return $read; }
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'),'wp_rest')) { return self::error('nonce',403); }
        $identity=$this->identity($request);
        $cap=(!$decision && $identity['kind']==='ownership') ? 'manage_digiforge_connections' : 'manage_digiforge_settings';
        return ShopAccess::authorize($identity['shop'],$cap);
    }

    private function body(WP_REST_Request $request,array $fields,bool $decision): array|WP_Error
    {
        $access=$this->writePermission($request,$decision);if (is_wp_error($access)) { return $access; }
        if (strlen($request->get_body())>32768) { return self::error('bounded',413); }
        if ($this->query($request)!==[] || !$request->is_json_content_type()) { return self::error('typed',400); }
        $body=$request->get_json_params();
        if (!is_array($body) || !self::exact($body,$fields) || !is_int($body['expected_revision']) || $body['expected_revision']<0
            || !is_string($body['request_id']) || preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$body['request_id'])!==1) { return self::error('typed',400); }
        return $body;
    }

    /** WordPress plain permalinks carry routing metadata, not an extra business input. */
    private function query(WP_REST_Request $request): array|WP_Error
    {
        $query=$request->get_query_params();
        if (array_key_exists('rest_route',$query)) {
            if (!is_string($query['rest_route']) || $query['rest_route']!==$request->get_route()) { return self::error('scope',400); }
            unset($query['rest_route']);
        }
        return $query;
    }

    private function identity(WP_REST_Request $request): array|WP_Error
    {
        $params=$request->get_url_params();$shop=$params['shop']??null;$kind=$params['kind']??null;
        if (!is_string($shop) || !ShopAccessPolicy::validShop($shop) || !is_string($kind) || !in_array($kind,['policy','ownership'],true)) { return self::error('scope',400); }
        return ['shop'=>$shop,'kind'=>$kind];
    }

    private function ownershipInput(array $input): bool
    {
        if (!self::exact($input,['integration_id','provider_mapping_id','production_template_id','source_sha256','evidence_reference'])) { return false; }
        foreach (['integration_id','provider_mapping_id','production_template_id'] as $field) { if (!is_int($input[$field]) || $input[$field]<1) { return false; } }
        return is_string($input['source_sha256']) && preg_match('/^[a-f0-9]{64}$/D',$input['source_sha256'])===1
            && is_string($input['evidence_reference']) && preg_match('#^local://[A-Za-z0-9/_:.-]{1,240}$#D',$input['evidence_reference'])===1;
    }

    private static function exact(array $input,array $fields): bool { return count($input)===count($fields) && array_diff(array_keys($input),$fields)===[]; }
    private static function key(mixed $key): bool { return is_string($key) && preg_match('/^[A-Za-z0-9_:.-]{1,240}$/D',$key)===1; }
    private static function error(string $code,int $status): WP_Error { return new WP_Error('digiforge_reviewed_contract_'.$code,'An exact authenticated local review request is required.',['status'=>$status,'external_execution_authorized'=>false]); }
}
