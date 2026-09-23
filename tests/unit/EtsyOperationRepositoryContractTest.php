<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyOperationRepositoryContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRepository.php');
    }

    public function testRepositoryRemainsLocalOnly(): void
    {
        $source=$this->source();
        foreach(['wp_remote_','curl_exec(','etsy.com','api.etsy'] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }

    public function testRepositoryUsesShopScopedIdempotency(): void
    {
        $source=$this->source();
        self::assertStringContainsString('shop_reference=%s AND idempotency_key=%s',$source);
        self::assertStringContainsString('idempotent_replay',$source);
        self::assertStringContainsString('idempotency_conflict',$source);
        foreach(['intent_id','draft_package_id','operation_type','request_fingerprint','authorization_hash','evidence_hash'] as $field) {
            self::assertStringContainsString("'".$field."'",$source);
        }
    }

    public function testTransitionsUseCompareAndSwapAndCanonicalLifecycle(): void
    {
        $source=$this->source();
        self::assertStringContainsString('EtsyOperationLifecycle::canTransition',$source);
        self::assertStringContainsString("['id' => \$id, 'state' => \$from]",$source);
        self::assertStringContainsString('transition_conflict',$source);
    }

    public function testConfirmedSuccessRequiresExternalReference(): void
    {
        $source=$this->source();
        self::assertStringContainsString('CONFIRMED_SUCCESS',$source);
        self::assertStringContainsString('external_reference_required',$source);
    }
}
