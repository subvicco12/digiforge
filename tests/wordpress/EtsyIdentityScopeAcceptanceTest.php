<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Listings\EtsyOperationRepository;
use DigiForge\Listings\EtsyVerifiedShopIdentity;
require_once __DIR__.'/Support/SyntheticEtsyIdentity.php';

/** Stored synthetic identity only; no credentials, HTTP, activation or provider certification. */
final class EtsyIdentityScopeAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
    }

    private function integration(string $environment, mixed $shopId=987654321, mixed $name='SyntheticScopeShop'): int
    {
        $row=(new DigiForge\Integrations\Repository())->create([
            'provider'=>'etsy','environment'=>$environment,'connection_key'=>'identity-'.wp_generate_uuid4(),
            'display_name'=>'Synthetic identity only','status'=>'CONFIGURED','enabled'=>false,
            'config'=>['_connection_test'=>['ok'=>true,'details'=>['shop_id'=>$shopId,'shop_name'=>$name]]],
        ]);
        self::assertNotWPError($row);
        SyntheticEtsyIdentity::credential((int)$row['id']);
        $metadata=(new DigiForge\Integrations\Repository())->secretMetadata((int)$row['id']);
        self::assertNotWPError($metadata);
        $config=$row['config'];$config['_connection_test']['credential_evidence_version']=DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata($metadata);
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::integrations(),['config'=>wp_json_encode($config)],['id'=>$row['id']]));
        return (int)$row['id'];
    }

    private function operationInput(string $environment='production'): array
    {
        global $wpdb;
        $now=current_time('mysql',true);$hash=hash('sha256',wp_generate_uuid4());
        self::assertSame(1,$wpdb->insert(Tables::listings(),['product_version_id'=>1,'environment'=>$environment,'shop_reference'=>'SyntheticScopeShop','title'=>'Offline identity boundary','state'=>'APPROVED','approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]));
        $listing=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::etsy_draft_packages(),['listing_id'=>$listing,'package_version'=>'identity-v1','canonical_payload'=>'{}','payload_hash'=>$hash,'readiness'=>'{}','readiness_hash'=>$hash,'approved_by'=>get_current_user_id(),'approved_at'=>$now,'created_at'=>$now]));
        $package=(int)$wpdb->insert_id;
        self::assertSame(1,$wpdb->insert(Tables::etsy_intents(),['listing_id'=>$listing,'draft_package_id'=>$package,'environment'=>$environment,'intent_type'=>'PREPARE_DRAFT','state'=>'APPROVED_INTENT','created_at'=>$now,'updated_at'=>$now]));
        return ['shop_reference'=>'987654321','intent_id'=>(int)$wpdb->insert_id,'draft_package_id'=>$package,'operation_type'=>'CREATE_DRAFT','idempotency_key'=>'identity-'.wp_generate_uuid4(),'authorization_hash'=>$hash,'evidence_hash'=>$hash];
    }

    public function testNumericOperationCannotUseIdentityFromAnotherEnvironment(): void
    {
        $this->integration('sandbox');
        $result=(new EtsyOperationRepository())->createFromPayload($this->operationInput(),['title'=>'Offline only']);
        self::assertWPError($result);
        self::assertSame('digiforge_etsy_operation_shop_scope_mismatch',$result->get_error_code());
    }

    public function testResolutionSelectsOnlyTheExactEnvironmentAndRejectsAmbiguity(): void
    {
        $this->integration('sandbox');$production=$this->integration('production');
        $resolved=EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,'production');
        self::assertNotWPError($resolved);
        self::assertSame($production,$resolved['integration_id']);
        $this->integration('production');
        self::assertWPError(EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,'production'));
        self::assertWPError((new EtsyOperationRepository())->createFromPayload($this->operationInput(),['title'=>'Offline only']));
    }

    public function testConflictingNamesCannotHideDuplicateClaimsForTheSameSellerId(): void
    {
        $this->integration('production');$this->integration('production',987654321,'OtherSyntheticShop');
        self::assertWPError(EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,'production'));
        self::assertWPError(EtsyVerifiedShopIdentity::resolveAny('OtherSyntheticShop',987654321,'production'));
    }

    public function testIdempotentReplayRechecksCurrentIdentityInsteadOfReturningOldScope(): void
    {
        $id=$this->integration('production');$input=$this->operationInput();$repo=new EtsyOperationRepository();$payload=['title'=>'Offline only'];
        $created=$repo->createFromPayload($input,$payload);self::assertNotWPError($created);
        self::assertNotWPError($repo->createFromPayload($input,$payload));
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::integrations(),['environment'=>'sandbox'],['id'=>$id]));
        self::assertWPError($repo->createFromPayload($input,$payload));
        self::assertSame('NOT_SENT',$repo->find((int)$created['id'])['state']);
    }

    public function testPreparationRechecksEnvironmentBeforeReadinessOrPermitConsumption(): void
    {
        $id=$this->integration('production');$repo=new EtsyOperationRepository();
        $created=$repo->createFromPayload($this->operationInput(),['title'=>'Offline only']);self::assertNotWPError($created);
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::integrations(),['environment'=>'sandbox'],['id'=>$id]));
        $result=(new DigiForge\Listings\EtsyOperationPreparationService($repo))->prepare((int)$created['id'],[],(string)$created['evidence_hash'],get_current_user_id(),time(),['title'=>'Offline only']);
        self::assertWPError($result);
        self::assertSame('digiforge_etsy_preparation_listing_not_approved',$result->get_error_code());
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));
        self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }

    public function testReadFailureAfterAnEarlierMatchCannotEstablishUniqueIdentity(): void
    {
        $this->integration('production');$fault=$this->integration('production',987654322,'OtherSyntheticShop');
        $filter=static fn(string $sql):string=>str_starts_with($sql,'SELECT provider,status,config')&&str_contains($sql,'id='.$fault.' LIMIT 1')?'SELECT * FROM missing_identity_evidence':$sql;
        global $wpdb;$old=$wpdb->suppress_errors(true);add_filter('query',$filter);
        try{$result=EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,'production');}
        finally{remove_filter('query',$filter);$wpdb->suppress_errors($old);}
        self::assertWPError($result);
        self::assertSame(503,$result->get_error_data()['status']);
    }

    public function testIdentityEnvironmentIsRecheckedAfterEnumerationAndOnExplicitSelection(): void
    {
        $id=$this->integration('production');
        self::assertWPError(EtsyVerifiedShopIdentity::resolve($id,'SyntheticScopeShop',987654321,'sandbox'));
        $changed=false;
        $filter=static function(string $sql)use($id,&$changed):string{
            if(!$changed&&str_starts_with($sql,'SELECT provider,status,config')&&str_contains($sql,'id='.$id.' LIMIT 1')){
                $changed=true;global $wpdb;$wpdb->update(Tables::integrations(),['environment'=>'sandbox'],['id'=>$id]);
            }
            return $sql;
        };
        add_filter('query',$filter);
        try{$result=EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,'production');}
        finally{remove_filter('query',$filter);}
        self::assertTrue($changed);
        self::assertWPError($result);
    }

    public function testMalformedStoredIdentityCannotBeCoercedIntoAuthoritativeEvidence(): void
    {
        $id=$this->integration('production','987654321suffix');
        self::assertWPError(EtsyVerifiedShopIdentity::resolve($id,'SyntheticScopeShop',987654321));
        $array=$this->integration('production',987654321,['not'=>'a name']);
        self::assertWPError(EtsyVerifiedShopIdentity::resolve($array,'SyntheticScopeShop',987654321));
        self::assertWPError(EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,''));
    }

    public function testCredentialRotationInvalidatesExplicitAndNumericScopeUntilRetested(): void
    {
        $id=$this->integration('production');$integrations=new DigiForge\Integrations\Repository();
        self::assertNotWPError(EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,'production'));
        self::assertTrue($integrations->storeSecret($id,'access_token','offline-rotated-synthetic-credential'));
        self::assertWPError(EtsyVerifiedShopIdentity::resolve($id,'SyntheticScopeShop',987654321,'production'));
        self::assertWPError(EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,'production'));
        $row=$integrations->find($id);self::assertNotWPError($row);
        $metadata=$integrations->secretMetadata($id);self::assertNotWPError($metadata);
        $config=$row['config'];$config['_connection_test']['credential_evidence_version']=DigiForge\Integrations\CredentialEvidenceVersion::fromMetadata($metadata);
        global $wpdb;self::assertSame(1,$wpdb->update(Tables::integrations(),['config'=>wp_json_encode($config)],['id'=>$id]));
        self::assertNotWPError(EtsyVerifiedShopIdentity::resolveAny('SyntheticScopeShop',987654321,'production'));
    }
}
