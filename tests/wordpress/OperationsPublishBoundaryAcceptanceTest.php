<?php
declare(strict_types=1);

/** Local action/evidence/replay semantics, never an execution permit. */
final class OperationsPublishBoundaryAcceptanceTest extends WP_UnitTestCase
{
    public function testDraftAuthorizationCannotBecomePublishAndPublishRequiresExactUnusedEvidence(): void
    {
        $evidence = hash('sha256', 'synthetic reviewed publish evidence');
        $authorization = ['action'=>'ETSY_DRAFT_CREATE','evidence_hash'=>$evidence,'issued_at'=>100,'expires_at'=>200,'nonce'=>str_repeat('n',24)];
        $record = $this->record($authorization);
        $result = DigiForge\POD\ExecutionAuthorizationVerifier::verify($record,'ETSY_PUBLISH_LISTING',$evidence,150,static fn()=>true);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('digiforge_execution_scope', $result->get_error_code());
        $authorization['action'] = 'ETSY_PUBLISH_LISTING';
        $record = $this->record($authorization);
        self::assertTrue(DigiForge\POD\ExecutionAuthorizationVerifier::verify($record,'ETSY_PUBLISH_LISTING',$evidence,150,static fn()=>true));
        $result = DigiForge\POD\ExecutionAuthorizationVerifier::verify($record,'ETSY_PUBLISH_LISTING',hash('sha256','changed evidence'),150,static fn()=>true);
        self::assertSame('digiforge_execution_evidence', $result->get_error_code());
        $result = DigiForge\POD\ExecutionAuthorizationVerifier::verify($record,'ETSY_PUBLISH_LISTING',$evidence,150,static fn()=>false);
        self::assertSame('digiforge_execution_replay', $result->get_error_code());
        $record['executed'] = true;
        $result = DigiForge\POD\ExecutionAuthorizationVerifier::verify($record,'ETSY_PUBLISH_LISTING',$evidence,150,static fn()=>true);
        self::assertSame('digiforge_execution_state', $result->get_error_code());
    }

    private function record(array $authorization): array
    {
        ksort($authorization);
        return ['state'=>'EXECUTION_AUTHORIZED','executed'=>false,'authorization'=>$authorization,'authorization_hash'=>hash('sha256',(string)wp_json_encode($authorization))];
    }
}
