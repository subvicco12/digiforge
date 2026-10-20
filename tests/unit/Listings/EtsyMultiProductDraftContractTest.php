<?php

declare(strict_types=1);

namespace DigiForge\Tests\Listings;

use DigiForge\Listings\EtsyMultiProductDraftContract;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/POD/ProductionTemplateContract.php';
require_once __DIR__ . '/../../../includes/Listings/EtsyDraftListingOperations.php';
if (is_file(__DIR__ . '/../../../includes/Listings/EtsyMultiProductDraftContract.php')) { require_once __DIR__ . '/../../../includes/Listings/EtsyMultiProductDraftContract.php'; }

final class EtsyMultiProductDraftContractTest extends TestCase
{
    public function testSeparatePhysicalProductsRemainSeparateDraftCandidates(): void
    {
        self::assertTrue(class_exists(EtsyMultiProductDraftContract::class), 'Missing separate per-product draft compiler');
        $result = EtsyMultiProductDraftContract::build(self::design(), [self::product(), self::product(2)]);
        self::assertCount(2, $result['listings']);
        self::assertNotSame($result['listings'][0]['draft_key'], $result['listings'][1]['draft_key']);
        self::assertSame('SKU-1', $result['listings'][0]['sku']);
        self::assertSame(2, $result['listings'][1]['product_version_id']);
        self::assertSame('UNKNOWN', $result['listings'][0]['supplier_certification']);
        self::assertSame('REVIEW_REQUIRED', $result['state']);
        self::assertFalse($result['external_execution_performed']);
        self::assertSame(0, $result['draft_packages_created']);
        self::assertArrayNotHasKey('variations', $result);
        self::assertSame('CREATE_DRAFT', $result['listings'][0]['draft_operation']['operation']);
        self::assertSame(321, $result['listings'][0]['draft_operation']['payload']['shipping_profile_id']);
        self::assertSame(654, $result['listings'][0]['draft_operation']['payload']['readiness_state_id']);
        self::assertArrayNotHasKey('personalization_questions', $result['listings'][0]['draft_operation']['payload']);
        self::assertSame('WAITING_FOR_DRAFT_LISTING_ID', $result['listings'][0]['personalization_plan']['state']);
        self::assertSame('UPDATE_PERSONALIZATION', $result['listings'][0]['personalization_plan']['operation']);
        self::assertSame('/application/shops/123/listings/{listing_id}/personalization', $result['listings'][0]['personalization_plan']['endpoint_template']);
        self::assertCount(2, $result['listings'][0]['personalization_plan']['payload']['personalization_questions']);
        self::assertFalse($result['listings'][0]['personalization_plan']['network_request_permitted']);
        self::assertFalse($result['listings'][0]['draft_operation']['network_request_permitted']);
        self::assertSame('/application/shops/123/listings', $result['listings'][0]['draft_operation']['endpoint']);
        self::assertSame('code_points', $result['listings'][0]['field_behavior']['length_unit']);
    }

    public function testSeparatePlansNeverAssertIndependentProviderListings(): void
    {
        $a = self::product();
        $b = self::product(2);
        $b['mockup'] = $a['mockup'];
        $result = EtsyMultiProductDraftContract::build(self::design(), [$a, $b]);
        self::assertSame('UNKNOWN', $result['provider_listing_dependencies']['state']);
        self::assertFalse($result['provider_listing_dependencies']['independent_edits_permitted']);
        self::assertSame([1, 2], $result['provider_listing_dependencies']['shared_mockup_product_versions']);
        self::assertSame('UNKNOWN', $result['provider_listing_dependencies']['variant_dependencies']);
        self::assertSame('UNKNOWN', $result['provider_listing_dependencies']['pricing_dependencies']);
        self::assertFalse($result['network_request_permitted']);
        self::assertSame($result, EtsyMultiProductDraftContract::build(self::design(), [$b, $a]));
    }

