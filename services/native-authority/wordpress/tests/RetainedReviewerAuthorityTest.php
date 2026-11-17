<?php
declare(strict_types=1);
require_once dirname(__DIR__,4).'/tests/wordpress/fixtures/PortalAccessFixture.php';
if (is_file(dirname(__DIR__).'/bootstrap.php')) require_once dirname(__DIR__).'/bootstrap.php';

use DigiForge\NativeAuthority\WordPress\{RetainedReviewerAuthority,OfflineSchema,RetainedReviewerLease};
use DigiForge\PortalAccess\{Store,Session};

final class RetainedReviewerAuthorityTest extends WP_UnitTestCase
{
    private int $reviewer;
    protected function setUp():void {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        PortalAccessFixture::reset();
        PortalAccessFixture::enroll();
        $this->reviewer=self::factory()->user->create(['role'=>'administrator']);
        $saved=Store::change($this->reviewer,'Operator',['personalized_pod'=>['orders','personalized_pod']],true,0,wp_generate_uuid4());
        self::assertIsArray($saved);
        self::assertTrue(Session::start());
        global $wpdb;$wpdb->query('COMMIT');$wpdb->query('SET autocommit=1');
        remove_filter('query',[$this,'_create_temporary_tables']);remove_filter('query',[$this,'_drop_temporary_tables']);
    }
    protected function tearDown():void {
        global $wpdb;$wpdb->query('ROLLBACK');
        if(class_exists(OfflineSchema::class)) OfflineSchema::uninstallForDisposableTests();
        PortalAccessFixture::reset();$wpdb->query('SET autocommit=0');
        parent::tearDown();
    }
    public function testGenuineForeignReviewerCanBePinnedWithoutReviewerLogin():void {
        self::assertTrue(class_exists(RetainedReviewerAuthority::class));
        self::assertSame('db-gpo-native-reviewer-v1',RetainedReviewerAuthority::ABI_VERSION);
        self::assertTrue(OfflineSchema::installForDisposableTests());
        self::assertSame([],WP_Session_Tokens::get_instance($this->reviewer)->get_all());
        $lease=RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer);
        self::assertInstanceOf(RetainedReviewerLease::class,$lease,is_wp_error($lease)?$lease->get_error_code():'');
        global $wpdb;$wpdb->query('START TRANSACTION');
        self::assertTrue($lease->pin());
        self::assertTrue($lease->validate());
        self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
        self::assertSame(1,get_current_user_id());
        self::assertSame([],WP_Session_Tokens::get_instance($this->reviewer)->get_all());
        self::assertNotFalse($wpdb->query('COMMIT'));
        self::assertTrue($lease->release());
        self::assertTrue($lease->finalAdmission());
        self::assertWPError($lease->pin());
    }
    private function lease():RetainedReviewerLease {
        self::assertTrue(OfflineSchema::installForDisposableTests());
        $lease=RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer);
        self::assertInstanceOf(RetainedReviewerLease::class,$lease,is_wp_error($lease)?$lease->get_error_code():'');
        return $lease;
    }
    private function other():wpdb {
        global $wpdb;$other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$other->set_prefix($wpdb->prefix);$other->suppress_errors(true);
        self::assertNotSame($wpdb->get_var('SELECT CONNECTION_ID()'),$other->get_var('SELECT CONNECTION_ID()'));
        return $other;
    }
    public static function authorityWrites():array {
        return array_combine(['native_cap','role_option','profile','acl','owner','grantor_native','authority_audit'],array_map(static fn($v)=>[$v],['native_cap','role_option','profile','acl','owner','grantor_native','authority_audit']));
    }
    /** @dataProvider authorityWrites */
    public function testActualSecondConnectionAuthorityWritesInvalidateCapture(string $kind):void {
        $lease=$this->lease();global $wpdb;$primary=$wpdb;$other=$this->other();
        $before=$other->get_var('SELECT generation FROM `'.OfflineSchema::generationTable($other).'` WHERE singleton=1');
        try {
            $wpdb=$other;
            if($kind==='native_cap'){ $u=new WP_User($this->reviewer);$u->add_cap('manage_digiforge_orders',false); }
            elseif($kind==='grantor_native'){ $u=new WP_User(1);$u->add_cap('manage_digiforge_settings',false); }
            elseif($kind==='role_option'){ get_role('administrator')->remove_cap('manage_digiforge_orders'); }
            elseif($kind==='profile'){ $old=Store::read($this->reviewer);self::assertIsArray(Store::change($this->reviewer,'Operator',['personalized_pod'=>['orders','personalized_pod']],false,$old['revision'],wp_generate_uuid4())); }
            elseif($kind==='acl'){ $repo=new DigiForge\Security\ShopAccessRepository();$state=$repo->read('personalized_pod');$id=null;foreach($state['grants'] as $key=>$g)if($g['actor_id']===$this->reviewer&&!$g['revoked'])$id=$key;self::assertIsString($id);self::assertIsArray($repo->revoke('personalized_pod',$id,wp_generate_uuid4(),$state['revision'])); }
            elseif($kind==='owner'){ self::assertIsArray(DigiForge\PortalAccess\OwnerBootstrap::revoke(wp_generate_uuid4())); }
            else {self::assertSame(1,$other->delete(DigiForge\Database\Tables::audit_log(),['object_type'=>'portal_auth','event_type'=>'portal_global_owner_claimed'],['%s','%s']));}
            $after=$other->get_var('SELECT generation FROM `'.OfflineSchema::generationTable($other).'` WHERE singleton=1');
            self::assertGreaterThan((int)$before,(int)$after);
        } finally {$wpdb=$primary;$other->close();}
        $primary->query('START TRANSACTION');self::assertWPError($lease->pin());
        self::assertSame('1',(string)$primary->get_var('SELECT @@session.in_transaction'));
        $primary->query('ROLLBACK');self::assertTrue($lease->release());self::assertWPError($lease->finalAdmission());
    }
    public function testPinnedGenerationBlocksRealWriterButBusinessAuditDoesNotTouchGeneration():void {
        $lease=$this->lease();global $wpdb;$wpdb->query('START TRANSACTION');self::assertTrue($lease->pin());$other=$this->other();
        try {
            $other->query('SET SESSION innodb_lock_wait_timeout=1');
            $before=$other->get_var('SELECT generation FROM `'.OfflineSchema::generationTable($other).'` WHERE singleton=1');
            self::assertSame(1,$other->insert(DigiForge\Database\Tables::audit_log(),['event_type'=>'offline_business_observation','actor_id'=>1,'object_type'=>'order','object_id'=>'synthetic','context'=>'{}','created_at'=>current_time('mysql',true)]));
            self::assertSame($before,$other->get_var('SELECT generation FROM `'.OfflineSchema::generationTable($other).'` WHERE singleton=1'));
            $raw=$other->get_var($other->prepare('SELECT meta_value FROM '.$other->usermeta.' WHERE user_id=%d AND meta_key=%s',$this->reviewer,$other->get_blog_prefix().'capabilities'));
            self::assertFalse($other->query($other->prepare('UPDATE '.$other->usermeta.' SET meta_value=%s WHERE user_id=%d AND meta_key=%s',$raw,$this->reviewer,$other->get_blog_prefix().'capabilities')));
            self::assertStringContainsString('Lock wait timeout',$other->last_error);
            self::assertTrue($lease->validate());
            $wpdb->query('COMMIT');
            self::assertNotFalse($other->query($other->prepare('UPDATE '.$other->usermeta.' SET meta_value=%s WHERE user_id=%d AND meta_key=%s',$raw,$this->reviewer,$other->get_blog_prefix().'capabilities')));
            self::assertGreaterThan((int)$before,(int)$other->get_var('SELECT generation FROM `'.OfflineSchema::generationTable($other).'` WHERE singleton=1'));
            self::assertTrue($lease->release());self::assertWPError($lease->finalAdmission());
        }finally{$wpdb->query('ROLLBACK');$other->close();}
    }
    public function testRollbackAndNewTransactionCannotReusePinnedProofOrAcknowledgeCommit():void {
        $lease=$this->lease();global $wpdb;$wpdb->query('START TRANSACTION');self::assertTrue($lease->pin());
        $wpdb->query('ROLLBACK');$wpdb->query('START TRANSACTION');
        self::assertWPError($lease->validate());self::assertWPError($lease->pin());
        self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
        $wpdb->query('ROLLBACK');self::assertTrue($lease->release());self::assertWPError($lease->finalAdmission());
    }
    public function testCaptureDenialPreservesActualCallerPendingWrite():void {
        self::assertTrue(OfflineSchema::installForDisposableTests());global $wpdb;
        $wpdb->query('START TRANSACTION');$key='retained-pending-'.wp_generate_uuid4();
        self::assertSame(1,$wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>'pending','autoload'=>'no']));
        self::assertWPError(RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer));
        self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
        self::assertSame('pending',$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key)));
        $wpdb->query('ROLLBACK');self::assertNull($wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$key)));
    }
    public function testExpiryWithoutSqlAndPostcommitExpiryDenyPrivateAcknowledgement():void {
        $token=WP_Session_Tokens::get_instance(1)->create(time()+3);
        $_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie(1,time()+3,'logged_in',$token);self::assertTrue(Session::start());
        $lease=$this->lease();global $wpdb;$wpdb->query('START TRANSACTION');self::assertTrue($lease->pin());self::assertTrue($lease->validate());$wpdb->query('COMMIT');
        sleep(4);self::assertTrue($lease->release());self::assertWPError($lease->finalAdmission());
    }
    public function testCustomCapabilityAndExternalCacheBackendAreDenied():void {
        self::assertTrue(OfflineSchema::installForDisposableTests());
        $custom=static fn($caps)=>$caps;add_filter('user_has_cap',$custom);
        try{self::assertWPError(RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer));}finally{remove_filter('user_has_cap',$custom);}
        wp_using_ext_object_cache(true);
        try{self::assertWPError(RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer));}finally{wp_using_ext_object_cache(false);}
    }
    public function testGenerationResetAndMissingTriggerCannotAuthorize():void {
        self::assertTrue(OfflineSchema::installForDisposableTests());global $wpdb;$quiet=$wpdb->suppress_errors(true);
        try{
            self::assertFalse($wpdb->query('DELETE FROM `'.OfflineSchema::generationTable($wpdb).'`'));
            self::assertFalse($wpdb->query('UPDATE `'.OfflineSchema::generationTable($wpdb).'` SET generation=0 WHERE singleton=1'));
            $name=array_key_first(OfflineSchema::triggers($wpdb));self::assertNotFalse($wpdb->query('DROP TRIGGER `'.$name.'`'));
            self::assertWPError(RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer));
        }finally{$wpdb->suppress_errors($quiet);}
    }
    public function testLeaseCannotBeClonedSerializedOrUsedByDifferentActor():void {
        $lease=$this->lease();
        try{serialize($lease);self::fail('Serialization must throw');}catch(LogicException){self::assertTrue(true);}
        try{$copy=clone $lease;self::fail('Clone must throw');}catch(Error){self::assertTrue(true);}
        global $wpdb;wp_set_current_user($this->reviewer);$wpdb->query('START TRANSACTION');self::assertWPError($lease->pin());
        $wpdb->query('ROLLBACK');
    }

    public static function customReadHooks():array{return [['pre_wp_load_alloptions'],['pre_cache_alloptions'],['alloptions'],['update_user_metadata_cache'],['default_user_metadata'],['auth_cookie'],['wp_roles_init'],['clean_user_cache'],['set_current_user']];}
    /** @dataProvider customReadHooks */
    public function testCustomNativeReadBackendsAreExplicitlyDenied(string $hook):void {
        self::assertTrue(OfflineSchema::installForDisposableTests());$custom=static fn($v)=>$v;add_filter($hook,$custom);
        try{self::assertWPError(RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer));}finally{remove_filter($hook,$custom);}
    }

    public function testDroppedAuxiliaryConnectionPermanentlyDeniesWithoutResettingCallerFrame():void {
        $lease=$this->lease();global $wpdb;$wpdb->query('START TRANSACTION');self::assertTrue($lease->pin());
        $property=new ReflectionProperty($lease,'aux');$aux=$property->getValue($lease);self::assertTrue($aux->close());
        self::assertWPError($lease->validate());self::assertSame('1',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
        self::assertWPError($lease->validate());$wpdb->query('ROLLBACK');self::assertWPError($lease->release());
    }
    public function testUnconfirmedReleaseCannotBeRetriedOrReturnPrivateAdmission():void {
        $lease=$this->lease();global $wpdb;$wpdb->query('START TRANSACTION');self::assertTrue($lease->pin());$wpdb->query('COMMIT');
        $hit=false;$fault=static function(string $sql)use(&$hit):string{if(str_starts_with($sql,'UPDATE `')&&str_contains($sql,'digiforge_reviewer_authority_leases')&&str_contains($sql,"SET state='RELEASED'")){$hit=true;return 'SELECT 1 AS unconfirmed_release';}return $sql;};
        add_filter('query',$fault);
        try{self::assertWPError($lease->release());self::assertTrue($hit);self::assertWPError($lease->finalAdmission());self::assertWPError($lease->release());}finally{remove_filter('query',$fault);}
        self::assertSame('0',(string)$wpdb->get_var('SELECT @@session.in_transaction'));
    }
    public function testLastFinalAdmissionReadCannotMaskDecisionActorLogout():void {
        $lease=$this->lease();global $wpdb;$wpdb->query('START TRANSACTION');self::assertTrue($lease->pin());$wpdb->query('COMMIT');self::assertTrue($lease->release());
        $reads=0;$fault=static function(string $sql)use(&$reads):string{if(str_starts_with($sql,'SELECT generation FROM `')&&++$reads===2)wp_set_current_user(0);return $sql;};
        add_filter('query',$fault);
        try{self::assertWPError($lease->finalAdmission());self::assertSame(2,$reads);self::assertSame(0,get_current_user_id());}finally{remove_filter('query',$fault);}
    }

    public function testActualFileDelegatedGrantIsUnsupportedEvenWhenPublicAccessSucceeds():void {
        $old=Store::read($this->reviewer);
        self::assertIsArray(Store::change($this->reviewer,'Admin',['personalized_pod'=>['orders','personalized_pod','settings']],true,$old['revision'],wp_generate_uuid4()));
        $target=self::factory()->user->create(['role'=>'administrator']);
        SyntheticShopAuthority::write([['actor_id'=>$this->reviewer,'shop'=>'personalized_pod','caps'=>DigiForge\Core\Capabilities::ALL,'expires_at'=>time()+3600,'source_approval_hash'=>hash('sha256','offline-delegate'),'authority'=>'DELEGATE']]);
        PortalAccessFixture::login($this->reviewer);self::assertTrue(Session::start());
        $repo=new DigiForge\Security\ShopAccessRepository();$state=$repo->read('personalized_pod');
        self::assertIsArray($repo->grant($target,'personalized_pod','administrator',['manage_digiforge_orders','manage_digiforge_pod'],time()+60,wp_generate_uuid4(),$state['revision']));
        PortalAccessFixture::login(1);self::assertTrue(Session::start());
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('personalized_pod','manage_digiforge_orders',$target));
        self::assertTrue(OfflineSchema::installForDisposableTests());
        $result=RetainedReviewerAuthority::capture('personalized_pod',$target);self::assertWPError($result);self::assertSame('retained_reviewer_file_delegate_unsupported',$result->get_error_code());
    }

    public function testAuthorityWriteDuringFinalCallerWitnessReadDeniesPrecommitValidation():void {
        $lease=$this->lease();global $wpdb;$wpdb->query('START TRANSACTION');self::assertTrue($lease->pin());
        $hit=false;$fault=static function(string $sql)use(&$hit,$wpdb):string{if(!$hit&&str_starts_with($sql,'SELECT commit_marker FROM `')){$hit=true;$wpdb->query($wpdb->prepare('UPDATE '.$wpdb->options.' SET option_value=%s WHERE option_name=%s','{}',Store::key(1)));}return $sql;};
        add_filter('query',$fault);
        try{self::assertWPError($lease->validate());self::assertTrue($hit);}finally{remove_filter('query',$fault);$wpdb->query('ROLLBACK');}
    }

    public function testCustomInProcessCacheCannotHideBehindExternalCacheFalse():void {
        self::assertTrue(OfflineSchema::installForDisposableTests());
        $old=$GLOBALS['wp_object_cache'];$oldFlag=wp_using_ext_object_cache();wp_using_ext_object_cache(false);$GLOBALS['wp_object_cache']=new class extends WP_Object_Cache {};
        try{
            self::assertFalse(wp_using_ext_object_cache());
            self::assertWPError(RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer));
        }finally{$GLOBALS['wp_object_cache']=$old;$GLOBALS['_wp_using_ext_object_cache']=$oldFlag;}
    }

    public function testCustomNativeRolesObjectIsNotAQualifiedDatabaseBackend():void {
        self::assertTrue(OfflineSchema::installForDisposableTests());
        $old=$GLOBALS['wp_roles'];$GLOBALS['wp_roles']=new class extends WP_Roles {};
        try{self::assertWPError(RetainedReviewerAuthority::capture('personalized_pod',$this->reviewer));}finally{$GLOBALS['wp_roles']=$old;}
    }

}
