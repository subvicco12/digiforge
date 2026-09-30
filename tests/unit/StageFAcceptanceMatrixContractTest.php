<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class StageFAcceptanceMatrixContractTest extends TestCase
{
 public function testMatrixBindsAllStageFWorkstreamsAndFinalArtifactGate():void{
  $m=(string)file_get_contents(__DIR__.'/../../docs/build-stage-f/STAGE-F-ACCEPTANCE-MATRIX-v1.0.93.md');
  foreach(['F1 repository/runtime convergence','F2 Digital Product Factory','F3 Personalized POD Factory','F4 Listing Factory / Gate 3','F5 operations foundations','F6 Approval Inbox / Dashboard','F7 failure/recovery','F8 security/integrations','F9 release certification','F10 final artifact','Audit #3656 SUCCESS','CERTIFIED ARTIFACT AVAILABLE','11115867086','8b4681255c77a8656ed5f9f8e8ac48f2909ec4e9722052bbde102fe366743ba7','inner `digiforge.zip`','UNKNOWN external outcomes require reconciliation before retry','No row in this matrix may be interpreted as activation authorization'] as $v)self::assertStringContainsString($v,$m);
 }
}
