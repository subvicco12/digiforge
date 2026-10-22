<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class BusinessOwnershipProjectionTest extends TestCase {
 public function testApprovalRequiresConsistentActiveAncestryAndCurrentProviderMapping():void {
  require_once dirname(__DIR__,2).'/includes/Portal/BusinessOwnershipReadModel.php';
  $valid=['ownership_state'=>'APPROVED','ownership_approved_by'=>'8','ownership_approved_at'=>'2026-10-12 00:00:00','business_status'=>'ACTIVE','store_status'=>'ACTIVE','program_status'=>'ACTIVE','mapping_state'=>'APPROVED','mapping_approved_by'=>'8','mapping_approved_at'=>'2026-10-12 00:00:00'];
  self::assertSame('APPROVED_LOCAL_EVIDENCE',DigiForge\Portal\BusinessOwnershipReadModel::evidenceState($valid));
  foreach(['business_status','store_status','program_status'] as $field){$r=$valid;$r[$field]='INACTIVE';self::assertSame('INACTIVE_OWNERSHIP',DigiForge\Portal\BusinessOwnershipReadModel::evidenceState($r));}
  foreach(['ownership_approved_by','mapping_approved_by'] as $field){$r=$valid;$r[$field]=0;self::assertSame('REVIEW_REQUIRED',DigiForge\Portal\BusinessOwnershipReadModel::evidenceState($r));}
  $r=$valid;$r['mapping_state']='DRAFT';self::assertSame('REVIEW_REQUIRED',DigiForge\Portal\BusinessOwnershipReadModel::evidenceState($r));
 }
}
