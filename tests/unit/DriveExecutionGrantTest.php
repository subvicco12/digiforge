<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\DigitalFactory\DriveExecutionGrant as Grant;
use DigiForge\DigitalFactory\DriveTransferRequest as Request;
require_once dirname(__DIR__, 2).'/includes/DigitalFactory/DriveTransferRequest.php';
require_once dirname(__DIR__, 2).'/includes/DigitalFactory/DriveExecutionGrant.php';

final class DriveExecutionGrantTest extends TestCase
{
    private function plan(): array
    {
        return ['id'=>str_repeat('a',64),'installation_sha256'=>str_repeat('b',64),
            'environment'=>'local','shop_key'=>'digital','folder_id'=>Request::FOLDER,
            'product_id'=>25,'product_version_id'=>5,'revision_id'=>12,
            'source_sha256'=>str_repeat('c',64),'actor_id'=>7];
    }
    private function grant(array $plan): array
    {
        return $plan + ['issuer'=>'reviewed_google_issuer','connection_id'=>'google_digital',
            'nonce'=>str_repeat('d',64),'issued_at'=>100,'expires_at'=>200,
            'remaining_attempts'=>2,'stages'=>['folder','original_upload']];
    }
    public function testExactScopedGrantIsOnlyAnOfflineCandidate(): void
    {
        $plan=$this->plan();
        self::assertTrue(Grant::validate($this->grant($plan),$plan,'original_upload',150,false,true));
        self::assertFalse(Grant::validate($this->grant($plan),$plan,'native_conversion',150,false,true));
    }
    public function testRevokedExpiredOrConsumedGrantDenies(): void
    {
        $p=$this->plan();$g=$this->grant($p);
        self::assertFalse(Grant::validate($g,$p,'folder',150,true,true));
        self::assertFalse(Grant::validate($g,$p,'folder',150,false,false));
        self::assertFalse(Grant::validate($g,$p,'folder',200,false,true));
        self::assertFalse(Grant::validate($g,$p,'folder',99,false,true));
    }
    public function testMismatchedIdentityOrUnsafeQuotasDeny(): void
    {
        $p=$this->plan();$g=$this->grant($p);
        foreach (['id'=>str_repeat('f',64),'shop_key'=>'pod','folder_id'=>'foreign',
                  'source_sha256'=>str_repeat('e',64),'actor_id'=>8,
                  'product_version_id'=>6,'environment'=>'production'] as $key=>$value) {
            $bad=$g;$bad[$key]=$value;
            self::assertFalse(Grant::validate($bad,$p,'folder',150,false,true),$key);
        }
        foreach (['remaining_attempts'=>0,'expires_at'=>2000,
                  'nonce'=>'invalid','stages'=>['folder','folder']] as $key=>$value) {
            $bad=$g;$bad[$key]=$value;
            self::assertFalse(Grant::validate($bad,$p,'folder',150,false,true),$key);
        }
    }
}
