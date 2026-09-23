<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyOperationSchemaContractTest extends TestCase
{
    public function testSchemaIsAdditiveAndShopIdempotencyScoped(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/EtsyOperationSchema.php');
        foreach(['digiforge_etsy_operations','shop_reference','intent_id','draft_package_id','operation_type',
                 "DEFAULT 'NOT_SENT'",'idempotency_key','request_fingerprint','authorization_hash',
                 'evidence_hash','external_reference','UNIQUE KEY shop_idempotency'] as $needle) {
            self::assertStringContainsString($needle,$s);
        }
        self::assertStringNotContainsString('DROP TABLE',$s);
        self::assertStringNotContainsString('TRUNCATE',$s);
        self::assertStringNotContainsString('wp_remote_',$s);
    }

    public function testSchemaUsesIndependentVersionOption(): void
    {
        $s=(string)file_get_contents(dirname(__DIR__,2).'/includes/Database/EtsyOperationSchema.php');
        self::assertStringContainsString('digiforge_etsy_operation_schema_version',$s);
        self::assertStringNotContainsString("digiforge_db_schema_version",$s);
    }
}
