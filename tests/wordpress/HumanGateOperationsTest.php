<?php
declare(strict_types=1);
final class HumanGateOperationsTest extends WP_UnitTestCase{
 public function setUp():void{parent::setUp();DigiForge\Core\Activator::activate();wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));}
 public function testPersonalizationReviewIsHumanImmutableAndExternalAuthorityAbsent():void{
  $src=(string)file_get_contents(dirname(__DIR__,2).'/includes/Orders/Repository.php');
  self::assertStringContainsString("['APPROVED','REJECTED']",$src);self::assertStringContainsString("'review_status'=>'UNREVIEWED'",$src);self::assertStringContainsString("'transition_conflict'",$src);
  $read=(new DigiForge\Orders\HumanGateOperationsReadModel())->snapshot(10);
  self::assertFalse($read['external_execution_authorized']);self::assertFalse($read['retry_permitted']);self::assertContains($read['query_state']['aggregate'],['AVAILABLE','PARTIAL_UNAVAILABLE']);
 }
 public function testOwnershipApprovalUsesDraftOnlyAtomicRepositoryTransition():void{
  $src=(string)file_get_contents(dirname(__DIR__,2).'/includes/POD/BusinessScopeRepository.php');
  self::assertStringContainsString("['state'=>'APPROVED'",$src);self::assertStringContainsString("['id'=>\$mappingId,'state'=>'DRAFT']",$src);self::assertStringContainsString('digiforge_scope_transition',$src);
 }
}
