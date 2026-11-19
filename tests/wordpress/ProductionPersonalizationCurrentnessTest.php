<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ExistingRenderArtifactFixture.php';

use DigiForge\Database\Tables;
use DigiForge\Orders\Repository as Orders;
use DigiForge\POD\{DeterministicPersonalizationRenderer,RenderEvidenceRepository,ProductionAuthorizationRepository};

/** Production review cannot borrow an older immutable approval after buyer input changes. */
final class ProductionPersonalizationCurrentnessTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();\DigiForge\Core\Activator::activate();\DigiForge\Core\Settings::protectProduction();
        digiforge_reset_existing_render_fixture();
    }

    public function testUniqueCurrentInputCanCreateAndApproveLocalReviewPackage(): void
    {
        [$input,$render]=$this->fixture();$repo=new ProductionAuthorizationRepository();
        $package=$repo->create((int)$input['order_id'],(int)$render['id']);self::assertNotWPError($package);
        $approved=$repo->approveForReview((int)$package['id']);self::assertNotWPError($approved);
        self::assertSame('APPROVED_PACKAGE',$approved['state']);self::assertSame(0,(int)$approved['external_execution_authorized']);
    }

    public function testNewerUnreviewedRejectedAndApprovedInputCannotReuseOldPackage(): void
    {
        [$input,$render,$original]=$this->fixture();$repo=new ProductionAuthorizationRepository();global $wpdb;
        $package=$repo->create((int)$input['order_id'],(int)$render['id']);self::assertNotWPError($package);
        $accepted=[];
        foreach(['UNREVIEWED','REJECTED','APPROVED'] as $state){
            $second=(new Orders())->createPersonalization(['order_line_item_id'=>$original['order_line_item_id'],'personalization_schema_id'=>$original['personalization_schema_id'],'payload'=>['name'=>'Corrected '.$state]]);self::assertNotWPError($second);
            if($state!=='UNREVIEWED')self::assertNotWPError((new Orders())->reviewPersonalization((int)$second['id'],$state));
            $accepted[$state]=[!is_wp_error($repo->create((int)$input['order_id'],(int)$render['id'])),!is_wp_error($repo->approveForReview((int)$package['id']))];
        }
        $stored=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE id=%d',$original['id']),ARRAY_A);self::assertSame($original,$stored);
        self::assertSame(array_fill_keys(array_keys($accepted),[false,false]),$accepted);
    }

    public function testDuplicateMappedLineCannotBorrowAnotherLinesBuyerInput(): void
    {
        [$input,$render,$original]=$this->fixture();$repo=new ProductionAuthorizationRepository();
        $package=$repo->create((int)$input['order_id'],(int)$render['id']);self::assertNotWPError($package);
        global $wpdb;$other=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE id=%d',$original['order_line_item_id']),ARRAY_A);unset($other['id']);
        self::assertSame(1,$wpdb->insert(Tables::order_line_items(),$other));
        self::assertWPError($repo->create((int)$input['order_id'],(int)$render['id']));self::assertWPError($repo->approveForReview((int)$package['id']));
    }

    public function testNewerOrphanSchemaCannotDisappearFromCurrentness(): void
    {
        [$input,$render,$original]=$this->fixture();global $wpdb;$repo=new ProductionAuthorizationRepository();
        $package=$repo->create((int)$input['order_id'],(int)$render['id']);self::assertNotWPError($package);
        $row=$original;unset($row['id']);$row['personalization_schema_id']=PHP_INT_MAX;$row['canonical_payload']='{"name":"orphan"}';$row['payload_hash']=hash('sha256',$row['canonical_payload']);$row['review_status']='UNREVIEWED';$row['reviewed_by']=0;$row['reviewed_at']=null;
        self::assertSame(1,$wpdb->insert(Tables::personalization_submissions(),$row));
        self::assertWPError($repo->create((int)$input['order_id'],(int)$render['id']));self::assertWPError($repo->approveForReview((int)$package['id']));
    }

    public function testPreviouslyApprovedPackagePreflightRejectsNewUnresolvedBuyerInput(): void
    {
        [$input,$render,$original]=$this->fixture();global $wpdb;
        self::assertSame('0',$wpdb->get_var($wpdb->prepare('SELECT personalization_required FROM '.Tables::orders().' WHERE id=%d',$input['order_id'])),'Existing buyer input must be checked even when the order flag is false.');
        $repo=new ProductionAuthorizationRepository();$package=$repo->create((int)$input['order_id'],(int)$render['id']);self::assertNotWPError($package);self::assertNotWPError($repo->approveForReview((int)$package['id']));
        self::assertNotWPError((new Orders())->createPersonalization(['order_line_item_id'=>$original['order_line_item_id'],'personalization_schema_id'=>$original['personalization_schema_id'],'payload'=>['name'=>'Unresolved correction']]));
        $readiness=(new Orders())->readinessSnapshot((int)$input['order_id']);self::assertNotWPError($readiness);self::assertFalse($readiness['checks']['personalization_reviewed']);
        $preflight=(new \DigiForge\POD\PrintifyProductionPreflight())->evaluate((int)$package['id']);self::assertNotWPError($preflight);self::assertContains('ORDER_NOT_READY',$preflight['blockers']);self::assertFalse($preflight['ready_for_external_execution']);
        self::assertSame('APPROVED_PACKAGE',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::pod_authorization_packages().' WHERE id=%d',$package['id'])),'Retained approval evidence must not be rewritten.');
    }

    public function testResolverIsReadOnlyAndUnavailableEvidenceIsNotMissingInput(): void
    {
        [$input,,$original]=$this->fixture();$resolver=new \DigiForge\Orders\PersonalizationCurrentness();$queries=[];$http=0;
        $record=static function(string $sql)use(&$queries):string{$queries[]=$sql;return $sql;};
        $deny=static function()use(&$http){$http++;return new WP_Error('unexpected_http');};
        add_filter('query',$record);add_filter('pre_http_request',$deny);
        try {$current=$resolver->forRender((int)$input['order_id'],(int)$input['provider_mapping_id'],$input['personalization_evidence_hash']);}
        finally {remove_filter('query',$record);remove_filter('pre_http_request',$deny);}
        self::assertNotWPError($current);self::assertSame((int)$original['id'],$current['submission_id']);self::assertArrayNotHasKey('canonical_payload',$current);self::assertSame(0,$http);
        foreach($queries as $query)self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i',$query);
        $fault=static fn(string $sql):string=>str_contains($sql,'SELECT ps.id,ps.personalization_schema_id')?'SELECT * FROM currentness_missing_evidence':$sql;
        global $wpdb;$prior=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try {$failure=$resolver->forRender((int)$input['order_id'],(int)$input['provider_mapping_id'],$input['personalization_evidence_hash']);}
        finally {remove_filter('query',$fault);$wpdb->suppress_errors($prior);}
        self::assertWPError($failure);self::assertSame(503,$failure->get_error_data()['status']);
    }

    private function fixture(): array
    {
        $input=digiforge_existing_render_fixture('deterministicFixture');$renderer=new DeterministicPersonalizationRenderer();
        self::assertNotWPError($renderer->approveRecipe($input['template_key'],1,digiforge_existing_render_fixture('recipe')));
        $expected=$renderer->expected($input);self::assertNotWPError($expected);$path=tempnam(sys_get_temp_dir(),'production-currentness-');file_put_contents($path,$expected['svg']);
        try {$repo=new RenderEvidenceRepository();$render=$repo->createFromArtifacts($input,$path,$path);self::assertNotWPError($render);$render=$repo->approve((int)$render['id']);self::assertNotWPError($render);}
        finally {unlink($path);}
        global $wpdb;$original=$wpdb->get_row($wpdb->prepare('SELECT ps.* FROM '.Tables::personalization_submissions().' ps INNER JOIN '.Tables::order_line_items().' li ON li.id=ps.order_line_item_id WHERE li.order_id=%d AND li.provider_mapping_id=%d',$input['order_id'],$input['provider_mapping_id']),ARRAY_A);self::assertIsArray($original);
        return [$input,$render,$original];
    }
}
