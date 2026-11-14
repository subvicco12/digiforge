<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/PortalAccessFixture.php';

final class ShopAccessCanonicalReadTest extends WP_UnitTestCase
{
    protected function setUp():void
    {
        parent::setUp();DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();PortalAccessFixture::reset();
        PortalAccessFixture::enroll();
    }
    protected function tearDown():void { PortalAccessFixture::reset();parent::tearDown(); }

    public function testBatchEqualsIndependentReadsAndUsesThreeFreshQueries():void
    {
        $repo=new DigiForge\Security\ShopAccessRepository();$expected=[];
        foreach(DigiForge\Security\ShopAccessPolicy::canonicalShops() as $shop)$expected[$shop]=$repo->read($shop);
        global $wpdb;$before=$wpdb->num_queries;
        self::assertSame($expected,$repo->readCanonicalShops());
        self::assertSame(3,$wpdb->num_queries-$before);
        self::assertSame($expected,$repo->readCanonicalShops(),'A subsequent call must read fresh storage, not a cached authority.');
    }

    public static function corruptions():array
    {
        return array_map(static fn($x)=>[$x],['missing_event','changed_event','missing_audit','changed_audit','missing_snapshot','foreign_snapshot','malformed_snapshot']);
    }
    /** @dataProvider corruptions */
    public function testFreshBatchRejectsTamperingInEveryRetainedSide(string $mode):void
    {
        global $wpdb;$repo=new DigiForge\Security\ShopAccessRepository();
        $profile=DigiForge\PortalAccess\Store::read(1);$id=$profile['global_grants']['digital'];
        self::assertIsArray($repo->readCanonicalShops());
        $event='digiforge_shop_access_event_'.$id;$key='digiforge_shop_access_digital';
        // These exact keys are discovered from the real snapshot rather than supplied as authority.
        $options=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'digiforge_shop_access_%'",ARRAY_A);
        foreach($options as $row){$v=json_decode($row['option_value'],true);if(is_array($v)&&isset($v['grants'][$id]))$key=$row['option_name'];}
        if($mode==='missing_event')$wpdb->delete($wpdb->options,['option_name'=>$event]);
        elseif($mode==='changed_event')$wpdb->update($wpdb->options,['option_value'=>'{}'],['option_name'=>$event]);
        elseif($mode==='missing_audit')$wpdb->delete(DigiForge\Database\Tables::audit_log(),['object_type'=>'shop_access','object_id'=>$id]);
        elseif($mode==='changed_audit')$wpdb->update(DigiForge\Database\Tables::audit_log(),['context'=>'{}'],['object_type'=>'shop_access','object_id'=>$id]);
        elseif($mode==='missing_snapshot')$wpdb->delete($wpdb->options,['option_name'=>$key]);
        elseif($mode==='foreign_snapshot')$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($repo->read('jewelry'))],['option_name'=>$key]);
        else $wpdb->update($wpdb->options,['option_value'=>'not-json'],['option_name'=>$key]);
        self::assertWPError($repo->read('digital'));
        self::assertWPError($repo->readCanonicalShops());
        self::assertFalse(DigiForge\PortalAccess\GlobalOwnerAuthority::active($profile));
    }

    public function testRevocationIsFreshAndRolledBackSnapshotCannotHideIt():void
    {
        global $wpdb;$repo=new DigiForge\Security\ShopAccessRepository();
        $owner=self::factory()->user->create(['role'=>'administrator']);$subject=self::factory()->user->create(['role'=>'administrator']);$caps=['manage_digiforge_research'];
        ShopAccessFixture::provision([ShopAccessFixture::authority($owner,'digital',$caps)]);
        $grant=ShopAccessFixture::grant($owner,$subject,'digital',$caps);$before=$repo->readCanonicalShops();
        wp_set_current_user($owner);
        self::assertNotWPError($repo->revoke('digital',$grant['grant_id'],wp_generate_uuid4(),$grant['revision']));
        $after=$repo->readCanonicalShops();self::assertIsArray($after);self::assertNotSame($before,$after);
        foreach($after as $shop=>$state)self::assertSame($repo->read($shop),$state);
        $row=$wpdb->get_row($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value=%s LIMIT 1",$wpdb->esc_like('digiforge_shop_access_').'%',wp_json_encode($after['digital'])),ARRAY_A);
        self::assertIsArray($row);self::assertSame(1,$wpdb->update($wpdb->options,['option_value'=>wp_json_encode($before['digital'])],['option_name'=>$row['option_name']]));
        self::assertWPError($repo->readCanonicalShops());
    }

    public function testDatabaseFailureCannotBecomeAnEmptyVerifiedLedger():void
    {
        global $wpdb;$previous=$wpdb->suppress_errors(true);
        $fault=static fn(string $q):string=>str_starts_with($q,'SELECT option_name,option_value FROM ')?'SELECT FROM /* isolated canonical read outage */':$q;
        add_filter('query',$fault);
        try{self::assertWPError((new DigiForge\Security\ShopAccessRepository())->readCanonicalShops());}
        finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
    }

    public function testEmptyLedgersRemainEquivalentWithoutProvisioning():void
    {
        PortalAccessFixture::reset();$repo=new DigiForge\Security\ShopAccessRepository();$before=$repo->readCanonicalShops();
        self::assertIsArray($before);
        foreach(DigiForge\Security\ShopAccessPolicy::canonicalShops() as $shop)self::assertSame($repo->read($shop),$before[$shop]);
        self::assertSame($before,$repo->readCanonicalShops());
    }

    public function testPerShopHistoryLimitIsNotReplacedByTheCombinedLimit():void
    {
        global $wpdb;$parts=[];
        for($i=0;$i<1001;$i++)$parts[]=$wpdb->prepare("(%s,%s,'no')",'digiforge_shop_access_event_'.hash('sha256','isolated-overflow-'.$i),'{"shop":"digital"}');
        self::assertNotFalse($wpdb->query("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES ".implode(',',$parts)));
        self::assertWPError((new DigiForge\Security\ShopAccessRepository())->readCanonicalShops());
    }
}
