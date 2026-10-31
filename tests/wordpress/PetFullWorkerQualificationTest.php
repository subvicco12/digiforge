<?php
declare(strict_types=1);
require_once __DIR__.'/Support/SyntheticEtsyIdentity.php';
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Full local WP worker measurement. Synthetic technical evidence, never host certification.
 * Fixture lifecycle follows FiveEngineArchitectureTest; no compositor implementation here.
 */
final class PetFullWorkerQualificationTest extends WP_UnitTestCase
{
    private array $policiesBefore=[];
    private array $tableFloors=[];
    protected function setUp():void {parent::setUp();DigiForge\Core\Activator::activate();global $wpdb;$this->policiesBefore=$wpdb->get_results('SELECT * FROM '.DigiForge\Database\Tables::shop_ai_policies(),ARRAY_A);foreach(['research_candidates','research_reviews','opportunities','product_families','products','product_versions','pod_production_templates','orders','order_line_items','personalization_schemas','personalization_submissions','integrations','audit_log','shop_ai_policies','shop_ai_usage'] as $method){$table=DigiForge\Database\Tables::{$method}();$this->tableFloors[$table]=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.$table);}$this->tableFloors[$wpdb->options]=(int)$wpdb->get_var('SELECT COALESCE(MAX(option_id),0) FROM '.$wpdb->options);DigiForge\Core\Settings::protectProduction();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));ShopAccessFixture::reset();ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge_pod']);}
    private function fixture(int $side=1024,array $limits=[]):array
    {
        $engine='PET_PORTRAIT';
        $r=new DigiForge\Research\Repository();$c=$r->createCandidate(['shop_key'=>'personalized_pod','title'=>'Synthetic engine '.wp_generate_uuid4()]);self::assertNotWPError($c);self::assertNotWPError($r->review((int)$c['id'],'APPROVED','Synthetic architecture fixture'));$o=$r->promote((int)$c['id']);self::assertNotWPError($o);
        $p=new DigiForge\ProductFactory\Repository();$family=$p->create('product_family',['opportunity_id'=>$o['id'],'name'=>'Synthetic engine']);$product=$p->create('product',['product_family_id'=>$family['id'],'name'=>'Synthetic engine']);$v=$p->create('product_version',['product_id'=>$product['id'],'version_label'=>'v1']);self::assertNotWPError($v);
        self::assertNotWPError((new DigiForge\AI\ShopAiGovernanceRepository())->savePolicy(['shop_key'=>'personalized_pod','template_cap'=>['limit'=>8,'duration_seconds'=>604800,'anchor_utc'=>'2026-01-05 00:00:00']]));
        $area=['position'=>'front','decoration_method'=>'dtg','width_px'=>$side,'height_px'=>$side,'dpi'=>300,'qa_geometry'=>['safe_zone'=>['x'=>0,'y'=>0,'width'=>$side,'height'=>$side],'seam_exclusions'=>[],'min_image_dpi'=>72,'bleed'=>['trim_box'=>['x'=>0,'y'=>0,'width'=>$side,'height'=>$side],'margins_px'=>['left'=>0,'right'=>0,'top'=>0,'bottom'=>0]]]];
        $templateInput=digiforge_test_printify_template(['shop_key'=>'personalized_pod','template_id'=>'engine-'.wp_generate_uuid4(),'template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[3],'print_areas'=>[$area],'personalization_pipeline'=>'DIGIFORGE_AI','personalization_engine'=>$engine,'template_status'=>'VALIDATED']);self::assertNotWPError($templateInput);$template=(new DigiForge\POD\ProductionTemplateRepository())->save($templateInput);self::assertNotWPError($template);
        $path=tempnam(sys_get_temp_dir(),'df-engine-synthetic-');file_put_contents($path,DigiForge\POD\RasterArtworkCodec::encode($side,$side,str_repeat("\xff\xff\xff\xff",$side*$side)));$this->paths[]=$path;
        $request=['engine_id'=>$engine,'contract_version'=>'1.0.0','product_version_id'=>(int)$v['id'],'template_id'=>$template['template_id'],'template_version'=>1,'template_sha256'=>$template['fingerprint'],'personalization_sha256'=>hash('sha256','synthetic-personalization'),'options'=>['style_reference'=>'synthetic-reference'],'limits'=>array_replace(['max_input_bytes'=>16777216,'max_output_bytes'=>16777216,'max_pixels'=>1048576,'max_execution_seconds'=>10,'max_variants'=>1,'max_retries'=>0],$limits)];
        $rights=['evidence_kind'=>'SYNTHETIC_TEST_ONLY','fixture_id'=>'ENG-POS-001','engine_id'=>$engine,'version'=>'1.0.0','source_asset_sha256'=>hash_file('sha256',$path),'result_asset_sha256'=>hash_file('sha256',$path),'qa_rubric_revision'=>'synthetic-rubric-1','review_owner'=>'synthetic-test','template_id'=>$template['template_id']];
        return [$request,$path,$rights];
    }
    private array $paths=[];
    protected function tearDown():void {global $wpdb;foreach($this->tableFloors as $table=>$floor){$wpdb->query('DELETE FROM '.$table.' WHERE '.($table===$wpdb->options?'option_id':'id').'>'.(int)$floor);}$table=DigiForge\Database\Tables::shop_ai_policies();$ids=array_column($this->policiesBefore,'id');$wpdb->query('DELETE FROM '.$table.($ids!==[]?' WHERE id NOT IN ('.implode(',',array_map('intval',$ids)).')':''));foreach($this->policiesBefore as $row){$id=$row['id'];unset($row['id']);$wpdb->update($table,$row,['id'=>$id]);}// Repository review/activation commits are real: persist removal of only this test's fixture IDs before the framework rollback.
        $wpdb->query('COMMIT');foreach($this->paths as $path)if(is_file($path))unlink($path);ShopAccessFixture::reset();parent::tearDown();}

    private function matte(int $side):string
    {
        $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);
        $raw=str_repeat("\0".str_repeat("\x80",$side),$side);
        $path=tempnam(sys_get_temp_dir(),'df-pet-worker-matte-');$this->paths[]=$path;
        file_put_contents($path,"\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$side,$side,8,0,0,0,0)).$chunk('IDAT',gzcompress($raw,9)).$chunk('IEND',''));
        return $path;
    }
    private function ready(int $side=1024,array $limits=[]):array
    {
        [$request,$source,$rights]=$this->fixture($side,$limits);
        $expected=DigiForge\POD\RasterArtworkCodec::encode($side,$side,str_repeat("\xff\xff\xff\x80",$side*$side));
        $rights['result_asset_sha256']=hash('sha256',$expected);
        $service=new DigiForge\POD\TransformationWorkflow();$key=wp_generate_uuid4();
        self::assertNotWPError($service->create('personalized_pod',$key,$request,$source,$rights));
        self::assertNotWPError($service->verifyInput('personalized_pod',$key));
        return [$service,$key,$this->matte($side),['crop'=>[0,0,$side,$side],'canvas'=>[$side,$side],'placement'=>[0,0],'reduction'=>1],['subject_selection'=>'Synthetic opaque square','pose'=>'Not an animal','tool'=>'synthetic_fixture','tool_version'=>'1','history'=>'Synthetic supplied grayscale matte','border_contacts'=>'All four intentional synthetic edges','critical_features'=>'No animal features'], $expected];
    }
    private function journal(string $key):array
    {
        global $wpdb;$prefix='df_transform_job_'.hash('sha256','personalized_pod|'.$key).'_';
        return $wpdb->get_col($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_name',$wpdb->esc_like($prefix).'%'));
    }
    private function memory():array
    {
        $status=@file_get_contents('/proc/self/status');$rss=null;$peak=null;
        if(is_string($status)){if(preg_match('/^VmRSS:\s+(\d+) kB/m',$status,$m))$rss=(int)$m[1]*1024;if(preg_match('/^VmHWM:\s+(\d+) kB/m',$status,$m))$peak=(int)$m[1]*1024;}
        return ['allocator_current_bytes'=>memory_get_usage(true),'allocator_peak_bytes'=>memory_get_peak_usage(true),'process_rss_bytes'=>$rss,'process_rss_peak_bytes'=>$peak];
    }
    public function testFullPixelWorkerRetainsDeterministicResultAndPreview():void
    {
        if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();$start=hrtime(true);$before=$this->memory();$times=[];
        [$service,$key,$matte,$params,$annotation,$expected]=$this->ready();
        foreach(['prepare'=>fn()=>$service->preparePetInput('personalized_pod',$key),'review'=>fn()=>$service->reviewPetComposition('personalized_pod',$key,$matte,$params,$annotation),'render'=>fn()=>$service->renderPetCutout('personalized_pod',$key),'preview'=>fn()=>$service->preparePetPreview('personalized_pod',$key)] as $stage=>$operation){$at=hrtime(true);$job=$operation();$times[$stage]=(hrtime(true)-$at)/1e9;self::assertNotWPError($job,$stage);}
        self::assertSame('GENERATED',$job['state']);self::assertSame(hash('sha256',$expected),$job['result']['sha256']);self::assertSame($job['result'],$job['preview']);self::assertFalse($job['production_ready']);
        $original=DigiForge\POD\RasterArtworkCodec::encode(1024,1024,str_repeat("\xff\xff\xff\xff",1048576));
        $source=$service->artifact('personalized_pod',$key,'source');self::assertNotWPError($source);self::assertSame($original,$source['bytes']);self::assertSame(hash('sha256',$original),$job['source']['sha256']);
        $retained=$service->artifact('personalized_pod',$key,'preview');self::assertNotWPError($retained);self::assertSame($expected,$retained['bytes']);
        self::assertSame(DigiForge\Orders\Validator::canonicalJson($job),DigiForge\Orders\Validator::canonicalJson($service->renderPetCutout('personalized_pod',$key)));self::assertSame(DigiForge\Orders\Validator::canonicalJson($job),DigiForge\Orders\Validator::canonicalJson($service->preparePetPreview('personalized_pod',$key)));
        $measurement=['scope'=>'SYNTHETIC_TEST_ONLY','dimensions'=>[1024,1024],'pixels'=>1048576,'limits'=>$job['request']['limits'],'elapsed_seconds'=>(hrtime(true)-$start)/1e9,'stage_seconds'=>$times,'before'=>$before,'after'=>$this->memory(),'php'=>PHP_VERSION,'host_certified'=>false];
        fwrite(STDOUT,"\nPET_WORKER_MEASUREMENT ".json_encode($measurement,JSON_UNESCAPED_SLASHES)."\n");
    }
    public function testResourceFailureLeavesNoGeneratedResult():void
    {
        [$service,$key]=$this->ready(64,['max_output_bytes'=>1]);$before=$service->read('personalized_pod',$key);self::assertWPError($service->preparePetInput('personalized_pod',$key));$after=$service->read('personalized_pod',$key);self::assertSame($before,$after);self::assertSame('INPUT_VERIFIED',$after['state']);self::assertArrayNotHasKey('result',$after);self::assertWPError($service->renderPetCutout('personalized_pod',$key));
    }
    public function testReviewDeadlineFailureLeavesReviewRetryable():void
    {
        [$service,$key,$matte,$params,$annotation]=$this->ready(64,['max_execution_seconds'=>1]);self::assertNotWPError($service->preparePetInput('personalized_pod',$key));$before=$service->read('personalized_pod',$key);
        $delayed=false;$prefix=hash('sha256','personalized_pod|'.$key);
        $filter=static function($sql)use(&$delayed,$prefix){if(!$delayed&&str_starts_with($sql,'SELECT')&&str_contains($sql,$prefix)){$delayed=true;usleep(1100000);}return $sql;};
        add_filter('query',$filter);try{$failed=$service->reviewPetComposition('personalized_pod',$key,$matte,$params,$annotation);}finally{remove_filter('query',$filter);}
        self::assertTrue($delayed);self::assertWPError($failed);self::assertSame('transformation_cutout_review_limit',$failed->get_error_code());self::assertSame($before,$service->read('personalized_pod',$key));self::assertArrayNotHasKey('result',$before);self::assertNotWPError($service->reviewPetComposition('personalized_pod',$key,$matte,$params,$annotation));
        $before=$service->read('personalized_pod',$key);$delayed=false;
        add_filter('query',$filter);try{$failed=$service->renderPetCutout('personalized_pod',$key);}finally{remove_filter('query',$filter);}
        self::assertTrue($delayed);self::assertWPError($failed);self::assertSame('transformation_cutout_render_limit',$failed->get_error_code());self::assertSame($before,$service->read('personalized_pod',$key));self::assertSame('INPUT_VERIFIED',$before['state']);self::assertArrayNotHasKey('result',$before);self::assertNotWPError($service->renderPetCutout('personalized_pod',$key));
    }
    public function testCancelledAndRevokedWorkersCannotGenerate():void
    {
        [$service,$key,$matte,$params,$annotation]=$this->ready(64);self::assertNotWPError($service->preparePetInput('personalized_pod',$key));self::assertNotWPError($service->reviewPetComposition('personalized_pod',$key,$matte,$params,$annotation));$cancelled=$service->cancel('personalized_pod',$key);self::assertNotWPError($cancelled);self::assertSame('CANCELLED',$cancelled['state']);self::assertArrayNotHasKey('result',$cancelled);self::assertWPError($service->renderPetCutout('personalized_pod',$key));self::assertWPError($service->preparePetPreview('personalized_pod',$key));
        [$service,$key,$matte,$params,$annotation]=$this->ready(64);self::assertNotWPError($service->preparePetInput('personalized_pod',$key));self::assertNotWPError($service->reviewPetComposition('personalized_pod',$key,$matte,$params,$annotation));$before=$this->journal($key);ShopAccessFixture::reset();self::assertWPError($service->renderPetCutout('personalized_pod',$key));self::assertWPError($service->preparePetPreview('personalized_pod',$key));self::assertWPError($service->artifact('personalized_pod',$key,'source'));self::assertSame($before,$this->journal($key));$retained=json_decode(end($before),true);self::assertSame('INPUT_VERIFIED',$retained['state']);self::assertArrayNotHasKey('result',$retained);
    }
}
