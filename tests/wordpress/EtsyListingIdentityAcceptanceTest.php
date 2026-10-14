<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyOrderListingResolver;

/** Source p133: synthetic persisted external identity joins, never provider certification. */
final class EtsyListingIdentityAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();global $wpdb;self::assertNotFalse($wpdb->query('START TRANSACTION'));wp_set_current_user(0);
    }
    private function fixture(array $changes=[]): array
    {
        global $wpdb;$now=current_time('mysql',true);$times=['created_at'=>$now,'updated_at'=>$now];
        self::assertSame(1,$wpdb->insert(Tables::product_versions(),$times+['product_id'=>1,'version_label'=>'identity-'.wp_generate_uuid4(),'state'=>$changes['version_state']??'APPROVED']));$version=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::digital_products(),$times+['product_id'=>1,'product_version_id'=>$version,'name'=>'Synthetic digital identity','category'=>'fixture']));
        self::assertSame(1,$wpdb->insert(Tables::listings(),$times+['product_version_id'=>$version,'channel'=>$changes['channel']??'etsy','environment'=>$changes['listing_environment']??'production','shop_reference'=>$changes['listing_shop']??'812002','title'=>'Synthetic historical listing','state'=>$changes['listing_state']??'APPROVED']));$listing=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::etsy_intents(),$times+['listing_id'=>$listing,'environment'=>$changes['intent_environment']??'production','intent_type'=>'PREPARE_DRAFT','state'=>$changes['intent_state']??'APPROVED_INTENT']));$intent=(int)$wpdb->insert_id;$hash=hash('sha256','synthetic identity lookup');
        self::assertSame(1,$wpdb->insert($wpdb->prefix.'digiforge_etsy_operations',$times+['shop_reference'=>'812002','intent_id'=>$intent,'draft_package_id'=>0,'operation_type'=>'CREATE_DRAFT','state'=>'CONFIRMED_SUCCESS','idempotency_key'=>wp_generate_uuid4(),'request_fingerprint'=>$hash,'authorization_hash'=>$hash,'evidence_hash'=>$hash,'external_reference'=>'812003']));$operation=(int)$wpdb->insert_id;
        if(!empty($changes['missing_version']))self::assertSame(1,$wpdb->delete(Tables::product_versions(),['id'=>$version]));
        return compact('version','listing','intent','operation');
    }
    /** @dataProvider approvedProductStates */
    public function testOneApprovedProductionDigitalListingResolvesExactStoredIdentity(string $state): void
    {
        $f=$this->fixture(['version_state'=>$state]);self::assertSame(['listing_id'=>$f['listing'],'product_version_id'=>$f['version']],EtsyOrderListingResolver::resolve('812003','812002'));
        self::assertNull(EtsyOrderListingResolver::resolve('812003','812099'));self::assertNull(EtsyOrderListingResolver::resolve('812099','812002'));
    }
    public function approvedProductStates(): array{return [['APPROVED'],['RELEASED']];}
    public function testCanonicalDigitalShopResolvesOnlyThroughMatchingStoredProductionIdentity(): void
    {
        $f=$this->fixture(['listing_shop'=>'digital']);global $wpdb;$now=current_time('mysql',true);
        self::assertSame(1,$wpdb->insert(Tables::integrations(),['provider'=>'etsy','environment'=>'production','connection_key'=>'identity-map-'.wp_generate_uuid4(),'display_name'=>'Synthetic stored identity mapping','status'=>'CONFIGURED','enabled'=>0,'config'=>wp_json_encode(['_connection_test'=>['ok'=>true,'details'=>['shop_id'=>'812002','shop_name'=>'digital']]]),'created_at'=>$now,'updated_at'=>$now]));$connection=(int)$wpdb->insert_id;
        self::assertSame(['listing_id'=>$f['listing'],'product_version_id'=>$f['version']],EtsyOrderListingResolver::resolve('812003','812002'));
        self::assertSame(1,$wpdb->update(Tables::integrations(),['environment'=>'sandbox'],['id'=>$connection]));self::assertNull(EtsyOrderListingResolver::resolve('812003','812002'));
    }
    /** @dataProvider incongruentEvidence */
    public function testWrongShopEnvironmentChannelAndUnapprovedProductCannotResolve(array $changes): void
    {
        $this->fixture($changes);self::assertNull(EtsyOrderListingResolver::resolve('812003','812002'));
    }
    public function incongruentEvidence(): array
    {
        return ['wrong listing shop'=>[['listing_shop'=>'812004']],'sandbox listing'=>[['listing_environment'=>'sandbox']],'sandbox intent'=>[['intent_environment'=>'sandbox']],'both sandbox'=>[['listing_environment'=>'sandbox','intent_environment'=>'sandbox']],'wrong channel'=>[['channel'=>'manual']],'draft product'=>[['version_state'=>'DRAFT']],'review product'=>[['version_state'=>'REVIEW']],'retired product'=>[['version_state'=>'RETIRED']],'missing product'=>[['missing_version'=>true]],'draft listing'=>[['listing_state'=>'DRAFT']],'blocked intent'=>[['intent_state'=>'BLOCKED']]];
    }
    public function testDuplicateInternalResolutionsAreAmbiguousButDuplicateEvidenceForOneIdentityIsValid(): void
    {
        $one=$this->fixture();global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'digiforge_etsy_operations WHERE id=%d',$one['operation']),ARRAY_A);unset($row['id']);$row['idempotency_key']=wp_generate_uuid4();self::assertSame(1,$wpdb->insert($wpdb->prefix.'digiforge_etsy_operations',$row));
        self::assertSame(['listing_id'=>$one['listing'],'product_version_id'=>$one['version']],EtsyOrderListingResolver::resolve('812003','812002'));
        $this->fixture();self::assertNull(EtsyOrderListingResolver::resolve('812003','812002'));
    }
    public function testCurrentProductApprovalAndListingShopAreRechecked(): void
    {
        $f=$this->fixture();self::assertIsArray(EtsyOrderListingResolver::resolve('812003','812002'));global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::product_versions(),['state'=>'DRAFT'],['id'=>$f['version']]));self::assertNull(EtsyOrderListingResolver::resolve('812003','812002'));
        self::assertSame(1,$wpdb->update(Tables::product_versions(),['state'=>'APPROVED'],['id'=>$f['version']]));self::assertIsArray(EtsyOrderListingResolver::resolve('812003','812002'));
        self::assertSame(1,$wpdb->update(Tables::listings(),['shop_reference'=>'812004'],['id'=>$f['listing']]));self::assertNull(EtsyOrderListingResolver::resolve('812003','812002'));
    }
    public function testResolverReadFailureRemainsUnavailable(): void
    {
        $this->fixture();global $wpdb;$table=Tables::listings();$fault=static fn(string $sql):string=>str_starts_with(ltrim($sql),'SELECT')&&str_contains($sql,$table)?str_replace($table,$table.'_synthetic_missing',$sql):$sql;$previous=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$result=EtsyOrderListingResolver::resolve('812003','812002');}finally{remove_filter('query',$fault);$wpdb->suppress_errors($previous);}
        self::assertWPError($result);self::assertSame('digiforge_etsy_order_listing_evidence_unavailable',$result->get_error_code());self::assertSame(503,$result->get_error_data()['status']);
    }
}
