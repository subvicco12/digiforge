<?php
declare(strict_types=1);

/** Persisted local history and protected release boundaries; no provider actions. */
final class OperationsCurrentActionAcceptanceTest extends WP_UnitTestCase
{
    public function testSupersededPlanHistoryIsPreservedWithoutPermanentCurrentAction(): void
    {
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        global $wpdb;
        $tables = DigiForge\Database\Tables::class;
        $order = (new DigiForge\Orders\Repository())->createOrder(['shop_reference'=>'digital','channel'=>'etsy','environment'=>'sandbox']);
        self::assertNotWPError($order);
        $now = current_time('mysql', true);
        $payload = wp_json_encode(['local_history_fixture'=>wp_generate_uuid4()]);
        self::assertSame(1, $wpdb->insert($tables::fulfillment_plans(), ['order_id'=>$order['id'],'plan_version'=>'history','canonical_payload'=>$payload,'payload_hash'=>hash('sha256',$payload),'readiness'=>'{}','readiness_hash'=>hash('sha256','{}'),'state'=>'APPROVED','environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));
        $plan = (int)$wpdb->insert_id;
        self::assertSame(1, $wpdb->insert($tables::fulfillment_intents(), ['order_id'=>$order['id'],'fulfillment_plan_id'=>$plan,'intent_type'=>'PREPARE_FULFILLMENT','input_payload'=>'{}','state'=>'UNKNOWN','environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));
        $unknown = (int)$wpdb->insert_id;
        $ids = [];
        foreach (['FAILED','BLOCKED'] as $state) {
            self::assertSame(1, $wpdb->insert($tables::fulfillment_intents(), ['order_id'=>$order['id'],'fulfillment_plan_id'=>$plan,'intent_type'=>'PREPARE_FULFILLMENT','input_payload'=>'{}','state'=>$state,'environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));
            $ids[] = (int)$wpdb->insert_id;
        }
        $model = new DigiForge\Operations\OperationalExceptionReadModel();
        $snapshot = $model->snapshot(100, 'digital');
        self::assertSame('AVAILABLE', $snapshot['query_state']['fulfillment']);
        $activeTotal = $snapshot['fulfillment_total'];
        foreach ($ids as $id) self::assertContains($id, array_map('intval', array_column($snapshot['fulfillment'], 'id')));
        $history = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.$tables::fulfillment_intents().' WHERE fulfillment_plan_id=%d ORDER BY id', $plan), ARRAY_A);
        self::assertNotWPError((new DigiForge\Orders\Repository())->transition('plan', $plan, 'SUPERSEDED'));
        // A later successful local record is not itself resolution authority;
        // the explicit parent-plan SUPERSEDED state closes the old action.
        self::assertSame(1, $wpdb->insert($tables::fulfillment_intents(), ['order_id'=>$order['id'],'fulfillment_plan_id'=>$plan,'intent_type'=>'PREPARE_FULFILLMENT','input_payload'=>'{}','state'=>'APPROVED_INTENT','environment'=>'sandbox','created_at'=>$now,'updated_at'=>$now]));
        $snapshot = $model->snapshot(100, 'digital');
        self::assertSame($activeTotal - 2, $snapshot['fulfillment_total']);
        foreach ($ids as $id) self::assertNotContains($id, array_map('intval', array_column($snapshot['fulfillment'], 'id')));
        self::assertContains($unknown, array_map('intval', array_column($snapshot['fulfillment'], 'id')), 'Supersession is not provider reconciliation.');
        self::assertSame($history, $wpdb->get_results($wpdb->prepare('SELECT * FROM '.$tables::fulfillment_intents().' WHERE id IN (%d,%d,%d) ORDER BY id', $unknown, ...$ids), ARRAY_A));
        foreach ($snapshot['fulfillment'] as $row) {
            self::assertTrue($row['read_only']);
            self::assertFalse($row['retry_permitted']);
        }
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }

    public function testReactivationPreservesProtectedControlsAndPublishDenial(): void
    {
        DigiForge\Core\Activator::activate();
        DigiForge\Core\Settings::protectProduction();
        $keys = ['stop_all','activation_authorized','automation_armed','etsy_publish','etsy_draft','order_automation','printify'];
        $before = [];
        foreach ($keys as $key) $before[$key] = DigiForge\Core\Settings::get($key, false);
        foreach ([false,true] as $humanApproved) {
            $decision = DigiForge\Listings\EtsyExecutionPolicy::evaluate('PUBLISH', $humanApproved);
            self::assertFalse($decision['allowed']);
            self::assertSame('external_safety_lock_active', $decision['reason']);
            self::assertTrue($decision['requires_human_approval']);
        }
        DigiForge\Core\Activator::deactivate();
        DigiForge\Core\Activator::activate();
        foreach ($before as $key=>$value) self::assertSame($value, DigiForge\Core\Settings::get($key, false), $key);
        self::assertFalse(DigiForge\Listings\EtsyExecutionPolicy::evaluate('PUBLISH', true)['allowed']);
        self::assertFalse(DigiForge\Core\Settings::activateProduction());
        self::assertTrue(DigiForge\Core\Settings::safety_locked());
    }
}
