<?php
declare(strict_types=1);
use DigiForge\DigitalFactory\{DriveGoogleWordPress,DriveWordPressQueueJobs,DriveWordPressGoogleGate,DriveWordPressGrantStore};
use DigiForge\Queue\JobRepository;
use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Actual WordPress hooks, queue DB/leases and held controls. No live credentials or requests. */
final class DriveGoogleCallbackQueueTest extends WP_UnitTestCase
{
    private int $calls=0;private Closure $deny;
    protected function setUp():void{
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();
        $this->deny=function(){++$this->calls;return new WP_Error('no_google_http');};add_filter('pre_http_request',$this->deny);
    }
    protected function tearDown():void{
        remove_filter('pre_http_request',$this->deny);self::assertSame(0,$this->calls);self::assertTrue(Settings::get('stop_all',true));self::assertTrue(Settings::safety_locked());self::assertFalse(Settings::get('automation_armed',false));parent::tearDown();
    }
    public function testCanonicalCallbackHooksRegisterWithoutCredentialsOrScheduling():void{
        $runtime=new DriveGoogleWordPress();$runtime->register();
        foreach(['admin_post_digiforge_google_oauth_start'=>'start','admin_post_digiforge_google_oauth_callback'=>'callback','admin_post_digiforge_google_queue'=>'queue','digiforge_google_drive_job'=>'run'] as $hook=>$method){self::assertNotFalse(has_action($hook,[$runtime,$method]));remove_action($hook,[$runtime,$method]);}
        self::assertFalse(has_action('admin_post_nopriv_digiforge_google_oauth_callback'));
        self::assertStringStartsWith('https://',DriveGoogleWordPress::callbackUrl());self::assertStringEndsWith('admin-post.php?action=digiforge_google_oauth_callback',DriveGoogleWordPress::callbackUrl());
        self::assertNull($runtime->services());$actor=get_current_user_id();$runtime->run(99999);self::assertSame($actor,get_current_user_id());
    }
    public function testRealQueuePayloadBindingDependenciesAndLeases():void{
        $jobs=new DriveWordPressQueueJobs();$transfer=hash('sha256',wp_generate_uuid4());$id=$jobs->create($transfer,'folder',null);self::assertIsInt($id);self::assertSame($id,$jobs->create($transfer,'folder',null));
        $row=$jobs->read($id);self::assertSame('BLOCKED',$row['state']);self::assertSame($transfer,$row['transfer']);
        $repository=new JobRepository();self::assertTrue($repository->transition($id,'BLOCKED','QUEUED'));self::assertTrue($jobs->claim($id,'synthetic-worker'));
        self::assertFalse($jobs->claim($id,'foreign-worker'));self::assertFalse($jobs->finish($id,'foreign-worker','SUCCESS'));self::assertTrue($jobs->finish($id,'synthetic-worker','SUCCESS'));
        $child=$jobs->create($transfer,'original_upload',$id);self::assertIsInt($child);self::assertTrue($repository->transition($child,'BLOCKED','QUEUED'));self::assertTrue($jobs->claim($child,'child-worker'));self::assertTrue($jobs->finish($child,'child-worker','HUMAN_REVIEW'));self::assertFalse($jobs->claim($child,'retry-worker'));
        $foreign=$repository->enqueue('other_job',['google_transfer'=>$transfer]);self::assertIsInt($foreign);self::assertNull($jobs->read($foreign));
    }
    public function testHeldActorCannotResolveSourceOrDispatchForgedJob():void{
        $runtime=new DriveGoogleWordPress();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$actor=get_current_user_id();
        self::assertFalse((new DriveWordPressGoogleGate())->allows([]));self::assertNull($runtime->requestedPlan(['revision_id'=>'1','request_id'=>str_repeat('a',64)]));
        self::assertNull($runtime->requestedPlan(['revision_id'=>['1'],'request_id'=>'bad']));$runtime->run('1');$runtime->run(1);self::assertSame($actor,get_current_user_id());
    }
    public function testActualSessionBoundCallbackAndWorkerRestoreActorWithSyntheticTransport():void{
        ShopAccessFixture::reset();$actor=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($actor);
        ShopAccessFixture::authorizeActor($actor,['digital'],['manage_digiforge_products']);
        $store=new class implements \DigiForge\DigitalFactory\DriveGrantStore{
            public array $rows=[];
            public function read(string $key):?array{return $this->rows[$key]??[];}
            public function compareAndSwap(string $key,array $old,array $new):bool{if(($this->rows[$key]??[])!==$old)return false;$this->rows[$key]=$new;return true;}
        };
        $gate=new class implements \DigiForge\DigitalFactory\DriveGoogleGate{
            public function now():int{return time();}
            public function owns(array $b):bool{return $b['actor_id']===get_current_user_id()&&\DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge_products')===true;}
            public function allows(array $b):bool{return $this->owns($b);}
        };
        $http=new class implements \DigiForge\DigitalFactory\DriveGoogleHttp{
            public array $responses=[];public int $calls=0;
            public function exchange(array $b,string $url,array $args):array{$this->calls++;return array_shift($this->responses);}
        };
        $b=['id'=>hash('sha256',wp_generate_uuid4()),'installation_sha256'=>hash('sha256',site_url()),'environment'=>'local','shop_key'=>'digital','folder_id'=>\DigiForge\DigitalFactory\DriveTransferRequest::FOLDER,'actor_id'=>$actor,'product_id'=>9,'product_version_id'=>5,'revision_id'=>12,'source_sha256'=>hash('sha256','synthetic-docx')];
        $uri=DriveGoogleWordPress::callbackUrl();$client='synthetic.apps.googleusercontent.com';
        $cipher=\DigiForge\Integrations\CredentialVault::encrypt('synthetic-client','google:oauth-client:'.hash('sha256',$client."\0".$uri));
        $oauth=new \DigiForge\DigitalFactory\DriveGoogleOAuth($store,$gate,$http,$client,$uri,$cipher);
        $auth=new \DigiForge\DigitalFactory\DriveGoogleAuthority($store,$gate,$oauth);
        $dispatch=new \DigiForge\DigitalFactory\DriveGoogleDispatcher($store,$gate,$oauth,$auth,$http);$jobs=new DriveWordPressQueueJobs();
        $workflow=new \DigiForge\DigitalFactory\DriveGoogleWorkflow($store,$gate,$oauth,$auth,$dispatch,$jobs,fn($b)=>['bytes'=>'synthetic-docx'],fn()=>null,fn()=>null,$uri);
        $runtime=new DriveGoogleWordPress(fn()=>$workflow);
        $token=WP_Session_Tokens::get_instance($actor)->create(time()+3600);$cookie=wp_generate_auth_cookie($actor,time()+3600,'logged_in',$token);
        $oldCookie=$_COOKIE[LOGGED_IN_COOKIE]??null;$oldGet=$_GET;$method=$_SERVER['REQUEST_METHOD']??null;
        $_COOKIE[LOGGED_IN_COOKIE]=$cookie;self::assertSame($actor,wp_validate_auth_cookie($cookie,'logged_in'));self::assertSame($token,wp_get_session_token());
        $start=$workflow->begin($b,wp_get_session_token());self::assertIsArray($start);parse_str(parse_url($start['authorization_url'],PHP_URL_QUERY),$q);
        $json=static fn(array $r):array=>['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>json_encode($r)];
        $http->responses=[$json(['access_token'=>'synthetic-access','refresh_token'=>'synthetic-refresh','token_type'=>'Bearer','expires_in'=>3600,'scope'=>'https://www.googleapis.com/auth/drive.file']),$json(['user'=>['permissionId'=>'synthetic-account']])];
        $_SERVER['REQUEST_METHOD']='GET';$_GET=['action'=>'digiforge_google_oauth_callback','state'=>$q['state'],'code'=>'synthetic-code'];
        $destination='';$redirect=static function($url)use(&$destination){$destination=$url;throw new RuntimeException('google_test_redirect');};add_filter('wp_redirect',$redirect);
        try{try{$runtime->callback();self::fail('Redirect expected.');}catch(RuntimeException $e){self::assertSame('google_test_redirect',$e->getMessage());}
            self::assertSame(home_url('/?df_view=digital&df_shop=digital&df_google=connected&df_google_plan='.$b['id']),$destination);self::assertStringNotContainsString($q['state'],$destination);self::assertStringNotContainsString('synthetic-code',$destination);
        }finally{remove_filter('wp_redirect',$redirect);$_GET=$oldGet;if($oldCookie===null)unset($_COOKIE[LOGGED_IN_COOKIE]);else $_COOKIE[LOGGED_IN_COOKIE]=$oldCookie;if($method===null)unset($_SERVER['REQUEST_METHOD']);else $_SERVER['REQUEST_METHOD']=$method;}
        self::assertSame(2,$http->calls);
        $queued=$workflow->submit($b['id']);global $wpdb;
        $job=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::jobs().' WHERE idempotency_key=%s','google:'.hash('sha256',$b['id'].':folder')));self::assertGreaterThan(0,$job);
        $row=$jobs->read($job);if($row['state']==='BLOCKED')self::assertTrue((new JobRepository())->transition($job,'BLOCKED','QUEUED'));
        $http->responses=[$json(['id'=>\DigiForge\DigitalFactory\DriveTransferRequest::FOLDER,'mimeType'=>'application/vnd.google-apps.folder','trashed'=>false,'capabilities'=>['canAddChildren'=>true]])];
        wp_set_current_user(0);$runtime->run($job);self::assertSame(0,get_current_user_id());self::assertSame(3,$http->calls);self::assertSame('SUCCESS',$jobs->read($job)['state']);
        $runtime->run($job);self::assertSame(3,$http->calls);
    }

}