    public function testDifferentMockupsDoNotProveIndependentVariantsOrPricing(): void
    {
        $a = self::product();
        $b = self::product(2);
        $b['mockup']['reference'] = 'digiforge/product-factory/synthetic/shop-personalized_pod/other.png';
        $b['mockup']['sha256'] = str_repeat('f', 64);
        $result = EtsyMultiProductDraftContract::build(self::design(), [$a, $b]);
        self::assertSame([], $result['provider_listing_dependencies']['shared_mockup_product_versions']);
        self::assertSame('UNKNOWN', $result['provider_listing_dependencies']['state']);
        self::assertFalse($result['provider_listing_dependencies']['independent_edits_permitted']);
    }

    public function testUnresolvedDependenciesIncludeShippingAndNeverCertifyActivePersonalization(): void
    {
        $result = EtsyMultiProductDraftContract::build(self::design(), [self::product()]);
        self::assertSame('UNKNOWN', $result['listings'][0]['buyer_personalization']);
        self::assertSame('UNKNOWN', $result['provider_listing_dependencies']['shipping_dependencies']);
        self::assertFalse($result['provider_listing_dependencies']['independent_edits_permitted']);
        self::assertFalse($result['network_request_permitted']);
    }

    public function testRetriesAreDeterministicAndProductOrderIndependent(): void
    {
        $a = EtsyMultiProductDraftContract::build(self::design(), [self::product(), self::product(2)]);
        self::assertSame($a, EtsyMultiProductDraftContract::build(self::design(), [self::product(2), self::product()]));
        $changed = self::product();
        $changed['download_template']['sha256'] = str_repeat('e', 64);
        $b = EtsyMultiProductDraftContract::build(self::design(), [$changed, self::product(2)]);
        self::assertNotSame($a['manifest_sha256'], $b['manifest_sha256']);
        self::assertNotSame($a['listings'][0]['draft_key'], $b['listings'][0]['draft_key']);
    }

    /** @dataProvider conflictingTemplateEnvironments */
    public function testDeclaredCatalogEnvironmentCannotCrossDraftScope(string $designEnvironment, string $catalogEnvironment, string $status): void
    {
        $design = self::design();
        $design['environment'] = $designEnvironment;
        $this->expectException(\InvalidArgumentException::class);
        EtsyMultiProductDraftContract::build($design, [self::boundProduct($catalogEnvironment, $status)]);
    }

    public static function conflictingTemplateEnvironments(): array
    {
        return [['test', 'production', 'DRAFT'], ['production', 'test', 'DRAFT'], ['sandbox', 'production', 'VALIDATED'], ['production', 'sandbox', 'VALIDATED']];
    }

    public function testMatchingCatalogEnvironmentRetainsUnverifiedOfflinePlan(): void
    {
        $result = EtsyMultiProductDraftContract::build(self::design(), [self::boundProduct('test', 'VALIDATED')]);
        self::assertSame('test', $result['listings'][0]['template']['print_areas'][0]['_catalog_bindings'][0]['environment']);
        self::assertSame('UNKNOWN', $result['listings'][0]['supplier_certification']);
        self::assertSame('UNVERIFIED', $result['acceptance']);
        self::assertFalse($result['network_request_permitted']);
    }

