<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\DigitalFactory\DriveAttemptAdmission as Admission;
use DigiForge\DigitalFactory\DriveTransferRequest as Request;
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveTransferRequest.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveExecutionGrant.php';
require_once dirname(__DIR__,2).'/includes/DigitalFactory/DriveAttemptAdmission.php';

final class DriveAttemptAdmissionTest extends TestCase
{
    private function fixture(): array
    {
        $p=['id'=>str_repeat('a',64),'installation_sha256'=>str_repeat('b',64),
            'environment'=>'local','shop_key'=>'digital','folder_id'=>Request::FOLDER,
            'product_id'=>25,'product_version_id'=>5,'revision_id'=>12,
            'source_sha256'=>str_repeat('c',64),'actor_id'=>7];
        $g=$p+['issuer'=>'reviewed_google_issuer','connection_id'=>'google_digital',
            'nonce'=>str_repeat('d',64),'issued_at'=>100,'expires_at'=>200,
            'remaining_attempts'=>2,'stages'=>['folder','original_upload']];
        $o=['now'=>150,'stop_all'=>false,'external_locked'=>false,
            'automation_armed'=>true,'release_authorized'=>true,
            'shop_access_current'=>true,'google_connection_current'=>true,
            'account_verified'=>true,'folder_access_verified'=>true,
            'credential_valid'=>true,'durable_nonce_claimed'=>true,
            'attempt_claimed'=>true,'grant_revoked'=>false,
            'connection_revoked'=>false,'attempts_used'=>0,
            'issuer'=>'reviewed_google_issuer','connection_id'=>'google_digital'];
        return [$p,$g,$o];
    }
    public function testFullyObservedCandidatePassesWithoutDispatch(): void
    {
        [$p,$g,$o]=$this->fixture();
        self::assertTrue(Admission::allowed($g,$p,$o,'folder'));
        self::assertFalse(Admission::allowed($g,$p,$o,'native_conversion'));
    }
    public function testMissingOrUnsafeObservationFailsClosed(): void
    {
        [$p,$g,$o]=$this->fixture();
        foreach ($o as $key=>$value) {
            $missing=$o;unset($missing[$key]);
            self::assertFalse(Admission::allowed($g,$p,$missing,'folder'),$key);
        }
        foreach (['stop_all'=>true,'external_locked'=>true,'release_authorized'=>false,
            'durable_nonce_claimed'=>false,'attempt_claimed'=>false,
            'grant_revoked'=>true,'connection_revoked'=>true,
            'issuer'=>'other','connection_id'=>'other','attempts_used'=>2] as $key=>$value) {
            $bad=$o;$bad[$key]=$value;
            self::assertFalse(Admission::allowed($g,$p,$bad,'folder'),$key);
        }
    }
    public function testExpiredGrantAndWrongSourceFail(): void
    {
        [$p,$g,$o]=$this->fixture();
        $o['now']=200;
        self::assertFalse(Admission::allowed($g,$p,$o,'folder'));
        $o['now']=150;$p['source_sha256']=str_repeat('e',64);
        self::assertFalse(Admission::allowed($g,$p,$o,'folder'));
    }
}
