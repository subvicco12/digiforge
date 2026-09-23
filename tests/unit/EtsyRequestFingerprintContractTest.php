<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyRequestFingerprintContractTest extends TestCase
{
    public function testFingerprintCanonicalizesAssociativeKeysRecursively(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyRequestFingerprint.php');
        self::assertStringContainsString('ksort($value, SORT_STRING)',$source);
        self::assertStringContainsString('self::canonicalize($item)',$source);
        self::assertStringContainsString('array_is_list($value)',$source);
    }

    public function testFingerprintEncodingFailsClosed(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyRequestFingerprint.php');
        self::assertStringContainsString('JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE',$source);
        self::assertStringContainsString('if (!is_string($encoded))',$source);
        self::assertStringContainsString("new WP_Error('digiforge_etsy_request_fingerprint'",$source);
        self::assertStringNotContainsString("?: ''",$source);
    }

    public function testPreparationUsesSharedFingerprintContract(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyOperationPreparationService.php');
        self::assertStringContainsString('EtsyRequestFingerprint::fromPayload($payload)',$source);
        self::assertStringNotContainsString("hash('sha256', wp_json_encode(".'$payload',$source);
    }

    public function testContractIsLocalOnly(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyRequestFingerprint.php');
        foreach(['wp_remote_','curl_exec(','etsy.com','api.etsy','$wpdb','->insert(','->update(','ExecutionNonceLedger::consume'] as $needle) {
            self::assertStringNotContainsString($needle,$source);
        }
    }
}
