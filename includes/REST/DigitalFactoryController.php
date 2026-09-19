<?php
declare(strict_types=1);
namespace DigiForge\REST;
use DigiForge\Core\Capabilities;
use DigiForge\DigitalFactory\Repository;
/** Capability-gated CRUD/state API for local digital records. */
final class DigitalFactoryController {
    private const MAX_BODY_BYTES = 65536;
    private const ROUTES = ['digital-products'=>'digital_product','digital-files'=>'digital_file','digital-file-versions'=>'digital_file_version','digital-packages'=>'digital_package','digital-previews'=>'digital_preview','digital-templates'=>'digital_template','digital-licenses'=>'digital_license','digital-download-checks'=>'digital_download_check'];
    private Repository $repository;
    public function __construct(?Repository $repository = null) { $this->repository = $repository ?? new Repository(); }
    public function register(): void { add_action('rest_api_init', [$this, 'routes']); }
    public function routes(): void {
        $pages=['page'=>['default'=>1,'sanitize_callback'=>'absint','validate_callback'=>static fn($v)=>is_numeric($v)&&(int)$v>=1],'per_page'=>['default'=>Repository::DEFAULT_PAGE_SIZE,'sanitize_callback'=>'absint','validate_callback'=>static fn($v)=>is_numeric($v)&&(int)$v>=1&&(int)$v<=Repository::MAX_PAGE_SIZE]];
        foreach(self::ROUTES as $route=>$type){
            register_rest_route('digiforge/v1','/'.$route,[['methods'=>'GET','callback'=>fn(\WP_REST_Request $r)=>$this->index($r,$type),'permission_callback'=>[$this,'can_view'],'args'=>$pages],['methods'=>'POST','callback'=>fn(\WP_REST_Request $r)=>$this->create($r,$type),'permission_callback'=>[$this,'can_manage']]]);
            register_rest_route('digiforge/v1','/'.$route.'/(?P<id>\d+)',[['methods'=>'GET','callback'=>fn(\WP_REST_Request $r)=>$this->show($r,$type),'permission_callback'=>[$this,'can_view'],'args'=>['id'=>['sanitize_callback'=>'absint']]],['methods'=>['PUT','PATCH'],'callback'=>fn(\WP_REST_Request $r)=>$this->update($r,$type),'permission_callback'=>[$this,'can_manage'],'args'=>['id'=>['sanitize_callback'=>'absint']]]]);
        }
        register_rest_route('digiforge/v1','/digital-products/(?P<id>\d+)/state',['methods'=>'POST','callback'=>[$this,'transition'],'permission_callback'=>[$this,'can_manage'],'args'=>['id'=>['sanitize_callback'=>'absint'],'state'=>['required'=>true,'sanitize_callback'=>'sanitize_key']]]);
    }
    public function can_view(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge')||Capabilities::can('manage_digiforge_digital'); }
    public function can_manage(\WP_REST_Request $request): bool { return Capabilities::can('manage_digiforge_digital'); }
    private function index(\WP_REST_Request $r,string $type): \WP_REST_Response { $result=$this->repository->all($type,absint($r->get_param('page')),absint($r->get_param('per_page'))); $response=new \WP_REST_Response($result,200); $response->header('X-WP-Total',(string)$result['pagination']['total_items']); $response->header('X-WP-TotalPages',(string)$result['pagination']['total_pages']); return $response; }
    private function show(\WP_REST_Request $r,string $type): \WP_REST_Response|\WP_Error { $item=$this->repository->find($type,absint($r['id'])); return $item===null?new \WP_Error('digiforge_not_found',__('Digital entity not found.','digiforge'),['status'=>404]):new \WP_REST_Response($item,200); }
    private function create(\WP_REST_Request $r,string $type): \WP_REST_Response|\WP_Error { if(strlen((string)$r->get_body())>self::MAX_BODY_BYTES){return new \WP_Error('payload_too_large',__('JSON body exceeds 64 KiB.','digiforge'),['status'=>413]);} $key=sanitize_text_field((string)($r->get_header('Idempotency-Key')?:$r->get_param('idempotency_key'))); $result=$this->repository->create($type,(array)$r->get_json_params(),$key===''?null:$key); if(is_wp_error($result)){return $result;} $replay=(bool)($result['idempotent_replay']??false); unset($result['idempotent_replay']); $response=new \WP_REST_Response($result,$replay?200:201); if($replay){$response->header('Idempotent-Replay','true');} return $response; }
    private function update(\WP_REST_Request $r,string $type): \WP_REST_Response|\WP_Error { if(strlen((string)$r->get_body())>self::MAX_BODY_BYTES){return new \WP_Error('payload_too_large',__('JSON body exceeds 64 KiB.','digiforge'),['status'=>413]);} $result=$this->repository->update($type,absint($r['id']),(array)$r->get_json_params()); return is_wp_error($result)?$result:new \WP_REST_Response($result,200); }
    public function transition(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { if(strlen((string)$r->get_body())>self::MAX_BODY_BYTES){return new \WP_Error('payload_too_large',__('JSON body exceeds 64 KiB.','digiforge'),['status'=>413]);} $result=$this->repository->transition(absint($r['id']),(string)$r->get_param('state')); return is_wp_error($result)?$result:new \WP_REST_Response($result,200); }
}
