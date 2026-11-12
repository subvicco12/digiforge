<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/includes/Database/QueryEvidence.php';

final class V6LifecycleAttentionDenominatorTest extends TestCase
{
    public function testLifecycleDenominatorsDoNotClaimAiCostAttribution():void
    {
        require_once __DIR__.'/../../includes/AI/LifecycleDenominatorReadModel.php';
        $previous=$GLOBALS['wpdb']??null;
        $GLOBALS['wpdb']=new class {
            public string $prefix='wp_';
            public string $last_error='prior read failure';
            public function get_var(string $sql):string {return '4';}
        };
        try {
            $result=(new DigiForge\AI\LifecycleDenominatorReadModel())->snapshot();
            self::assertSame('AVAILABLE',$result['query_state']??null);
            foreach(['opportunities','developed_products','approved_listings','received_orders'] as $key)self::assertSame(4,$result[$key]);
            self::assertTrue($result['denominators_authoritative']);
            self::assertFalse($result['ai_cost_attribution_established']);
            self::assertFalse($result['external_execution_performed']);
        } finally {$GLOBALS['wpdb']=$previous;}
    }

    public function testFailedLifecycleCountIsUnknownAndDoesNotPoisonIndependentSources():void
    {
        require_once __DIR__.'/../../includes/AI/LifecycleDenominatorReadModel.php';
        $previous=$GLOBALS['wpdb']??null;
        $GLOBALS['wpdb']=new class {
            public string $prefix='wp_';
            public string $last_error='';
            public function get_var(string $sql):?string {
                if(str_contains($sql,'FROM wp_digiforge_products')){$this->last_error='unavailable synthetic source';return null;}
                return '3';
            }
        };
        try {
            $result=(new DigiForge\AI\LifecycleDenominatorReadModel())->snapshot();
            self::assertNull($result['developed_products']);
            self::assertSame('UNAVAILABLE',$result['query_state']??null);
            self::assertFalse($result['denominators_authoritative']);
            self::assertSame(3,$result['received_orders']);
            self::assertSame('UNAVAILABLE',$result['query_states']['developed_products']??null);
            self::assertSame('AVAILABLE',$result['query_states']['received_orders']??null);
            self::assertFalse($result['external_execution_performed']);
        } finally {$GLOBALS['wpdb']=$previous;}
    }

    public function testAttentionIncludesOrdersNeedingReconciliation():void
    {
        $model=file_get_contents(__DIR__.'/../../includes/Portal/AttentionReadModel.php');
        self::assertStringContainsString("'orders_needing_reconciliation'",$model);
        self::assertStringContainsString("validation_status<>'VALIDATED'",$model);
        self::assertStringContainsString('provider_mapping_id=0',$model);
    }

    public function testFinancePortalLoadsDenominatorsReadOnly():void
    {
        $portal=file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');
        self::assertStringContainsString('LifecycleDenominatorReadModel',$portal);
        self::assertStringContainsString('$denominators=', $portal);
        self::assertStringContainsString('Lifecycle denominators',$portal);
        self::assertStringContainsString('AI cost attribution: NOT ESTABLISHED',$portal);
        self::assertStringContainsString('Orders needing reconciliation',$portal);
    }
}
