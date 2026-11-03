<?php

declare(strict_types=1);

namespace DigiForge\Tests\Listings;

use DigiForge\POD\PrintifyMultiProductMigrationPlan;
use PHPUnit\Framework\TestCase;

/** Synthetic observations exercise planning; no provider records are resolved. */
final class PrintifyMultiProductMigrationPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../includes/POD/PrintifyMultiProductMigrationPlan.php';
    }

    public function testThreeGroupsPreserveEveryExternalIdentityAndSourcePrimaryWithoutExecution(): void
    {
        self::assertTrue(
            class_exists(PrintifyMultiProductMigrationPlan::class),
            'Missing reusable offline migration plan'
        );
        $source = self::source();
        $plan = PrintifyMultiProductMigrationPlan::build($source, self::groups());
        self::assertSame('printify-multi-product-migration-review-v1', $plan['schema']);
        self::assertSame($source, $plan['source_snapshot']);
        self::assertSame($source['source_primary_product_id'], $plan['rollback']['source_primary_product_id']);
        self::assertSame(array_column($source['products'], 'product_id'), $plan['rollback']['source_product_ids']);
        self::assertSame('synthetic-r1', $plan['rollback']['source_revision']);
        $partition = array_merge(...array_column($plan['groups'], 'product_ids'));
        sort($partition);
        $expected = array_column($source['products'], 'product_id');
        sort($expected);
        self::assertSame($expected, $partition);
        self::assertCount(3, $plan['groups']);
        foreach (
            ['network_request_permitted',
            'publish_permitted',
            'inventory_plan_permitted',
            'independent_edits_permitted',
            'external_execution_performed',
            'source_modified'] as $flag
        ) {
            self::assertFalse($plan[$flag]);
        }
        self::assertFalse($plan['rollback']['restore_permitted']);
        self::assertSame('UNVERIFIED', $plan['acceptance']);
        self::assertSame(0, $plan['drafts_created']);
        self::assertSame('UNBOUND', $plan['local_product_binding']);
        self::assertSame('DYNAMIC_CHOICE', $plan['source_snapshot']['products'][0]['routing']);
        self::assertNull($plan['source_snapshot']['products'][0]['provider_id']);
    }

    public function testHomeNineIsOnlyLocalCalculationAndIncompleteGroupsStayUnknown(): void
    {
        $plan = PrintifyMultiProductMigrationPlan::build(self::source(), self::groups());
        $home = self::group($plan, 'home');
        self::assertSame(9, $home['option_count']['count']);
        self::assertSame('LOCAL_CALCULATED', $home['option_count']['state']);
        self::assertSame('LOCALLY_WITHIN_400_NOT_PROVIDER_ACCEPTANCE', $home['option_count']['limit_state']);
        self::assertFalse($home['option_count']['provider_verified']);
        foreach (['apparel', 'accessories'] as $key) {
            self::assertNull(self::group($plan, $key)['option_count']['count']);
            self::assertSame('UNKNOWN', self::group($plan, $key)['option_count']['state']);
        }
        self::assertSame(
            ['Conflicting sweatshirt sizes: 8 versus 9'],
            self::group(
                $plan,
                'apparel'
            )['evidence_conflicts']
        );
    }

    public function testCountingRequiresExplicitReviewedRuleAndCannotInventColorlessOptions(): void
    {
        $groups = self::groups();
        $groups[1]['counting_rule'] = null;
        self::assertNull(self::group(
            PrintifyMultiProductMigrationPlan::build(
                self::source(),
                $groups
            ),
            'home'
        )['option_count']['count']);
        $source = self::source();
        foreach ([4, 5] as $index) {
            $source['products'][$index]['selected_size_slots'] = ['one size'];
            $source['products'][$index]['selections_complete'] = true;
        }
        $groups = self::groups();
        $groups[2]['counting_rule'] = self::rule();
        self::assertNull(self::group(
            PrintifyMultiProductMigrationPlan::build(
                $source,
                $groups
            ),
            'accessories'
        )['option_count']['count']);
    }

    public function testSharedUnionKeepsExactLabelsProductSizeSlotsAndUnsupportedPairs(): void
    {
        $source = self::source();
        foreach ([0, 1, 2] as $index) {
            $source['products'][$index]['selected_size_slots'] = ['M'];
            $source['products'][$index]['selected_shared_values'] = [['Gray', 'Grey'], ['Gray'], ['gray']][$index];
            $source['products'][$index]['selections_complete'] = true;
            $source['products'][$index]['options_source_reference'] = 'local://synthetic/options';
        }
        $groups = self::groups();
        $groups[0]['counting_rule'] = self::rule();
        $groups[0]['evidence_conflicts'] = [];
        $count = self::group(PrintifyMultiProductMigrationPlan::build($source, $groups), 'apparel')['option_count'];
        self::assertSame(3, $count['selected_product_size_slots']);
        self::assertSame(['Gray', 'Grey', 'gray'], $count['shared_exact_values']);
        self::assertSame(9, $count['count']);
        self::assertSame(5, $count['unsupported_shared_pairs']);
    }

    public function testConflictsBlockCountsEvenWhenPartialOptionsLookComplete(): void
    {
        $source = self::source();
        $groups = self::groups();
        $groups[1]['evidence_conflicts'] = ['Scents incomplete in current observation'];
        self::assertNull(self::group(
            PrintifyMultiProductMigrationPlan::build(
                $source,
                $groups
            ),
            'home'
        )['option_count']['count']);
    }

    public function testOutOfStockEvidenceIsRetainedWithoutReducingTheCountOrChangingInventory(): void
    {
        $source = self::source();
        $source['products'][3]['stock'] = [['variant_id' => null,
            'size_slot' => '9oz',
            'shared_value' => 'Unscented',
            'state' => 'OUT_OF_STOCK',
            'source_reference' => 'local://synthetic/current-stock']];
        $home = self::group(PrintifyMultiProductMigrationPlan::build($source, self::groups()), 'home');
        self::assertSame(9, $home['option_count']['count']);
        self::assertCount(1, $home['out_of_stock_observations']);
        self::assertFalse($home['automatically_exclude_out_of_stock']);
        self::assertContains('STOCK_AND_MANUAL_REPUBLISH_REVIEW_REQUIRED', $home['blockers']);
        self::assertFalse($home['inventory_plan_permitted']);
    }

    public function testOverFourHundredBlocksReadinessWithoutTrimmingOrSplitting(): void
    {
        $source = self::source();
        $source['products'][3]['selected_size_slots'] = array_map(
            static fn(int $i): string => 'synthetic-size-' . $i,
            range(
                1,
                45
            )
        );
        $plan = PrintifyMultiProductMigrationPlan::build($source, self::groups());
        $home = self::group($plan, 'home');
        self::assertSame(405, $home['option_count']['count']);
        self::assertSame('EXCEEDS_400', $home['option_count']['limit_state']);
        self::assertContains('SHARED_OPTION_LIMIT_EXCEEDED', $home['blockers']);
        self::assertSame($source, $plan['source_snapshot']);
        self::assertFalse($home['automatic_trimming_or_splitting']);
    }

    public function testExactlyFourHundredIsOnlyWithinTheLocalLimit(): void
    {
        $source = self::source();
        $source['products'][3]['selected_size_slots'] = array_map(
            static fn(int $i): string => 'size-' . $i,
            range(
                1,
                20
            )
        );
        $source['products'][3]['selected_shared_values'] = array_map(
            static fn(int $i): string => 'value-' . $i,
            range(
                1,
                20
            )
        );
        $home = self::group(PrintifyMultiProductMigrationPlan::build($source, self::groups()), 'home');
        self::assertSame(400, $home['option_count']['count']);
        self::assertSame('LOCALLY_WITHIN_400_NOT_PROVIDER_ACCEPTANCE', $home['option_count']['limit_state']);
        self::assertSame('BLOCKED_REVIEW_PLAN', $home['state']);
        self::assertFalse($home['network_request_permitted']);
    }

    public function testDeclaredLocalVersionsAndKnownRevisionNeverResolveOwnershipOrCurrentProviderState(): void
    {
        $source = self::source();
        $source['products'][0]['local_product_version_id'] = 12;
        $source['products'][0]['local_product_version_sha256'] = str_repeat('a', 64);
        $source['dependency_snapshots']['linkage'] = ['source_reference' => 'local://synthetic/linkage',
            'sha256' => str_repeat(
                'b',
                64
            ),
            'revision' => null];
        $plan = PrintifyMultiProductMigrationPlan::build($source, self::groups());
        self::assertSame('UNBOUND', $plan['local_product_binding']);
        self::assertSame(12, $plan['source_snapshot']['products'][0]['local_product_version_id']);
        $check = PrintifyMultiProductMigrationPlan::assertCurrent($plan, $source);
        self::assertSame('MATCHING_DECLARED_SNAPSHOT', $check['state']);
        self::assertFalse($check['current_source_verified']);
        $source['dependency_snapshots']['linkage']['sha256'] = str_repeat('c', 64);
        self::assertSame('STALE_SOURCE', PrintifyMultiProductMigrationPlan::assertCurrent($plan, $source)['state']);
    }

    public function testRollbackTamperingCannotRetainAnOtherwiseValidPlan(): void
    {
        $plan = PrintifyMultiProductMigrationPlan::build(self::source(), self::groups());
        $plan['rollback']['source_primary_product_id'] = $plan['rollback']['source_product_ids'][0];
        $this->expectException(\InvalidArgumentException::class);
        PrintifyMultiProductMigrationPlan::assertCurrent($plan, self::source());
    }

    public function testMalformedProjectionIsRejectedWithoutEncodingUntrustedPlanMaterial(): void
    {
        $plan = PrintifyMultiProductMigrationPlan::build(self::source(), self::groups());
        $plan['groups'] = [['unexpected' => "\xff"]];
        $this->expectException(\InvalidArgumentException::class);
        PrintifyMultiProductMigrationPlan::assertCurrent($plan, self::source());
    }

    public function testRetriesAndGroupOrderAreDeterministicAndMaterialChangesChangeHashes(): void
    {
        $a = PrintifyMultiProductMigrationPlan::build(self::source(), self::groups());
        self::assertSame($a, PrintifyMultiProductMigrationPlan::build(self::source(), array_reverse(self::groups())));
        $source = self::source();
        $source['source_revision'] = 'synthetic-r2';
        $b = PrintifyMultiProductMigrationPlan::build($source, self::groups());
        self::assertNotSame($a['plan_sha256'], $b['plan_sha256']);
        self::assertNotSame($a['rollback']['rollback_sha256'], $b['rollback']['rollback_sha256']);
    }

    public function testCurrentChecksNeverVerifyMissingRevisionsAndRejectChangedSourceOrPlan(): void
    {
        $source = self::source();
        $source['source_revision'] = null;
        $plan = PrintifyMultiProductMigrationPlan::build($source, self::groups());
        self::assertSame('UNKNOWN', PrintifyMultiProductMigrationPlan::assertCurrent($plan, $source)['state']);
        self::assertFalse(PrintifyMultiProductMigrationPlan::assertCurrent($plan, $source)['current_source_verified']);
        self::assertSame('UNKNOWN', $plan['rollback']['revision_state']);
        $source['source_revision'] = 'new-observation';
        self::assertSame('STALE_SOURCE', PrintifyMultiProductMigrationPlan::assertCurrent($plan, $source)['state']);
        $plan['publish_permitted'] = true;
        $this->expectException(\InvalidArgumentException::class);
        PrintifyMultiProductMigrationPlan::assertCurrent($plan, $source);
    }

    public function testMissingPreciseObservationTimeRemainsUnknown(): void
    {
        $source = self::source();
        $source['provenance']['observed_at'] = null;
        $source['provenance']['kind'] = 'ATTRIBUTED_AUTHENTICATED_UI_REPORT';
        $source['source_revision'] = null;
        $plan = PrintifyMultiProductMigrationPlan::build($source, self::groups());
        self::assertNull($plan['source_snapshot']['provenance']['observed_at']);
        self::assertSame(9, self::group($plan, 'home')['option_count']['count']);
        self::assertSame('UNKNOWN', PrintifyMultiProductMigrationPlan::assertCurrent($plan, $source)['state']);
    }

    public function testPublicSourceReceiptsBuildTheBlockedSixProductThreeGroupReview(): void
    {
        $path = __DIR__ . '/../../fixtures/printify/source-six-group-review-20261020.json';
        self::assertFileExists($path);
        $fixture = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $source = $fixture['source_snapshot'];
        $plan = PrintifyMultiProductMigrationPlan::build($source, $fixture['groups']);
        self::assertSame('ATTRIBUTED_AUTHENTICATED_UI_REPORT', $source['provenance']['kind']);
        $receipts = $fixture['report_receipts'];
        foreach ($receipts as &$receipt) {
            self::assertSame(hash('sha256', $receipt['body']), $receipt['body_sha256']);
            ksort($receipt, SORT_STRING);
        }
        unset($receipt);
        $receiptBytes = json_encode($receipts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertSame(hash('sha256', $receiptBytes), $fixture['report_receipts_sha256']);
        self::assertSame($fixture['report_receipts_sha256'], $source['provenance']['evidence_sha256']);
        self::assertSame(
            '3ff0d9f95b8bbce89e0f1254091c0af0cbded65c9c230d8bd584c0a11cb42cba',
            $plan['source_snapshot_sha256']
        );
        self::assertSame('3198050e5685f7e177d124b3fd805e7b38860416668ee3dfaef7b60ee1d6d681', $plan['plan_sha256']);
        self::assertSame(9, self::group($plan, 'home')['option_count']['count']);
        foreach (['apparel', 'accessories'] as $key) {
            self::assertNull(self::group($plan, $key)['option_count']['count']);
        }
        $ids = array_merge(...array_column($plan['groups'], 'product_ids'));
        sort($ids);
        $expected = ['6ad647d14f4a65e4300211e4',
            '6ad647d24f4a65e4300211e5',
            '6ad647d23c2fe90c6102cb1c',
            '6ad647d03c2fe90c6102cb18',
            '6ad647d01f72f1c0030e41be',
            '6ad647d04f4a65e4300211e2'];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame('6ad647d03c2fe90c6102cb18', $plan['rollback']['source_primary_product_id']);
        self::assertNull($plan['rollback']['source_revision']);
        self::assertNull($source['provenance']['observed_at']);
        self::assertSame('UNKNOWN', PrintifyMultiProductMigrationPlan::assertCurrent($plan, $source)['state']);
        foreach (
            ['network_request_permitted',
            'publish_permitted',
            'inventory_plan_permitted',
            'independent_edits_permitted',
            'external_execution_performed',
            'source_modified'] as $flag
        ) {
            self::assertFalse($plan[$flag]);
        }
        self::assertFalse($plan['rollback']['restore_permitted']);
        $stock = self::group($plan, 'accessories')['out_of_stock_observations'];
        self::assertCount(1, $stock);
        self::assertNull($stock[0]['shared_value']);
        self::assertNull($stock[0]['variant_id']);
    }

    /** @dataProvider invalidInputs */
    public function testInvalidOrAmbiguousEvidenceCannotBecomeAPlan(array $source, array $groups): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PrintifyMultiProductMigrationPlan::build($source, $groups);
    }

    public static function invalidInputs(): array
    {
        $cases = [];
        $source = self::source();
        $source['printify_store_id'] = 28930593;
        $cases['coerced store ID'] = [$source, self::groups()];
        $source = self::source();
        $source['products'][0]['product_id'] = 1;
        $cases['coerced external product'] = [$source, self::groups()];
        $source = self::source();
        $source['shop_key'] = 'digital';
        $cases['foreign shop'] = [$source, self::groups()];
        $source = self::source();
        $source['source_listing_id'] = '123';
        $cases['non-UUID source'] = [$source, self::groups()];
        $source = self::source();
        $source['products'][1]['product_id'] = $source['products'][0]['product_id'];
        $cases['duplicate source ID'] = [$source, self::groups()];
        $source = self::source();
        $source['source_primary_product_id'] = str_repeat('f', 24);
        $cases['foreign primary'] = [$source, self::groups()];
        $source = self::source();
        $source['products'][0]['provider_id'] = 7;
        $cases['dynamic Choice fixed provider'] = [$source, self::groups()];
        $source = self::source();
        $source['access_token'] = 'synthetic-forbidden';
        $cases['undeclared authority field'] = [$source, self::groups()];
        $source = self::source();
        $source['provenance']['observed_at'] = 'not-a-date';
        $cases['invalid observation'] = [$source, self::groups()];
        $source = self::source();
        $source['products'][3]['selected_size_slots'] = array_fill(0, 1001, 'x');
        $cases['unbounded options'] = [$source, self::groups()];
        $source = self::source();
        $source['products'][3]['selected_shared_values'] = ['Same', 'Same'];
        $cases['duplicate exact option'] = [$source, self::groups()];
        $source = self::source();
        $source['products'][0]['selected_variant_ids'] = ['11'];
        $cases['coerced variant ID'] = [$source, self::groups()];
        $source = self::source();
        $source['products'][0]['selected_variant_ids'] = [11];
        $source['products'][0]['sku_mapping'] = [['variant_id' => 12, 'sku' => 'synthetic']];
        $cases['foreign SKU variant'] = [$source, self::groups()];
        $source = self::source();
        $source['products'][0]['local_product_version_id'] = 3;
        $cases['unhashed local version'] = [$source, self::groups()];
        $groups = self::groups();
        $groups[0]['product_ids'][] = $groups[1]['product_ids'][0];
        $cases['duplicate partition member'] = [self::source(), $groups];
        $groups = self::groups();
        array_pop($groups[0]['product_ids']);
        $cases['omitted member'] = [self::source(), $groups];
        $groups = self::groups();
        $groups[0]['product_ids'][0] = str_repeat('f', 24);
        $cases['foreign member'] = [self::source(), $groups];
        $groups = self::groups();
        $groups[0]['primary_product_id'] = $groups[1]['product_ids'][0];
        $cases['foreign group primary'] = [self::source(), $groups];
        $groups = self::groups();
        $groups[1]['counting_rule']['review_state'] = 'APPROVED_FOR_PRODUCTION';
        $cases['invented counting authority'] = [self::source(), $groups];
        return $cases;
    }

    private static function group(array $plan, string $key): array
    {
        foreach ($plan['groups'] as $group) {
            if ($group['group_key'] === $key) {
                return $group;
            }
        }
        throw new \RuntimeException('Missing group');
    }

    private static function rule(): array
    {
        return ['rule' => 'SUM_PRODUCT_SIZE_SLOTS_X_EXACT_SHARED_VALUE_UNION',
            'review_state' => 'REVIEWED_FOR_OFFLINE_COUNTING',
            'source_reference' => 'local://synthetic/reviewed-counting-rule'];
    }

    private static function groups(): array
    {
        $ids = array_column(self::source()['products'], 'product_id');
        return [
            ['group_key' => 'apparel',
                'product_ids' => array_slice(
                    $ids,
                    0,
                    3
                ),
                'primary_product_id' => null,
                'counting_rule' => null,
                'evidence_conflicts' => ['Conflicting sweatshirt sizes: 8 versus 9']],
            ['group_key' => 'home',
                'product_ids' => [$ids[3]],
                'primary_product_id' => null,
                'counting_rule' => self::rule(),
                'evidence_conflicts' => []],
            ['group_key' => 'accessories',
                'product_ids' => array_slice(
                    $ids,
                    4
                ),
                'primary_product_id' => null,
                'counting_rule' => null,
                'evidence_conflicts' => []],
        ];
    }

    private static function source(): array
    {
        $ids = ['6ad647d14f4a65e4300211e4',
            '6ad647d24f4a65e4300211e5',
            '6ad647d23c2fe90c6102cb1c',
            '6ad647d03c2fe90c6102cb18',
            '6ad647d01f72f1c0030e41be',
            '6ad647d04f4a65e4300211e2'];
        $products = [];
        foreach ($ids as $i => $id) {
            $products[] = ['product_id' => $id,
                'label' => ['hoodie',
                'sweatshirt',
                'tshirt',
                'soy_candle',
                'stickers',
                'tote'][$i],
                'routing' => $i < 3
                ? 'DYNAMIC_CHOICE' : 'UNKNOWN',
                'provider_id' => null,
                'blueprint_id' => null,
                'selected_variant_ids' => null,
                'sku_mapping' => null,
                'selected_size_slots' => $i === 3
                ? ['9oz'] : null,
                'selected_shared_values' => $i === 3
                ? ['Clean Cotton',
                'Sea Salt + Orchid',
                'White Sage and Lavender',
                'Apple Harvest',
                'Cinnamon Vanilla',
                'Christmas Warmth',
                'Coconut Cream + Cardamom',
                'Peppered Passionfruit',
                'Unscented'] : null,
                'selections_complete' => $i === 3,
                'options_source_reference' => $i === 3
                ? 'local://synthetic/selected-options' : null,
                'stock' => null,
                'local_product_version_id' => null,
                'local_product_version_sha256' => null];
        }
        return ['shop_key' => 'personalized_pod',
            'environment' => 'test',
            'printify_store_id' => '28930593',
            'source_listing_id' => '8a2666c5-d3db-4b44-bf6a-4c5b4213815e',
            'source_primary_product_id' => $ids[3],
            'source_revision' => 'synthetic-r1',
            'source_state' => 'DRAFT_WITH_UNSAVED_CHANGES',
            'provenance' => ['kind' => 'SYNTHETIC_TEST_ONLY',
            'source_reference' => 'local://synthetic/issue1209-comment6288058817',
            'observed_at' => '2026-10-20T00:00:00Z',
            'evidence_sha256' => hash(
                'sha256',
                'synthetic-observation'
            )],
            'products' => $products,
            'dependency_snapshots' => ['linkage' => null,
            'pricing' => null,
            'shipping' => null,
            'mockups' => null,
            'personalization' => null]];
    }
}
