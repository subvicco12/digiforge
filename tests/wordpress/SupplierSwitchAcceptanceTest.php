<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\POD\ProductMockupRegistry;
use DigiForge\POD\RenderArtifactVerifier;
use DigiForge\POD\RenderEvidenceRepository;

require_once __DIR__.'/fixtures/ExistingRenderArtifactFixture.php';

/** Current selected-route invalidation; historical approved identities remain immutable. */
final class SupplierSwitchAcceptanceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        digiforge_reset_existing_render_fixture();
    }

    private function approvedRender(): array
    {
        // Exact existing100×80 synthetic approved bleed fixture, not invented supplier dimensions.
        $area = ['position'=>'front', 'decoration_method'=>'dtg', 'width_px'=>100, 'height_px'=>80, 'dpi'=>300,
            'qa_geometry'=>['safe_zone'=>['x'=>5, 'y'=>5, 'width'=>90, 'height'=>70], 'seam_exclusions'=>[], 'min_image_dpi'=>72,
                'bleed'=>['trim_box'=>['x'=>3, 'y'=>4, 'width'=>94, 'height'=>72], 'margins_px'=>['left'=>3, 'right'=>3, 'top'=>4, 'bottom'=>4]]]];
        $input = digiforge_existing_render_fixture('fixture', ['PHOTO_TEXT', 100, 80, [$area]]);
        $path = tempnam(sys_get_temp_dir(), 'df-supplier-switch-');
        file_put_contents($path, DigiForge\POD\RasterArtworkCodec::encode(100, 80, str_repeat("\xff\xff\xff\xff", 8000)));
        $repository = new RenderEvidenceRepository();
        try {
            $record = $repository->createFromArtifacts($input, $path, $path);
            self::assertNotWPError($record);
            $approved = $repository->approve((int)$record['id']);
            self::assertNotWPError($approved);
            $receipt = (new RenderArtifactVerifier())->assertCertified($approved);
            self::assertNotWPError($receipt);
            self::assertSame('VERIFIED_LOCAL_BLEED_PIXELS', $receipt['visual_qa']['panels']['front']['bleed_pixels']);
            self::assertFalse($receipt['visual_qa']['supplier_certified']);
            return ['input'=>$input, 'record'=>$approved, 'path'=>$path];
        } catch (Throwable $error) {
            unlink($path);
            throw $error;
        }
    }

    private function selectNewMapping(array $input, array $changes): array
    {
        global $wpdb;
        $prior = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d', $input['provider_mapping_id']), ARRAY_A);
        $new = array_replace($prior, $changes);
        unset($new['id']);
        $new['mapping_version'] = 'supplier-switch-'.wp_generate_uuid4();
        self::assertSame(1, $wpdb->insert(Tables::pod_mappings(), $new));
        $id = (int)$wpdb->insert_id;
        $ownership = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_business_mappings().' WHERE provider_mapping_id=%d', $input['provider_mapping_id']), ARRAY_A);
        unset($ownership['id']);
        $ownership['provider_mapping_id'] = $id;
        self::assertSame(1, $wpdb->insert(Tables::pod_business_mappings(), $ownership));
        self::assertSame(1, $wpdb->update(Tables::order_line_items(), ['provider_mapping_id'=>$id], ['order_id'=>$input['order_id'], 'provider_mapping_id'=>$input['provider_mapping_id']]));
        self::assertSame($prior, $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_mappings().' WHERE id=%d', $input['provider_mapping_id']), ARRAY_A));
        return array_replace($input, ['provider_mapping_id'=>$id]);
    }

    public static function routeChanges(): array
    {
        return [
            'supplier'=>[['provider'=>'printful']],
            'physical blueprint'=>[['provider_product_key'=>'102']],
            'provider route'=>[['provider_variant_key'=>'3:202']],
            'variant'=>[['provider_variant_key'=>'2:203']],
        ];
    }

    /** @dataProvider routeChanges */
    public function testSelectedMappingSwitchCannotReusePriorReviewedGeometryBleedSafeZoneOrVariant(array $changes): void
    {
        global $wpdb;
        $fixture = $this->approvedRender();
        $input = $fixture['input'];
        $originalTemplate = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=1', $input['template_key']), ARRAY_A);
        try {
            $current = $this->selectNewMapping($input, $changes);
            $oldReceipt = (new RenderArtifactVerifier())->assertCertified($fixture['record']);
            self::assertWPError($oldReceipt);
            self::assertSame('render_artifact_personalization_required', $oldReceipt->get_error_code());
            $reuse = (new RenderEvidenceRepository())->createFromArtifacts($current, $fixture['path'], $fixture['path']);
            self::assertWPError($reuse);
            self::assertSame('render_artifact_mapping_conflict', $reuse->get_error_code());
            self::assertSame($originalTemplate, $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::pod_production_templates().' WHERE template_id=%s AND template_version=1', $input['template_key']), ARRAY_A));
            self::assertSame('APPROVED', $wpdb->get_var($wpdb->prepare('SELECT review_status FROM '.Tables::pod_render_evidence().' WHERE id=%d', $fixture['record']['id'])));
            self::assertTrue(DigiForge\Core\Settings::safety_locked());
            self::assertFalse(DigiForge\Core\Settings::get('automation_armed', false));
        } finally { unlink($fixture['path']); }
    }

    public function testSelectedProviderSwitchInvalidatesReviewedMockupBindingWithoutRewritingHistory(): void
    {
        $fixture = $this->approvedRender();
        try {
            $spec = digiforge_existing_render_fixture('mockupRecipeFixture', [$fixture['record'], 'MUG', 1]);
            $registry = new ProductMockupRegistry();
            $recipe = $registry->intake($spec['reference'], $spec['sha256'], (int)$fixture['record']['id'], null);
            self::assertNotWPError($recipe);
            $creator = get_current_user_id();
            wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
            self::assertNotWPError($registry->review($recipe['sha256'], 'APPROVE', array_fill_keys(ProductMockupRegistry::REVIEW_FLAGS, false), ''));
            wp_set_current_user($creator);
            $bound = $registry->bind((int)$fixture['record']['id'], $recipe['recipe']['recipe_key'], 1, $recipe['sha256']);
            self::assertNotWPError($bound);
            $this->selectNewMapping($fixture['input'], ['provider_variant_key'=>'3:202']);
            self::assertWPError($registry->bind((int)$fixture['record']['id'], $recipe['recipe']['recipe_key'], 1, $recipe['sha256']));
            self::assertTrue(DigiForge\Core\Settings::safety_locked());
        } finally { unlink($fixture['path']); }
    }
    public function testCurrentApprovedTypedTextChangesActualPngAndPdfWithoutSampleSubstitution(): void
    {
        $input = digiforge_existing_render_fixture('deterministicFixture', ['NAME_MONOGRAM', [['position'=>'front', 'decoration_method'=>'dtg', 'width_px'=>640, 'height_px'=>480, 'dpi'=>300]]]);
        $recipe = digiforge_existing_render_fixture('recipe');
        $renderer = new DigiForge\POD\DeterministicPersonalizationRenderer();
        self::assertNotWPError($renderer->approveRecipe($input['template_key'], 1, $recipe));
        global $wpdb;
        $priorHash = $input['personalization_evidence_hash'];
        $lineId = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Tables::order_line_items().' WHERE order_id=%d AND provider_mapping_id=%d', $input['order_id'], $input['provider_mapping_id']));
        self::assertGreaterThan(0, $lineId);
        $pixels = [];
        foreach (['Ada', 'Lovelace'] as $text) {
            // The existing fixture supplies these exact glyphs and an approved typed schema.
            $payload = DigiForge\Orders\Validator::canonicalJson(['name'=>$text]);
            $hash = hash('sha256', $payload);
            self::assertSame(1, $wpdb->update(Tables::personalization_submissions(), ['canonical_payload'=>$payload, 'payload_hash'=>$hash], ['order_line_item_id'=>$lineId, 'payload_hash'=>$priorHash]));
            $priorHash = $hash;
            $input['personalization_evidence_hash'] = $hash;
            $expected = $renderer->expected($input);
            self::assertNotWPError($expected);
            $png = DigiForge\POD\LocalArtworkAdapter::convert($expected['svg'], 'png', $expected['dpi']);
            $pdf = DigiForge\POD\LocalArtworkAdapter::convert($expected['svg'], 'pdf', $expected['dpi']);
            self::assertNotWPError($png);
            self::assertNotWPError($pdf);
            $actual = DigiForge\POD\RasterArtworkCodec::decode($png['bytes']);
            self::assertSame($actual['rgba'], DigiForge\POD\RasterPdfAdapter::pixels($pdf['bytes'])['rgba']);
            $pixels[$text] = $actual['rgba'];
        }
        self::assertNotSame($pixels['Ada'], $pixels['Lovelace'], 'Actual approved customer text must change pixels, not only an evidence label/hash.');
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

}
