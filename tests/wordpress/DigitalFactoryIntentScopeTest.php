<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\DigitalFactory\Repository;
use DigiForge\Database\Tables;

/** Local registry intent and real REST scope; never product/provider certification. */
final class DigitalFactoryIntentScopeTest extends WP_UnitTestCase
{
    private int $calls=0;
    private Closure $http;
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        ShopAccessFixture::reset();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        (new DigiForge\REST\DigitalFactoryController())->register();do_action('rest_api_init');
        (new DigiForge\Security\ShopAccessRestGuard())->register();
        $this->http=function(){++$this->calls;return new WP_Error('offline_only');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown():void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->calls);
        self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));parent::tearDown();
    }
    private function hierarchy(string $shop):array
    {
        $marker=wp_generate_uuid4();$products=new DigiForge\ProductFactory\Repository();
        $o=$products->create('opportunity',['title'=>'Synthetic '.$marker]);self::assertNotWPError($o);
        $c=(new DigiForge\Research\Repository())->createCandidate(['title'=>'Synthetic '.$marker,'shop_key'=>$shop]);self::assertNotWPError($c);
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::research_candidates(),['opportunity_id'=>$o['id']],['id'=>$c['id']]));
        $f=$products->create('product_family',['name'=>'Synthetic family '.$marker,'opportunity_id'=>$o['id']]);self::assertNotWPError($f);
        $p=$products->create('product',['name'=>'Synthetic product '.$marker,'product_family_id'=>$f['id']]);self::assertNotWPError($p);
        $v=$products->create('product_version',['product_id'=>$p['id'],'version_label'=>'Synthetic '.$marker,'notes'=>'Offline only']);self::assertNotWPError($v);
        $repo=new Repository();$rows=[];$inputs=[];$keys=[];
        $save=function(string $type,array $input)use($repo,&$rows,&$inputs,&$keys,$marker):array{
            $inputs[$type]=$input;$keys[$type]=$marker.'-'.$type;$row=$repo->create($type,$input,$keys[$type]);self::assertNotWPError($row);$rows[$type]=$row;return $row;
        };
        $dp=$save('digital_product',['name'=>'Synthetic '.$marker,'category'=>'fixture','product_id'=>$p['id'],'product_version_id'=>$v['id']]);
        $df=$save('digital_file',['name'=>'Synthetic PDF','file_type'=>'pdf','digital_product_id'=>$dp['id'],'product_version_id'=>$v['id'],'checksum_sha256'=>hash('sha256','fixture'),'storage_reference'=>'local://synthetic.pdf']);
        $fv=$save('digital_file_version',['digital_file_id'=>$df['id'],'version_label'=>'v1','checksum_sha256'=>hash('sha256','fixture'),'storage_reference'=>'local://synthetic.pdf','byte_size'=>12]);
        $save('digital_package',['name'=>'Synthetic package','digital_product_id'=>$dp['id'],'product_version_id'=>$v['id'],'manifest'=>['files'=>['synthetic.pdf'],'version'=>1]]);
        $preview=$save('digital_preview',['name'=>'Synthetic preview','digital_product_id'=>$dp['id'],'digital_file_id'=>$df['id'],'preview_reference'=>'local://synthetic.png']);
        $save('digital_template',['name'=>'Synthetic template','digital_product_id'=>$dp['id'],'digital_file_id'=>$df['id'],'digital_preview_id'=>$preview['id'],'template_reference'=>'local://synthetic.docx','access_instructions'=>'Offline fixture']);
        $save('digital_license',['name'=>'Synthetic license','license_type'=>'personal','digital_product_id'=>$dp['id'],'terms'=>'Synthetic only','license_code'=>'synthetic-license']);
        $save('digital_download_check',['digital_product_id'=>$dp['id'],'target_type'=>'file_version','target_id'=>$fv['id'],'check_type'=>'checksum','details'=>['synthetic'=>true]]);
        return compact('rows','inputs','keys');
    }
    public static function entityCases():array
    {
        return [
            ['digital_product','digital-products','name'],['digital_file','digital-files','checksum_sha256'],
            ['digital_file_version','digital-file-versions','checksum_sha256'],['digital_package','digital-packages','manifest'],
            ['digital_preview','digital-previews','preview_reference'],['digital_template','digital-templates','access_instructions'],
            ['digital_license','digital-licenses','license_code'],['digital_download_check','digital-download-checks','details'],
        ];
    }
    /** @dataProvider entityCases */
    public function testConflictingRetryCannotReturnAnotherIntent(string $type,string $route,string $field):void
    {
        $f=$this->hierarchy('digital');$repo=new Repository();$input=$f['inputs'][$type];$key=$f['keys'][$type];
        $same=$repo->create($type,$input,$key);self::assertNotWPError($same);self::assertTrue($same['idempotent_replay']);
        self::assertSame($f['rows'][$type]['id'],$same['id']);
        $input[$field]=in_array($field,['manifest','details'],true)?['different'=>true]:($field==='checksum_sha256'?hash('sha256','different'):'different intent');
        $conflict=$repo->create($type,$input,$key);self::assertWPError($conflict);
        self::assertSame(409,$conflict->get_error_data()['status']);
        self::assertSame($f['rows'][$type],$repo->find($type,$f['rows'][$type]['id']));
    }
    /** @dataProvider entityCases */
    public function testStoredDigitalItemUsesExactShopScope(string $type,string $route,string $field):void
    {
        $own=$this->hierarchy('digital');$foreign=$this->hierarchy('personalized_pod');
        ShopAccessFixture::authorizeActor(get_current_user_id(),['digital'],['manage_digiforge_digital']);
        $read=function(int $id,?string $shop=null)use($route):WP_REST_Response{
            $r=new WP_REST_Request('GET','/digiforge/v1/'.$route.'/'.$id);$r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
            if($shop!==null)$r->set_query_params(['shop_key'=>$shop]);return rest_do_request($r);
        };
        self::assertSame(200,$read($own['rows'][$type]['id'])->get_status());
        self::assertSame(403,$read($foreign['rows'][$type]['id'],'digital')->get_status());
        self::assertSame(403,$read($own['rows'][$type]['id'],'personalized_pod')->get_status());
        $collection=new WP_REST_Request('GET','/digiforge/v1/'.$route);$collection->set_query_params(['shop_key'=>'digital']);
        self::assertSame(403,rest_do_request($collection)->get_status(),'A query claim cannot scope a global collection.');
        $write=new WP_REST_Request('PATCH','/digiforge/v1/'.$route.'/'.$own['rows'][$type]['id']);
        $write->set_header('Content-Type','application/json');$write->set_body(wp_json_encode($own['inputs'][$type]));
        self::assertSame(403,rest_do_request($write)->get_status(),'Scoped reads do not weaken existing global write admission.');
    }

    public function testAssignmentRevokedAfterRestAdmissionCannotExposeItem():void
    {
        $f=$this->hierarchy('digital');$actor=get_current_user_id();
        $owner=ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_digital']);
        $repo=new DigiForge\Security\ShopAccessRepository();$state=$repo->read('digital');self::assertNotWPError($state);
        $grants=array_filter($state['grants'],static fn(array $g):bool=>$g['actor_id']===$actor);self::assertNotEmpty($grants);
        $route='/digiforge/v1/digital-products/'.$f['rows']['digital_product']['id'];$changed=false;
        $revoke=function($result,$handler,WP_REST_Request $request)use($route,$owner,$actor,$repo,$state,$grants,&$changed){
            if($request->get_route()===$route&&!$changed){
                $changed=true;wp_set_current_user($owner);
                try{self::assertNotWPError($repo->revoke('digital',(string)array_key_first($grants),wp_generate_uuid4(),$state['revision']));}
                finally{wp_set_current_user($actor);}
            }
            return $result;
        };
        add_filter('rest_request_before_callbacks',$revoke,11,3);
        try{$response=rest_do_request(new WP_REST_Request('GET',$route));}
        finally{remove_filter('rest_request_before_callbacks',$revoke,11);}
        self::assertTrue($changed);self::assertSame(403,$response->get_status());
        self::assertArrayNotHasKey('product_version_id',$response->get_data());
    }

    public function testRetryValidatesRequiredFieldsLineageAndCompetingWinner():void
    {
        $own=$this->hierarchy('digital');$foreign=$this->hierarchy('personalized_pod');$repo=new Repository();
        self::assertWPError($repo->create('digital_product',[],$own['keys']['digital_product']));
        $input=$own['inputs']['digital_product'];
        foreach(['product_id','product_version_id'] as $field)$input[$field]=$foreign['inputs']['digital_product'][$field];
        $conflict=$repo->create('digital_product',$input,$own['keys']['digital_product']);self::assertWPError($conflict);
        self::assertSame(409,$conflict->get_error_data()['status']);
        global $wpdb;$table=Tables::digital_packages();
        foreach([false,true] as $changed){
            $hidden=false;$hide=function(string $sql)use($table,&$hidden):string{
                if(!$hidden&&str_contains($sql,'SELECT * FROM '.$table.' WHERE idempotency_key')){$hidden=true;return 'SELECT * FROM '.$table.' WHERE id=0';}
                return $sql;
            };
            $input=$own['inputs']['digital_package'];if($changed)$input['manifest']=['different'=>true];
            add_filter('query',$hide);
            try{$result=$repo->create('digital_package',$input,$own['keys']['digital_package']);}
            finally{remove_filter('query',$hide);}
            self::assertTrue($hidden);
            if($changed){self::assertWPError($result);self::assertSame(409,$result->get_error_data()['status']);}
            else{self::assertNotWPError($result);self::assertTrue($result['idempotent_replay']);}
        }
        $input=$own['inputs']['digital_package'];$input['manifest']=['version'=>1,'files'=>['synthetic.pdf']];
        self::assertNotWPError($repo->create('digital_package',$input,$own['keys']['digital_package']));
    }

    public static function semanticCases():array
    {
        $required=['specification_match','spelling_text_quality','ip_trademark_risk','prohibited_content','link_qr_integrity','marketing_product_consistency','mockup_production_separation'];
        return [[array_fill(0,7,'arbitrary'),'BLOCKED'],[array_fill(0,7,'specification_match'),'BLOCKED'],[array_values(array_diff($required,['ip_trademark_risk'])),'BLOCKED'],[$required,'PASS']];
    }
    /** @dataProvider semanticCases */
    public function testPortalSemanticReadinessMatchesAuthoritativeApprovalBoundary(array $names,string $expected):void
    {
        $f=$this->hierarchy('digital');$production=new DigiForge\Production\Repository();
        $plan=$production->createPlan(['product_version_id'=>$f['rows']['digital_product']['product_version_id'],'plan_key'=>'synthetic-semantic-'.wp_generate_uuid4(),'version_label'=>'1','channel'=>'digital','production_type'=>'fixture']);
        self::assertNotWPError($plan);
        foreach(['VALIDATED','REVIEW_REQUIRED'] as $state)self::assertNotWPError($production->transition('plan',(int)$plan['id'],$state));
        foreach($names as $name)self::assertNotWPError($production->addQa(['target_type'=>'plan','target_id'=>$plan['id'],'check_type'=>'semantic_'.$name,'status'=>'PASS','details'=>['synthetic_only'=>true]]));
        $gate=(new ReflectionMethod(DigiForge\ProductFactory\ProductReview::class,'planQaPassed'))->invoke(new DigiForge\ProductFactory\ProductReview(),$plan['id']);
        self::assertSame($expected==='PASS',$gate===true);
        $view=(new ReflectionMethod(DigiForge\Portal\U3ApprovalInbox::class,'pendingProducts'))->invoke(new DigiForge\Portal\U3ApprovalInbox());
        self::assertIsArray($view);$row=array_values(array_filter($view,static fn(array $r):bool=>(int)$r['plan_id']===(int)$plan['id']));
        self::assertCount(1,$row);self::assertSame($expected,$row[0]['semantic_qa_status']);
        self::assertGreaterThan(0,$row[0]['qa_failures'],'No required assets cannot mean zero approval blockers.');
    }

    public function testOmittedAndMalformedFieldsCannotChangeRetryIdentity():void
    {
        $f=$this->hierarchy('digital');$repo=new Repository();
        foreach(['digital_file'=>'checksum_sha256','digital_package'=>'manifest','digital_license'=>'license_code'] as $type=>$field){
            $input=$f['inputs'][$type];unset($input[$field]);
            $result=$repo->create($type,$input,$f['keys'][$type]);self::assertWPError($result);
            self::assertSame(409,$result->get_error_data()['status']);
        }
        $input=$f['inputs']['digital_file'];$input['checksum_sha256']=['invalid'];
        self::assertWPError($repo->create('digital_file',$input,$f['keys']['digital_file']));
    }
}
