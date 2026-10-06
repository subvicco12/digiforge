<?php
declare(strict_types=1);
use DigiForge\Database\MigrationPlan;
use DigiForge\Database\V6OperationalSchema;
use PHPUnit\Framework\TestCase;

final class V6OperationalPersistenceContractTest extends TestCase {
 public function testV23FollowsCoordinatedV22Migration():void{self::assertGreaterThanOrEqual(23,MigrationPlan::LATEST);self::assertSame(range(16,MigrationPlan::LATEST),MigrationPlan::pending(15));self::assertSame(range(22,MigrationPlan::LATEST),MigrationPlan::pending(21));self::assertSame(range(23,MigrationPlan::LATEST),MigrationPlan::pending(22));self::assertSame([],MigrationPlan::pending(MigrationPlan::LATEST));}
 public function testSchemaSeparatesCatalogAiAndWebhookEvidence():void{$sql=implode("\n",V6OperationalSchema::statements(''));foreach(['catalog_versions','catalog_items','shop_ai_policies','shop_ai_usage','webhook_evidence','pod_render_evidence','pod_authorization_packages'] as $name)self::assertStringContainsString($name,$sql);self::assertStringContainsString('production_authority tinyint(1) NOT NULL DEFAULT 0',$sql);self::assertStringContainsString('UNIQUE KEY provider_event (provider,event_id)',$sql);}
 public function testCatalogVersionAndWebhookEventAreImmutableIdentities():void{$sql=implode("\n",V6OperationalSchema::statements(''));self::assertStringContainsString('UNIQUE KEY catalog_version (catalog_key,version_label)',$sql);self::assertStringContainsString('UNIQUE KEY version_listing (catalog_version_id,listing_id)',$sql);}
 public function testAiUsageCanAttributeProductOrderWorkflowAndStage():void{$sql=implode("\n",V6OperationalSchema::statements(''));foreach(['workflow varchar(64)',"run_id varchar(100) NOT NULL DEFAULT ''",'run_started_at datetime','stage varchar(64)','product_id bigint(20)','order_id bigint(20)','estimated_cost decimal(14,6)','actual_cost decimal(14,6)'] as $needle)self::assertStringContainsString($needle,$sql);}
}