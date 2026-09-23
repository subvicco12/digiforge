<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/Listings/EtsyOperationLifecycle.php';
require_once dirname(__DIR__, 2) . '/includes/Listings/EtsyOperationRecord.php';

use DigiForge\Listings\EtsyOperationRecord;
use PHPUnit\Framework\TestCase;

final class EtsyOperationRecordTest extends TestCase
{
    private function valid(): array
    {
        return [
            'shop_reference'=>'DigiCraftifyDigital',
            'intent_id'=>1,
            'draft_package_id'=>1,
            'operation_type'=>'CREATE_DRAFT',
            'idempotency_key'=>'etsy-op-listing-1-v1',
            'request_fingerprint'=>str_repeat('a',64),
            'authorization_hash'=>str_repeat('b',64),
            'evidence_hash'=>str_repeat('c',64),
        ];
    }

    public function testCanonicalRecordStartsNotSentAndIsShopScoped(): void
    {
        $record=EtsyOperationRecord::canonicalize($this->valid());
        self::assertIsArray($record);
        self::assertSame('NOT_SENT',$record['state']);
        self::assertSame('DigiCraftifyDigital',$record['shop_reference']);
        self::assertSame(1,$record['intent_id']);
        self::assertSame(1,$record['draft_package_id']);
    }

    public function testIdentityChangesAcrossShopScope(): void
    {
        $a=EtsyOperationRecord::canonicalize($this->valid());
        $bInput=$this->valid();$bInput['shop_reference']='DigiCraftifyGoods';
        $b=EtsyOperationRecord::canonicalize($bInput);
        self::assertIsArray($a);self::assertIsArray($b);
        self::assertNotSame(EtsyOperationRecord::identity($a),EtsyOperationRecord::identity($b));
    }

    public function testHashesAndScopeFailClosed(): void
    {
        if (!class_exists('WP_Error')) { require_once dirname(__DIR__) . '/support/wp-stubs.php'; }
        $bad=$this->valid();$bad['request_fingerprint']='unsafe';
        self::assertInstanceOf(WP_Error::class,EtsyOperationRecord::canonicalize($bad));
        $bad=$this->valid();$bad['intent_id']=0;
        self::assertInstanceOf(WP_Error::class,EtsyOperationRecord::canonicalize($bad));
    }

    public function testContractDoesNotPerformPersistenceOrHttp(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationRecord.php');
        foreach(['$wpdb->','wp_remote_','curl_exec('] as $needle) self::assertStringNotContainsString($needle,$source);
    }
}
