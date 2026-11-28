<?php
declare(strict_types=1);
use DigiForge\Database\Tables;

/** Actual anonymous REST dispatch in a child with a synthetic server-only signing constant. */
final class EtsyWebhookIngressAcceptanceTest extends WP_UnitTestCase
{
    private array $cutoffs=[];
    private string $shop;
    private string $externalListing;
    private array $settingsBefore=[];
    private array $userRows=[];
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();global $wpdb;$this->settingsBefore=$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A);$this->userRows=[$wpdb->get_results('SELECT * FROM '.$wpdb->users.' ORDER BY ID',ARRAY_A),$wpdb->get_results('SELECT * FROM '.$wpdb->usermeta.' ORDER BY umeta_id',ARRAY_A)];DigiForge\Core\Settings::protectProduction();wp_set_current_user(0);
        self::assertFalse(defined('DIGIFORGE_ETSY_WEBHOOK_SECRET'),'The parent process must remain unconfigured for the existing boundary test.');
        global $wpdb;
        foreach(['orders','order_line_items','product_versions','listings','pod_mappings','businesses','stores','product_programs','pod_business_mappings','listing_pod_bindings','etsy_intents','webhook_evidence','etsy_order_event_evidence','idempotency','audit_log']as $name){$table=Tables::{$name}();$this->cutoffs[$table]=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.$table);}
        $table=$wpdb->prefix.'digiforge_etsy_operations';$this->cutoffs[$table]=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.$table);
        $this->shop='68031896';$this->externalListing=(string)wp_rand(100000000,999999999);
    }
    protected function tearDown():void
    {
        global $wpdb;$wpdb->query('ROLLBACK');foreach($this->cutoffs as $table=>$last)$wpdb->query($wpdb->prepare('DELETE FROM '.$table.' WHERE id>%d',$last));
        self::assertSame($this->userRows,[$wpdb->get_results('SELECT * FROM '.$wpdb->users.' ORDER BY ID',ARRAY_A),$wpdb->get_results('SELECT * FROM '.$wpdb->usermeta.' ORDER BY umeta_id',ARRAY_A)]);$wpdb->query('DELETE FROM '.Tables::settings());foreach($this->settingsBefore as $row)$wpdb->insert(Tables::settings(),$row);self::assertFalse(defined('DIGIFORGE_ETSY_WEBHOOK_SECRET'));parent::tearDown();
    }
    private function historicalPodListing():array
    {
        global $wpdb;$now=current_time('mysql',true);$times=['created_at'=>$now,'updated_at'=>$now];$actor=1;
        // Explicit retained synthetic historical metadata, not native/provider certification.
        $insert=static function(string $table,array $row)use($wpdb):int{self::assertSame(1,$wpdb->insert($table,$row));return (int)$wpdb->insert_id;};
        $version=$insert(Tables::product_versions(),$times+['product_id'=>0,'version_label'=>'ingress-'.wp_generate_uuid4(),'state'=>'APPROVED']);
        $listing=$insert(Tables::listings(),$times+['product_version_id'=>$version,'channel'=>'etsy','environment'=>'production','shop_reference'=>$this->shop,'title'=>'SYNTHETIC retained POD ingress listing','state'=>'APPROVED','approved_by'=>$actor,'approved_at'=>$now]);
        $intent=$insert(Tables::etsy_intents(),$times+['listing_id'=>$listing,'environment'=>'production','intent_type'=>'PREPARE_DRAFT','state'=>'APPROVED_INTENT']);$hash=hash('sha256','synthetic ingress historical source');
        $insert($wpdb->prefix.'digiforge_etsy_operations',$times+['shop_reference'=>$this->shop,'intent_id'=>$intent,'draft_package_id'=>0,'operation_type'=>'CREATE_DRAFT','state'=>'CONFIRMED_SUCCESS','idempotency_key'=>wp_generate_uuid4(),'request_fingerprint'=>$hash,'authorization_hash'=>$hash,'evidence_hash'=>$hash,'external_reference'=>$this->externalListing]);
        $mapping=$insert(Tables::pod_mappings(),$times+['product_version_id'=>$version,'production_plan_id'=>0,'provider'=>'printify','environment'=>'production','provider_product_key'=>'synthetic-retained-product','provider_variant_key'=>'9','mapping_version'=>'retained-v1','state'=>'APPROVED','approved_by'=>$actor,'approved_at'=>$now]);
        $insert(Tables::listing_pod_bindings(),$times+['listing_id'=>$listing,'provider_mapping_id'=>$mapping,'readiness_hash'=>$hash]);
        $business=$insert(Tables::businesses(),$times+['business_key'=>'ingress-'.wp_generate_uuid4(),'display_name'=>'Synthetic ingress owner','status'=>'ACTIVE']);
        $store=$insert(Tables::stores(),$times+['business_id'=>$business,'store_key'=>'ingress-'.wp_generate_uuid4(),'display_name'=>'Synthetic ingress store','channel'=>'etsy','status'=>'ACTIVE']);
        $program=$insert(Tables::product_programs(),$times+['business_id'=>$business,'store_id'=>$store,'program_key'=>'PERSONALIZED_POD','status'=>'ACTIVE','config'=>'{}']);
        $insert(Tables::pod_business_mappings(),$times+['business_id'=>$business,'store_id'=>$store,'product_program_id'=>$program,'product_version_id'=>$version,'provider_mapping_id'=>$mapping,'state'=>'APPROVED','approved_by'=>$actor,'approved_at'=>$now]);
        self::assertFalse(DigiForge\Orders\ApprovedPodMappingResolver::isDigital($version,$listing));self::assertSame($mapping,DigiForge\Orders\ApprovedPodMappingResolver::resolve($listing,$version,'production'));self::assertNotWPError((new DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping($mapping));
        return compact('version','listing','mapping');
    }
    private function payload(string $order):array{return ['event_type'=>'order.paid','order_id'=>$order,'shop_id'=>$this->shop,'currency'=>'EUR','line_items'=>[['listing_id'=>$this->externalListing,'quantity'=>2,'price'=>12,'currency'=>'EUR']]];}
    private function signed(array|string $payload,?string $event=null,int $offset=0):array
    {
        $body=is_array($payload)?(string)wp_json_encode($payload):$payload;$event??='rest-ingress-'.wp_generate_uuid4();$ts=(string)(time()+$offset);
        return ['event'=>$event,'body'=>$body,'headers'=>['webhook-id'=>$event,'webhook-timestamp'=>$ts,'webhook-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$event.'.'.$ts.'.'.$body,'synthetic-rest-ingress-secret',true))]];
    }
    private function worker(array $requests):array
    {
        global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');$settings=$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A);
        $dir=sys_get_temp_dir().'/etsy-ingress-'.wp_generate_uuid4();self::assertTrue(mkdir($dir,0700));
        $config=defined('WP_TESTS_CONFIG_FILE_PATH')?WP_TESTS_CONFIG_FILE_PATH:'';
        $fixture=['synthetic_only'=>true,'config'=>$config,'autoload'=>dirname((new ReflectionClass(Composer\Autoload\ClassLoader::class))->getFileName(),2).'/autoload.php','requests'=>$requests];file_put_contents($dir.'/fixture.json',wp_json_encode($fixture));chmod($dir.'/fixture.json',0600);$process=null;
        try{
            $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/etsy-webhook-ingress-worker.php',$dir.'/fixture.json',$dir.'/result'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/log','w'],2=>['file',$dir.'/err','w']],$pipes);self::assertIsResource($process);
            $until=microtime(true)+45;do{$status=proc_get_status($process);if(!$status['running'])break;usleep(10000);}while(microtime(true)<$until);
            if($status['running']){proc_terminate($process);self::fail('Synthetic REST ingress worker exceeded its deadline.');}$exit=$status['exitcode'];proc_close($process);$process=null;self::assertSame(0,$exit,(string)file_get_contents($dir.'/err'));
            $result=json_decode((string)file_get_contents($dir.'/result'),true);self::assertIsArray($result);self::assertTrue($result['route_registered']);self::assertSame(0,$result['actor_id']);self::assertSame(0,$result['actor_after']);self::assertSame(0,$result['http_calls']);self::assertSame([],$result['enabled_switches']);self::assertTrue($result['stop_all']);self::assertFalse($result['automation_armed']);self::assertTrue($result['safety_locked']);self::assertStringNotContainsString('synthetic-rest-ingress-secret',wp_json_encode($result));self::assertSame($settings,$wpdb->get_results('SELECT * FROM '.Tables::settings().' ORDER BY setting_key',ARRAY_A));return $result;
        }finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}foreach(glob($dir.'/*')as $path)unlink($path);rmdir($dir);}
    }
    private function order(string $external):?array{global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".Tables::orders()." WHERE external_order_reference=%s AND shop_reference=%s AND environment='production' AND channel='etsy'",$external,$this->shop),ARRAY_A);return is_array($row)?$row:null;}
    private function ledger(string $event,string $expected):void{global $wpdb;self::assertSame($expected,$wpdb->get_var($wpdb->prepare('SELECT processing_status FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$event)));self::assertSame($expected,$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Tables::idempotency().' WHERE operation_key=%s','etsy_webhook:'.hash('sha256',$event))));}
    private function unapproved(int $order):void{global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT approved_by,approved_at FROM '.Tables::orders().' WHERE id=%d',$order),ARRAY_A);self::assertSame('0',$row['approved_by']);self::assertNull($row['approved_at']);foreach(['fulfillment_plans','fulfillment_intents','pod_authorization_packages']as $name)self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::{$name}().' WHERE order_id=%d',$order)));}
    public function testActualAnonymousRegisteredRestRoutePersistsMappedPodOrdersAndImmutableEventReplay():void
    {
        $fixture=$this->historicalPodListing();$external='paid-'.wp_generate_uuid4();$paid=$this->signed($this->payload($external));$again=$this->signed($this->payload($external));$unmatched=$this->signed(array_replace($this->payload('review-'.wp_generate_uuid4()),['line_items'=>[['listing_id'=>'999999999999','quantity'=>1]]]));$conflict=$this->signed(array_replace($this->payload($external),['shop_id'=>'different-shop']),$paid['event']);
        $foreignShop=(string)((int)$this->shop+1);$foreign=$this->signed(array_replace($this->payload('foreign-'.wp_generate_uuid4()),['shop_id'=>$foreignShop]));$out=$this->worker([$paid,$paid,$again,$unmatched,$conflict,$foreign]);$responses=$out['responses'];self::assertContains('webhook_id',$responses[0]['header_keys'],'WordPress really normalizes hyphenated headers before controller dispatch.');
        self::assertSame(200,$responses[0]['status'],wp_json_encode($responses[0]));self::assertSame('ETSY_ORDER_VALIDATED',$responses[0]['data']['state']);self::assertTrue($responses[0]['data']['webhook_verified']);self::assertFalse($responses[0]['data']['external_execution_performed']);
        self::assertSame('ETSY_WEBHOOK_DUPLICATE',$responses[1]['data']['state']);self::assertSame('ETSY_ORDER_ALREADY_RECEIVED',$responses[2]['data']['state']);self::assertSame('ETSY_ORDER_REVIEW_REQUIRED',$responses[3]['data']['state']);self::assertSame(409,$responses[4]['status']);self::assertSame('webhook_evidence_conflict',$responses[4]['data']['code']);
        $order=$this->order($external);self::assertIsArray($order);self::assertSame('VALIDATED',$order['state']);$this->unapproved((int)$order['id']);global $wpdb;$lines=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE order_id=%d',$order['id']),ARRAY_A);self::assertCount(1,$lines);self::assertSame($fixture['mapping'],(int)$lines[0]['provider_mapping_id']);self::assertSame($fixture['listing'],(int)$lines[0]['listing_id']);self::assertSame(2,(int)$lines[0]['quantity']);self::assertSame('EUR',$lines[0]['currency']);
        foreach([$paid,$again,$unmatched]as $case)$this->ledger($case['event'],'PROCESSED');$review=$this->order(json_decode($unmatched['body'],true)['order_id']);self::assertSame('REVIEW_REQUIRED',$review['state']);$this->unapproved((int)$review['id']);self::assertSame('ETSY_ORDER_REVIEW_REQUIRED',$responses[5]['data']['state']);$foreignOrder=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::orders().' WHERE external_order_reference=%s AND shop_reference=%s',json_decode($foreign['body'],true)['order_id'],$foreignShop),ARRAY_A);self::assertIsArray($foreignOrder);self::assertSame('REVIEW_REQUIRED',$foreignOrder['state']);self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::order_line_items().' WHERE order_id=%d',$foreignOrder['id'])));$this->unapproved((int)$foreignOrder['id']);
    }
    /** S2-ORD03 composition: authenticated delivery still supplies no internal lineage authority. */
    public function testActualRestLineageDerivesStoredPodIdentitiesAndRetainsBuyerTypesDespiteSpoofedIds():void
    {
        $expected=$this->historicalPodListing();$externalListing=$this->externalListing;
        $this->externalListing=(string)((int)$externalListing+1);$other=$this->historicalPodListing();$this->externalListing=$externalListing;
        $owners=new DigiForge\POD\BusinessScopeRepository();$owner=$owners->assertActiveOwnershipForMapping($expected['mapping']);$foreignOwner=$owners->assertActiveOwnershipForMapping($other['mapping']);self::assertNotWPError($owner);self::assertNotWPError($foreignOwner);
        $spoof=['internal_listing_id'=>$other['listing'],'local_listing_id'=>$other['listing'],'product_version_id'=>$other['version'],'provider_mapping_id'=>$other['mapping'],'product_id'=>999999,'business_id'=>(int)$foreignOwner['business_id'],'store_id'=>(int)$foreignOwner['store_id'],'product_program_id'=>(int)$foreignOwner['product_program_id']];
        $buyer=['recipient_name'=>'Élodie & José','printed_year'=>2026,'gift'=>true,'optional_note'=>null];$external='lineage-'.wp_generate_uuid4();
        $payload=array_replace($this->payload($external),$spoof);$payload['listing_id']=$other['listing'];
        $payload['line_items'][0]=array_replace($payload['line_items'][0],$spoof,['personalization'=>$buyer]);
        $event=$this->signed($payload);$result=$this->worker([$event]);self::assertSame(200,$result['responses'][0]['status'],wp_json_encode($result['responses'][0]));self::assertSame('ETSY_ORDER_VALIDATED',$result['responses'][0]['data']['state']);
        $order=$this->order($external);self::assertIsArray($order);self::assertSame('production',$order['environment']);self::assertSame($this->shop,$order['shop_reference']);self::assertSame('EUR',$order['currency']);self::assertSame(1,(int)$order['personalization_required']);$this->unapproved((int)$order['id']);
        global $wpdb;$lines=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE order_id=%d',$order['id']),ARRAY_A);self::assertCount(1,$lines);$line=$lines[0];
        foreach(['listing'=>'listing_id','version'=>'product_version_id','mapping'=>'provider_mapping_id']as $key=>$column){self::assertSame($expected[$key],(int)$line[$column]);self::assertNotSame($other[$key],(int)$line[$column]);}
        self::assertSame('production',$line['environment']);self::assertSame('EUR',$line['currency']);self::assertSame(2,(int)$line['quantity']);self::assertSame(DigiForge\Orders\Validator::canonicalJson($buyer),$line['personalization_payload']);$retained=json_decode($line['personalization_payload'],true);foreach($buyer as $key=>$value)self::assertSame($value,$retained[$key]);
        $resolvedOwner=$owners->assertActiveOwnershipForMapping((int)$line['provider_mapping_id']);self::assertNotWPError($resolvedOwner);foreach(['business_id','store_id','product_program_id']as $key){self::assertSame($owner[$key],$resolvedOwner[$key]);self::assertNotSame($foreignOwner[$key],$resolvedOwner[$key]);}
        self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::personalization_submissions().' WHERE order_line_item_id=%d',$line['id'])),'Retained buyer answers do not create a reviewed typed submission.');$this->ledger($event['event'],'PROCESSED');
        // A plausible payload mapping cannot select a winner once the saved listing binding is ambiguous.
        $now=current_time('mysql',true);self::assertSame(1,$wpdb->insert(Tables::pod_mappings(),['product_version_id'=>$expected['version'],'production_plan_id'=>0,'provider'=>'printify','environment'=>'production','provider_product_key'=>'synthetic-ambiguous-retained-product','provider_variant_key'=>'10','mapping_version'=>'retained-ambiguous-v1','state'=>'APPROVED','approved_by'=>1,'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]));$second=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::listing_pod_bindings(),['listing_id'=>$expected['listing'],'provider_mapping_id'=>$second,'readiness_hash'=>hash('sha256','synthetic ambiguous lineage'),'created_at'=>$now,'updated_at'=>$now]));self::assertSame(0,DigiForge\Orders\ApprovedPodMappingResolver::resolve($expected['listing'],$expected['version'],'production'));
        $ambiguous=$payload;$ambiguous['order_id']='ambiguous-'.wp_generate_uuid4();$ambiguous['line_items'][0]['provider_mapping_id']=$expected['mapping'];$ambiguousEvent=$this->signed($ambiguous);$denied=$this->worker([$ambiguousEvent]);self::assertSame(200,$denied['responses'][0]['status']);self::assertSame('ETSY_ORDER_REVIEW_REQUIRED',$denied['responses'][0]['data']['state']);
        $review=$this->order($ambiguous['order_id']);self::assertIsArray($review);self::assertSame('REVIEW_REQUIRED',$review['state']);$reviewLine=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE order_id=%d',$review['id']),ARRAY_A);self::assertIsArray($reviewLine);self::assertSame($expected['listing'],(int)$reviewLine['listing_id']);self::assertSame($expected['version'],(int)$reviewLine['product_version_id']);self::assertSame(0,(int)$reviewLine['provider_mapping_id']);self::assertSame('EUR',$reviewLine['currency']);self::assertSame($line['personalization_payload'],$reviewLine['personalization_payload']);$this->unapproved((int)$review['id']);$this->ledger($ambiguousEvent['event'],'PROCESSED');
    }
    public function testInvalidRestRequestsCannotPersistAnyOrderOrReceiptRows():void
    {
        $payload=$this->payload('invalid-'.wp_generate_uuid4());$bad=$this->signed($payload);$bad['headers']['webhook-signature']='v1,'.base64_encode(str_repeat('x',32));$stale=$this->signed($payload,null,-301);$missing=$this->signed($payload);unset($missing['headers']['webhook-id']);$malformed=$this->signed('{malformed');$oversize=$this->signed($payload+['padding'=>str_repeat('x',262144)]);
        $tampered=$this->signed($payload);$tampered['body']=wp_json_encode($payload+['changed_after_signing'=>true]);$unsupported=$this->signed(array_replace($payload,['event_type'=>'order.refunded']));$missingSig=$this->signed($payload);unset($missingSig['headers']['webhook-signature']);$missingTime=$this->signed($payload);unset($missingTime['headers']['webhook-timestamp']);$cases=[$bad,$stale,$missing,$malformed,$oversize,$tampered,$unsupported,$missingSig,$missingTime];$out=$this->worker($cases);self::assertSame('digiforge_etsy_webhook_signature',$out['responses'][0]['data']['code']);self::assertSame('digiforge_etsy_webhook_stale',$out['responses'][1]['data']['code']);self::assertSame('digiforge_etsy_webhook_signature',$out['responses'][5]['data']['code']);self::assertSame('digiforge_etsy_webhook_event',$out['responses'][6]['data']['code']);global $wpdb;foreach($out['responses']as $i=>$response){self::assertGreaterThanOrEqual(400,$response['status']);self::assertNull($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$cases[$i]['event'])));self::assertNull($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::idempotency().' WHERE operation_key=%s','etsy_webhook:'.hash('sha256',$cases[$i]['event']))));}self::assertNull($this->order($payload['order_id']));
    }
    public function testActualRestPersistenceFailureCannotClaimProcessedSuccessOrApproveDownstream():void
    {
        $this->historicalPodListing();$cases=[];foreach(['order_insert','order_transition','completion']as $fault){$case=$this->signed($this->payload('failure-'.wp_generate_uuid4()));$case['fault']=$fault;$cases[]=$case;}$out=$this->worker($cases);
        foreach($out['responses']as $i=>$response){self::assertGreaterThan(0,$response['faults'],'Fault must reach actual persistence after signature verification.');self::assertGreaterThanOrEqual(400,$response['status']);$external=json_decode($cases[$i]['body'],true)['order_id'];$order=$this->order($external);if($i===0)self::assertNull($order);else{self::assertIsArray($order);self::assertSame($i===1?'RECEIVED':'VALIDATED',$order['state']);$this->unapproved((int)$order['id']);}if($i<2)$this->ledger($cases[$i]['event'],'FAILED');else{global $wpdb;self::assertNotSame('PROCESSED',$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Tables::idempotency().' WHERE operation_key=%s','etsy_webhook:'.hash('sha256',$cases[$i]['event']))));self::assertSame('digiforge_etsy_webhook_completion_evidence_unavailable',$response['data']['code']);}}
    }
}
