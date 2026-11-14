<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Research\Repository as Research;
use DigiForge\ProductFactory\Repository as Products;
use DigiForge\POD\Repository as Pod;

/** Source p104: local reviewed-concept retention and alternative research, not supplier certification. */
final class SupplierAlternativeAcceptanceTest extends WP_UnitTestCase
{
    private int $httpCalls=0;
    private Closure $http;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge_research','manage_digiforge_products','manage_digiforge_production','manage_digiforge_pod']);
        $this->http=function(){++$this->httpCalls;return new WP_Error('offline_supplier_alternative','All actual HTTP forbidden.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->httpCalls);self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all',true));self::assertFalse(Settings::get('automation_armed',false));parent::tearDown();
    }
    private function row(string $table,int $id): array
    {
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id),ARRAY_A);self::assertIsArray($row);return $row;
    }
    private function evidence(Research $research,string $shop,string $provider,int $catalog,string $kind,string $note): array
    {
        $source=$research->createSource(['shop_key'=>$shop,'name'=>'Synthetic '.$provider.' local review '.wp_generate_uuid4(),'source_type'=>'manual','environment'=>'sandbox','config'=>['provider'=>$provider,'catalog_id'=>$catalog,'fixture_only'=>true]]);self::assertNotWPError($source);self::assertSame(0,(int)$source['enabled']);
        $observation=$research->ingest(['source_id'=>$source['id'],'external_id'=>'local-catalog-'.$catalog,'title'=>$note,'body'=>'Synthetic stored local review only; no live supplier evidence.','provenance'=>['provider'=>$provider,'catalog_id'=>$catalog,'fixture_only'=>true]]);self::assertNotWPError($observation);
        $evidence=$research->addEvidence((int)$observation['id'],['evidence_type'=>$kind,'value'=>$note,'provenance'=>['provider'=>$provider,'catalog_id'=>$catalog,'fixture_only'=>true]]);self::assertNotWPError($evidence);
        return compact('source','observation','evidence');
    }
    public function testRejectedFirstCatalogRetainsReviewedConceptAndAlternativeResearchCreatesOnlyDraftMapping(): void
    {
        $research=new Research();$products=new Products();$pod=new Pod();$shop='personalized_pod';$marker=wp_generate_uuid4();
        $first=$pod->createCatalog(['provider'=>'printify','environment'=>'sandbox','provider_product_key'=>'synthetic-first-'.$marker,'title'=>'Synthetic first local catalog hypothesis','attributes'=>['fixture_only'=>true],'availability_state'=>'UNKNOWN','source_revision'=>'synthetic-local-v1']);self::assertNotWPError($first);
        $original=$this->evidence($research,$shop,'printify',(int)$first['id'],'manual_note','Concept retained for local development; supplier suitability remains separate.');
        $candidate=$research->createCandidate(['shop_key'=>$shop,'title'=>'Retained local concept '.$marker,'summary'=>'Human-reviewed concept; no claimed market score or supplier certification.']);self::assertNotWPError($candidate);self::assertTrue($research->linkEvidence((int)$candidate['id'],(int)$original['evidence']['id']));
        // "Strong" is this explicit local review decision, never an invented numerical threshold.
        $review=$research->review((int)$candidate['id'],'APPROVED','Retain this concept for development even if the first supplier is unsuitable; review alternatives locally.');self::assertNotWPError($review);self::assertSame('APPROVED',$review['review_status']);
        $opportunity=$research->promote((int)$candidate['id']);self::assertNotWPError($opportunity);self::assertNotWPError($products->transition('opportunity',(int)$opportunity['id'],'QUALIFIED'));
        $family=$products->create('product_family',['opportunity_id'=>$opportunity['id'],'name'=>'Retained family '.$marker]);self::assertNotWPError($family);
        $product=$products->create('product',['product_family_id'=>$family['id'],'name'=>'Retained canonical product '.$marker]);self::assertNotWPError($product);
        $version=$products->create('product_version',['product_id'=>$product['id'],'version_label'=>'local-v1','notes'=>'Original local concept, not approved supplier material.']);self::assertNotWPError($version);
        $plan=(new DigiForge\Production\Repository())->createPlan(['product_version_id'=>$version['id'],'plan_key'=>'alternative-plan-'.$marker,'version_label'=>'local-v1','channel'=>'pod','production_type'=>'deterministic']);self::assertNotWPError($plan);
        $lineage=[Tables::research_candidates()=>(int)$candidate['id'],Tables::opportunities()=>(int)$opportunity['id'],Tables::product_families()=>(int)$family['id'],Tables::products()=>(int)$product['id'],Tables::product_versions()=>(int)$version['id'],Tables::production_plans()=>(int)$plan['id']];$before=[];foreach($lineage as $table=>$id)$before[$table]=$this->row($table,$id);
        $originalBefore=$this->row(Tables::research_observations(),(int)$original['observation']['id']);
        $rejected=$pod->transition('catalog',(int)$first['id'],'REJECTED');self::assertNotWPError($rejected);self::assertSame('REJECTED',$rejected['state']);
        $rejection=$research->addEvidence((int)$original['observation']['id'],['evidence_type'=>'supplier_rejection','value'=>'First local catalog hypothesis rejected; retain the already reviewed concept.','provenance'=>['provider'=>'printify','catalog_id'=>$first['id'],'fixture_only'=>true]]);self::assertNotWPError($rejection);self::assertTrue($research->linkEvidence((int)$candidate['id'],(int)$rejection['id']));
        $alternative=$pod->createCatalog(['provider'=>'gelato','environment'=>'sandbox','provider_product_key'=>'synthetic-alternative-'.$marker,'title'=>'Synthetic alternative local catalog hypothesis','attributes'=>['fixture_only'=>true],'availability_state'=>'UNKNOWN','source_revision'=>'synthetic-local-v1']);self::assertNotWPError($alternative);self::assertSame('DRAFT',$alternative['state']);
        $alternativeEvidence=$this->evidence($research,$shop,'gelato',(int)$alternative['id'],'alternative_supplier','Alternative provider hypothesis for the same retained local concept.');self::assertTrue($research->linkEvidence((int)$candidate['id'],(int)$alternativeEvidence['evidence']['id']));
        $integration=(new DigiForge\Integrations\Repository())->create(['provider'=>'gelato','environment'=>'sandbox','connection_key'=>'alternative-'.$marker,'display_name'=>'Disconnected synthetic alternative provider','status'=>'DISCONNECTED','enabled'=>false,'config'=>['fixture_only'=>true]]);self::assertNotWPError($integration);
        $mapping=$pod->createMapping(['product_version_id'=>$version['id'],'production_plan_id'=>$plan['id'],'provider'=>'gelato','environment'=>'sandbox','provider_product_key'=>$alternative['provider_product_key'],'mapping_version'=>'alternative-local-v1','notes'=>'Uncertified local hypothesis based on catalog #'.$alternative['id']]);self::assertNotWPError($mapping);
        self::assertSame('DRAFT',$mapping['state']);self::assertSame(0,(int)$mapping['approved_by']);self::assertNull($mapping['approved_at']);self::assertSame((int)$version['id'],(int)$mapping['product_version_id']);self::assertSame((int)$plan['id'],(int)$mapping['production_plan_id']);self::assertSame('gelato',$mapping['provider']);self::assertSame($alternative['provider_product_key'],$mapping['provider_product_key']);
        self::assertWPError((new DigiForge\POD\BusinessScopeRepository())->assertActiveOwnershipForMapping((int)$mapping['id']));self::assertWPError($pod->readiness((int)$mapping['id']));
        // Foreign research cannot become an alternative for this owned opportunity.
        $foreign=$this->evidence($research,'jewelry','gelato',(int)$alternative['id'],'alternative_supplier','Foreign shop hypothesis');self::assertWPError($research->linkEvidence((int)$candidate['id'],(int)$foreign['evidence']['id']));
        global $wpdb;$links=$wpdb->get_col($wpdb->prepare('SELECT evidence_id FROM '.Tables::research_candidate_evidence().' WHERE candidate_id=%d ORDER BY evidence_id',$candidate['id']));$expected=[(string)$original['evidence']['id'],(string)$rejection['id'],(string)$alternativeEvidence['evidence']['id']];sort($expected,SORT_NUMERIC);self::assertSame($expected,$links);
        $persisted=$this->row(Tables::research_evidence(),(int)$alternativeEvidence['evidence']['id']);self::assertSame($alternativeEvidence['observation']['id'],(int)$persisted['observation_id']);self::assertSame(['provider'=>'gelato','catalog_id'=>(string)$alternative['id'],'fixture_only'=>'1'],json_decode($persisted['provenance'],true));
        self::assertSame($alternativeEvidence['source']['id'],(int)$this->row(Tables::research_observations(),(int)$alternativeEvidence['observation']['id'])['source_id']);self::assertSame($shop,$alternativeEvidence['source']['config']['shop_key']);
        foreach($lineage as $table=>$id)self::assertSame($before[$table],$this->row($table,$id));self::assertSame($originalBefore,$this->row(Tables::research_observations(),(int)$original['observation']['id']));
        self::assertSame($opportunity['id'],$research->promote((int)$candidate['id'])['id']);self::assertSame('REJECTED',$this->row(Tables::pod_catalog(),(int)$first['id'])['state']);self::assertSame('DRAFT',$this->row(Tables::pod_catalog(),(int)$alternative['id'])['state']);self::assertSame('DISCONNECTED',$integration['status']);self::assertFalse($integration['enabled']);
        self::assertSame(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::integration_secrets().' WHERE integration_id=%d',$integration['id'])));
    }
}