    private static function boundProduct(string $environment, string $status): array
    {
        $product = self::product();
        $product['template']['template_status'] = $status;
        $product['template']['print_areas'][0] += ['region'=>'US', '_catalog_bindings'=>[[
            'schema'=>'printify-placeholder-binding-v1', 'provider'=>'printify', 'environment'=>$environment,
            'blueprint_id'=>1, 'provider_id'=>7, 'variant_id'=>11,
            'endpoint_path'=>'/v1/catalog/blueprints/1/print_providers/7/variants.json',
            'response_sha256'=>str_repeat('d', 64), 'source_revision'=>'synthetic-only',
            'observed_at'=>'2026-10-20T00:00:00Z', 'variant_sha256'=>str_repeat('e', 64),
            'placeholder_sha256'=>hash('sha256', json_encode(['position'=>'front', 'decoration_method'=>'print', 'width'=>100, 'height'=>100], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]]];
        $product['download_template']['template_sha256'] = \DigiForge\POD\ProductionTemplateContract::normalize($product['template'])['fingerprint'];
        return $product;
    }

    /** @dataProvider invalidProducts */
    public function testInvalidOrForeignEvidenceCannotBecomeDraftCandidates(array $products): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EtsyMultiProductDraftContract::build(self::design(), $products);
    }

    public static function invalidProducts(): array
    {
        $base = self::product();
        $cases = [];
        foreach (['shop_key'=>'digital', 'variant_id'=>999, 'placement'=>'unknown', 'sku'=>'', 'product_version_id'=>0, 'native_verified'=>true, 'personalization_enabled'=>false] as $key=>$value) {
            $p = $base;
            $p[$key] = $value;
            $cases[$key] = [[$p]];
        }
        $p = $base;
        $p['download_template']['reference'] = '../secret';
        $cases['unsafe download'] = [[$p]];
        foreach (['provider_id' => 'Printify Choice', 'provider_blueprint_id' => null] as $key => $value) {
            $p = $base;
            $p['template'][$key] = $value;
            $cases['unresolved observed identity ' . $key] = [[$p]];
        }
        $p = $base;
        unset($p['mockup_rights']);
        $cases['missing rights reference'] = [[$p]];
        $p = self::product(2);
        $p['sku'] = $base['sku'];
        $cases['duplicate sku'] = [[$base, $p]];
        $cases['duplicate product'] = [[$base, $base]];
        $cases['empty products'] = [[]];
        foreach (['shipping_profile_id', 'readiness_state_id'] as $key) {
            $p = $base;
            $p['draft'][$key] = 0;
            $cases['invalid physical listing ' . $key] = [[$p]];
        }
        foreach (['template_sha256'=>str_repeat('f', 64), 'variant_id'=>999, 'placement'=>'back', 'shop_key'=>'digital'] as $key=>$value) {
            $p = $base;
            $p['download_template'][$key] = $value;
            $cases['download mismatch ' . $key] = [[$p]];
        }
        foreach (['mockup', 'mockup_rights', 'download_template'] as $key) {
            $p = $base;
            $p[$key]['reference'] = 'digiforge/product-factory/synthetic/shop-digital/product.json';
            $cases['foreign reference path ' . $key] = [[$p]];
        }
        foreach (['products'=>[['sku'=>'foreign']], 'variations'=>[['product_type'=>'mug']], 'state'=>'active', 'access_token'=>'synthetic-forbidden'] as $key=>$value) {
            $p = $base;
            $p['draft'][$key] = $value;
            $cases['forbidden draft ' . $key] = [[$p]];
        }
        foreach (['required'=>true, 'max_allowed_characters'=>49, 'question_type'=>'dropdown'] as $key=>$value) {
            $p = $base;
            $p['personalization'][1]['question'][$key] = $value;
            $cases['weakened optional field ' . $key] = [[$p]];
        }
        $p = $base;
        $p['template']['variant_ids'] = [11, 12];
        $p['template']['print_areas'][] = ['position'=>'front','decoration_method'=>'embroidery','width_px'=>100,'height_px'=>100];
        $cases['ambiguous placement'] = [[$p]];
        return $cases;
    }

    public function testDeferredShopAndAlteredDesignAreRejected(): void
    {
        foreach (['standard_pod', 'jewelry', 'digital'] as $shop) {
            $design = self::design();
            $design['shop_key'] = $shop;
            try {
                EtsyMultiProductDraftContract::build($design, [self::product()]);
                self::fail('Foreign or deferred design scope accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([['family_id', 'OTHER'], ['version', 99]] as [$key, $value]) {
            $design = self::design();
            $design[$key] = $value;
            try {
                EtsyMultiProductDraftContract::build($design, [self::product()]);
                self::fail('Non-FC01-v2 design accepted with FC01 questions');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testScopeAndExactDraftTextRemainBoundWithoutGrantingAuthority(): void
    {
        $p = self::product();
        $p['draft']['description'] = '  Exact synthetic spacing  ';
        $a = EtsyMultiProductDraftContract::build(self::design(), [$p]);
        self::assertSame('  Exact synthetic spacing  ', $a['listings'][0]['draft_operation']['payload']['description']);
        $p['draft']['description'] = trim($p['draft']['description']);
        self::assertNotSame($a['manifest_sha256'], EtsyMultiProductDraftContract::build(self::design(), [$p])['manifest_sha256']);
        $scope = self::design();
        $scope['environment'] = 'sandbox';
        self::assertNotSame($a['manifest_sha256'], EtsyMultiProductDraftContract::build($scope, [self::product()])['manifest_sha256']);
        self::assertSame('UNKNOWN', $a['listings'][0]['ownership']);
        self::assertSame('UNKNOWN', $a['listings'][0]['rights']);
        self::assertSame('UNKNOWN', $a['listings'][0]['native_compatibility']);
        self::assertFalse($a['network_request_permitted']);
        self::assertFalse($a['publish_permitted']);
        self::assertSame('UNVERIFIED', $a['acceptance']);
    }

    public static function design(): array
    {
        return ['shop_key'=>'personalized_pod', 'family_id'=>'FC01', 'version'=>2, 'spec_sha256'=>str_repeat('a', 64), 'etsy_shop_id'=>123, 'environment'=>'test'];
    }

    public static function product(int $id = 1): array
    {
        $ref = ['shop_key'=>'personalized_pod', 'reference'=>'digiforge/product-factory/synthetic/shop-personalized_pod/product-' . $id . '.json', 'sha256'=>str_repeat('b', 64)];
        $product = [
            'shop_key'=>'personalized_pod', 'product_id'=>$id, 'product_version_id'=>$id,
            'product_version_sha256'=>str_repeat('c', 64), 'sku'=>'SKU-' . $id,
            'template'=>[
                'template_id'=>'synthetic-template-' . $id, 'template_version'=>1,
                'supplier'=>'printify', 'provider_blueprint_id'=>$id, 'provider_id'=>7,
                'variant_ids'=>[10 + $id], 'print_areas'=>[['position'=>'front', 'decoration_method'=>'print', 'width_px'=>100, 'height_px'=>100]],
                'personalization_pipeline'=>'DIGIFORGE_RENDER', 'personalization_engine'=>'NAME_MONOGRAM', 'template_status'=>'DRAFT',
            ],
            'variant_id'=>10 + $id, 'placement'=>'front', 'download_template'=>$ref,
            'draft'=>['quantity'=>1, 'title'=>'Synthetic product ' . $id, 'description'=>'Offline fixture', 'price'=>10, 'who_made'=>'i_did', 'when_made'=>'made_to_order', 'taxonomy_id'=>123, 'shipping_profile_id'=>321, 'readiness_state_id'=>654],
            'mockup'=>$ref, 'mockup_rights'=>$ref,
            'personalization'=>[
                ['field'=>'family_name', 'question'=>['question_type'=>'text_input', 'question_text'=>'Family name', 'required'=>true, 'max_allowed_characters'=>48]],
                ['field'=>'established', 'question'=>['question_type'=>'text_input', 'question_text'=>'Established', 'required'=>false, 'max_allowed_characters'=>48]],
            ],
        ];
        $template = \DigiForge\POD\ProductionTemplateContract::normalize($product['template']);
        $product['download_template'] += ['template_sha256'=>$template['fingerprint'], 'variant_id'=>10 + $id, 'placement'=>'front'];
        return $product;
    }
}
