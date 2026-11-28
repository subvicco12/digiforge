<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Orders\Repository;

require_once __DIR__ . '/EtsyWebhookPersistenceAcceptanceTest.php';

/** Synthetic signed intake only; invalid quantities must retain review evidence. */
final class EtsyWebhookQuantityIntegrityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(0);
    }

    private function fixture(string $method, array $arguments = []): mixed
    {
        $fixture = new EtsyWebhookPersistenceAcceptanceTest();
        $reflection = new ReflectionMethod($fixture, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($fixture, $arguments);
    }

    /** @dataProvider invalidQuantities */
    public function testInvalidQuantityRequiresReviewWithoutFabricatingLine(mixed $quantity, bool $withSibling): void
    {
        $listing = $this->fixture('historicalDigitalListing');
        $items = [['listing_id' => '712003', 'quantity' => $quantity]];
        if ($withSibling) {
            $items[] = ['listing_id' => '712003', 'quantity' => 3];
        }
        $signed = $this->fixture('signed', [[
            'event_type' => 'order.paid', 'order_id' => wp_generate_uuid4(),
            'shop_id' => '712002', 'line_items' => $items,
        ]]);
        $result = $this->fixture('accept', [$signed]);
        self::assertNotWPError($result);
        self::assertSame('ETSY_ORDER_REVIEW_REQUIRED', $result['state']);
        self::assertSame('REVIEW_REQUIRED', $result['persisted_order_state']);
        self::assertTrue($result['unmatched_line_items']);
        self::assertSame($withSibling ? 1 : 0, $result['line_item_count']);
        self::assertFalse($result['fulfillment_authorized']);
        self::assertFalse($result['external_execution_performed']);
        self::assertSame('REVIEW_REQUIRED', (new Repository())->findByExternalReference(
            json_decode($signed[1], true)['order_id'], '712002'
        )['state']);

        global $wpdb;
        $lines = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Tables::order_line_items() . ' WHERE order_id=%d', $result['order_id']
        ), ARRAY_A);
        self::assertCount($withSibling ? 1 : 0, $lines);
        if ($withSibling) {
            self::assertSame($listing, (int) $lines[0]['listing_id']);
            self::assertSame(3, (int) $lines[0]['quantity']);
            self::assertSame('VALIDATED', $lines[0]['validation_status']);
        }
        $evidence = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::webhook_evidence() . ' WHERE event_id=%s', $signed[0]
        ), ARRAY_A);
        self::assertSame(hash('sha256', $signed[1]), $evidence['body_sha256']);
        self::assertSame('VERIFIED', $evidence['verification_status']);
        $this->fixture('assertLedger', [$signed[0], 'PROCESSED']);
    }

    public function invalidQuantities(): array
    {
        $values = [
            'null' => null, 'true' => true, 'false' => false,
            'empty array' => [], 'nonempty array' => [2],
            'fraction' => 1.9, 'fraction string' => '2.7',
            'malformed' => '2 items', 'empty string' => '',
            'zero' => 0, 'negative' => -1, 'over limit' => 1001,
            'large integer' => PHP_INT_MAX, 'large numeric string' => '999999999999999999999999',
        ];
        $cases = [];
        foreach ($values as $name => $value) {
            $cases[$name . ' alone'] = [$value, false];
            $cases[$name . ' with valid sibling'] = [$value, true];
        }
        return $cases;
    }

    /** @dataProvider acceptedQuantities */
    public function testMissingAndValidQuantitiesRemainValidated(array $quantity, int $expected): void
    {
        $this->fixture('historicalDigitalListing');
        $signed = $this->fixture('signed', [[
            'event_type' => 'order.paid', 'order_id' => wp_generate_uuid4(), 'shop_id' => '712002',
            'line_items' => [array_merge(['listing_id' => '712003'], $quantity)],
        ]]);
        $result = $this->fixture('accept', [$signed]);
        self::assertNotWPError($result);
        self::assertSame('VALIDATED', $result['persisted_order_state']);
        self::assertSame(1, $result['line_item_count']);
        self::assertFalse($result['fulfillment_authorized']);
        self::assertFalse($result['external_execution_performed']);
        global $wpdb;
        self::assertSame($expected, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT quantity FROM ' . Tables::order_line_items() . ' WHERE order_id=%d', $result['order_id']
        )));
    }

    public function acceptedQuantities(): array
    {
        return ['missing' => [[], 1], 'integer' => [['quantity' => 2], 2],
            'integer string' => [['quantity' => '2'], 2], 'upper bound' => [['quantity' => 1000], 1000]];
    }
}
