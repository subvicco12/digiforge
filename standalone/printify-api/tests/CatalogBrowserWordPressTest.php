<?php
declare(strict_types=1);

use DigiForgePrintifyApi\{CatalogBrowser, Runtime, SyntheticPrintifyTransport};

final class CatalogBrowserWordPressTest extends WP_UnitTestCase
{
    public function testExtractedScopedCatalogJourney(): void
    {
        define('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT', 'fixture-catalog');
        // Verify default-disabled behavior before enabling the local-only fixture for this process.
        $actor = self::factory()->user->create(['role'=>'administrator']);
        wp_set_current_user($actor);
        wp_get_current_user()->add_cap('digiforge_printify_review_shop_12');
        self::assertSame('SCOPE_OR_TRANSPORT_UNAVAILABLE', Runtime::browseCatalog(1,'12','fixture-catalog')['error']);
        define('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY', true);
        $selection = ['blueprint_id'=>42, 'provider_id'=>7, 'variant_id'=>9, 'method'=>'standard'];
        $result = Runtime::browseCatalog(1,'12','fixture-catalog',$selection);
        self::assertSame('OBSERVED', $result['state'], json_encode($result));
        self::assertSame(['shop'=>'12','account'=>'fixture-catalog','integration'=>null], $result['scope']);
        self::assertSame('SYNTHETIC_PRINTIFY', $result['source']);
        self::assertSame('FIXTURE_ONLY', $result['freshness']);
        self::assertNull($result['provider_observed_at']);
        self::assertFalse($result['provider_fact_verified']);
        self::assertFalse($result['provider_execution_authorized']);
        self::assertFalse($result['address_quote']);
        self::assertSame(['id'=>42,'title'=>'Synthetic mug'], $result['data']['blueprints']['rows'][0]);
        self::assertSame(['id'=>7,'title'=>'Synthetic printer'], $result['data']['providers']['rows'][0]);
        self::assertSame('Synthetic white mug', $result['data']['variants']['rows'][0]['title']);
        self::assertSame(['position'=>'front','width'=>1200,'height'=>600,'bleed'=>null,'safe_zone'=>null],
            $result['data']['print_areas'][0]);
        self::assertSame([['method'=>'standard','country'=>'US','first_item'=>500,'additional_item'=>200,'currency'=>'USD']],
            $result['data']['catalog_rates']);
        self::assertSame([], Runtime::browseCatalog(1,'12','fixture-catalog',array_replace($selection,['variant_id'=>10]))['data']['catalog_rates']);
        self::assertSame('UNSUPPORTED_SELECTION', Runtime::browseCatalog(1,'12','fixture-catalog',array_replace($selection,['provider_id'=>1]))['error']);
        self::assertSame('UNSUPPORTED_SELECTION', Runtime::browseCatalog(1,'12','fixture-catalog',array_replace($selection,['variant_id'=>99]))['error']);
        self::assertSame('SCOPE_OR_TRANSPORT_UNAVAILABLE', Runtime::browseCatalog(1,'13','fixture-catalog')['error']);
        self::assertSame('SCOPE_OR_TRANSPORT_UNAVAILABLE', Runtime::browseCatalog(1,'12','other')['error']);
        self::assertSame('INCOMPATIBLE_VERSION', Runtime::browseCatalog(2,'12','fixture-catalog')['error']);
        self::assertSame('INVALID_SELECTION', Runtime::browseCatalog(1,'12','fixture-catalog',['operation'=>'ORDER_CREATE'])['error']);
        self::assertSame(['rows'=>[],'page'=>2,'next_page'=>null,'provider_pagination_followed'=>false],
            Runtime::browseCatalog(1,'12','fixture-catalog',['page'=>2])['data']['blueprints']);
        $fixture = new SyntheticPrintifyTransport('12');
        $fixture->faultNext('rate_limit');
        self::assertSame('RATE_LIMITED', CatalogBrowser::read(1,'12','fixture-catalog',[],$fixture)['error']);
        self::assertSame('SCOPE_OR_TRANSPORT_UNAVAILABLE', CatalogBrowser::read(1,'12','fixture-catalog',[],new SyntheticPrintifyTransport('13'))['error']);

        self::assertSame('DENIED', Runtime::validateCatalog('12', ['kind'=>'blueprints'], '{')['state']);
        self::assertSame('DENIED', Runtime::validateCatalog('12', ['kind'=>'blueprints'], '{"last_page":2}')['state']);
        $unattributed = Runtime::validateCatalog('12', ['kind'=>'blueprints'], '[{"id":42}]');
        self::assertSame('UNATTRIBUTED_OFFLINE_INPUT', $unattributed['evidence_class']);
        self::assertFalse($unattributed['freshness_verified']);
        $areas = new ReflectionMethod(CatalogBrowser::class, 'areas');
        self::assertNull($areas->invoke(null, ['id'=>9]));
        self::assertSame([['position'=>'front','width'=>null,'height'=>null,'bleed'=>null,'safe_zone'=>null]],
            $areas->invoke(null, ['placeholders'=>[['position'=>'front']]]));

        $previous = $_GET;
        $steps = [
            [], ['df_catalog_shop'=>'12'], ['df_catalog_blueprint'=>'42'], ['df_catalog_provider'=>'7'],
            ['df_catalog_variant'=>'9'], ['df_catalog_method'=>'standard'],
        ];
        $labels = ['df_catalog_shop','df_catalog_blueprint','df_catalog_provider','df_catalog_variant',
            '1200 × 600','USD 500 first item'];
        try {
            $_GET = [];
            foreach ($steps as $i=>$step) {
                $_GET += $step;
                ob_start();
                Runtime::panel();
                $html = ob_get_clean();
                self::assertStringContainsString($labels[$i], $html);
                self::assertStringContainsString('Synthetic observations only', $html);
            }
            self::assertStringContainsString('does not quote an address', $html);
            self::assertStringNotContainsString('fixture-catalog', $html);
        } finally {
            $_GET = $previous;
        }
    }
}
