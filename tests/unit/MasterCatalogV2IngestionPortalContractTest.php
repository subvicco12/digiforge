<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class MasterCatalogV2IngestionPortalContractTest extends TestCase {
 public function testReadinessProjectionIsFailClosedAndNonAuthorizing():void{$s=(string)file_get_contents(__DIR__.'/../../includes/POD/MasterCatalogV2IngestionReadModel.php');foreach(['PARENT_EVIDENCE_UNAVAILABLE','PARENT_EVIDENCE_INVALID','V2_EVIDENCE_UNAVAILABLE','READY_FOR_VALIDATED_INPUT','ALREADY_PERSISTED','EXISTING_V2_EVIDENCE_CONFLICT',"'production_authority'=>false","'promotion_authorized'=>false","'external_execution_authorized'=>false",'PersonalizedCatalogReference::LISTING_COUNT','MasterCatalogV2Reference::SOURCE_SHA256'] as $n)self::assertStringContainsString($n,$s);}
 public function testPortalDoesNotExposeRawImportOrPromotionAction():void{$s=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');foreach(['Master 500 v2 ingestion readiness','No raw upload or automatic ingestion action is exposed here','never grants Etsy publish or POD production authority','Open reconciliation','ALREADY_PERSISTED means the stored v2 source','EXISTING_V2_EVIDENCE_CONFLICT requires reconciliation'] as $n)self::assertStringContainsString($n,$s);}
 public function testFulfillmentRouteCarriesExactInternalEvidenceIdentity():void{$s=(string)file_get_contents(__DIR__.'/../../includes/Portal/Portal.php');foreach(['df_fulfillment_plan','df_order','Focused fulfillment evidence','Review reconciliation evidence before any retry','this focus grants no execution authority'] as $n)self::assertStringContainsString($n,$s);}
}
