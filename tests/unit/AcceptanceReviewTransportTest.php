<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Portal/AcceptanceReviewRequest.php';

final class AcceptanceReviewTransportTest extends TestCase
{
    private function proposal(): array
    {
        return ['input'=>['version'=>2], 'effective_at'=>1, 'expires_at'=>2000000000,
            'request_id'=>'synthetic-request-1234', 'expected_revision'=>0, 'confirm'=>'CONFIRM_LOCAL_ACCEPTANCE_REVIEW'];
    }

    public function testProposalPreservesTypedPayload(): void
    {
        self::assertSame($this->proposal(), DigiForge\Portal\AcceptanceReviewRequest::parse(json_encode($this->proposal()), false));
    }

    public function testDecisionRequiresExactIdentityHashRevisionAndConfirmation(): void
    {
        $decision=['criterion'=>'BP-quality-feedback','scope'=>'product:1','proposal_hash'=>str_repeat('a',64),
            'decision'=>'APPROVED','request_id'=>'synthetic-request-1234','expected_revision'=>1,'confirm'=>'CONFIRM_LOCAL_ACCEPTANCE_REVIEW'];
        self::assertSame($decision, DigiForge\Portal\AcceptanceReviewRequest::parse(json_encode($decision), true));
        foreach (['scope'=>[], 'proposal_hash'=>'bad', 'decision'=>'approve', 'expected_revision'=>'1', 'confirm'=>'', 'request_id'=>'short', 'token'=>'synthetic'] as $key=>$value) {
            self::assertNull(DigiForge\Portal\AcceptanceReviewRequest::parse(json_encode(array_replace($decision,[$key=>$value])), true), $key);
        }
    }

    public function testMalformedOversizedCoercedAndExtraInputIsRejected(): void
    {
        foreach (['null','[]','{',str_repeat(' ',32769)] as $raw) self::assertNull(DigiForge\Portal\AcceptanceReviewRequest::parse($raw,false));
        foreach (['input'=>[], 'effective_at'=>'1','expires_at'=>1,'expected_revision'=>-1,'confirm'=>true,'token'=>'synthetic'] as $key=>$value) {
            self::assertNull(DigiForge\Portal\AcceptanceReviewRequest::parse(json_encode(array_replace($this->proposal(),[$key=>$value])), false), $key);
        }
    }
}
