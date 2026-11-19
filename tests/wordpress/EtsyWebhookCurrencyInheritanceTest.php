<?php
declare(strict_types=1);

use DigiForge\Database\Tables;
use DigiForge\Orders\Repository;

require_once __DIR__ . '/EtsyWebhookPersistenceAcceptanceTest.php';

/** Signed local zero-amount intake must retain its known order currency. */
final class EtsyWebhookCurrencyInheritanceTest extends WP_UnitTestCase
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

    /** @dataProvider inheritedCurrencyCases */
    public function testMissingZeroAmountLineCurrencyInheritsOrderIdentity(array $amount): void
    {
        $this->assertCurrencies(['currency' => 'EUR'], $amount, 'EUR', 'EUR');
    }

    public function inheritedCurrencyCases(): array
    {
        return ['absent price' => [[]], 'zero price' => [['price' => 0]],
            'zero alias price' => [['price_amount' => 0]]];
    }

    public function testExplicitDifferentLineCurrencyRetainsItsIdentity(): void
    {
        $this->assertCurrencies(['currency' => 'EUR'], ['currency' => 'GBP'], 'EUR', 'GBP');
    }

    public function testCurrencylessZeroAmountCompatibilityStillUsesUsd(): void
    {
        $this->assertCurrencies([], [], 'USD', 'USD');
    }

    private function assertCurrencies(array $orderFields, array $lineFields, string $orderCurrency, string $lineCurrency): void
    {
        $this->fixture('historicalDigitalListing');
        $signed = $this->fixture('signed', [array_merge([
            'event_type' => 'order.paid', 'order_id' => wp_generate_uuid4(), 'shop_id' => '712002',
            'line_items' => [array_merge(['listing_id' => '712003', 'quantity' => 1], $lineFields)],
        ], $orderFields)]);
        $result = $this->fixture('accept', [$signed]);
        self::assertNotWPError($result);
        self::assertSame('VALIDATED', $result['persisted_order_state']);
        self::assertSame(1, $result['line_item_count']);
        self::assertFalse($result['fulfillment_authorized']);
        self::assertFalse($result['external_execution_performed']);
        $order = (new Repository())->findByExternalReference(json_decode($signed[1], true)['order_id'], '712002');
        self::assertNotWPError($order);
        self::assertSame($orderCurrency, $order['currency']);
        global $wpdb;
        $line = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Tables::order_line_items() . ' WHERE order_id=%d', $result['order_id']
        ), ARRAY_A);
        self::assertSame($lineCurrency, $line['currency']);
        self::assertEquals(0, $line['unit_price_amount']);
        self::assertSame(hash('sha256', $signed[1]), $wpdb->get_var($wpdb->prepare(
            'SELECT body_sha256 FROM ' . Tables::webhook_evidence() . ' WHERE event_id=%s', $signed[0]
        )));
        $this->fixture('assertLedger', [$signed[0], 'PROCESSED']);
    }
}
