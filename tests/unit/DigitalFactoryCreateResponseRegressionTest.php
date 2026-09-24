<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DigitalFactoryCreateResponseRegressionTest extends TestCase
{
    public function testCreateCapturesEntityIdBeforeAuditAndUsesCapturedIdForResponse(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/DigitalFactory/Repository.php');

        $capture = strpos($source, '$id = (int) $wpdb->insert_id;');
        $audit = strpos($source, "Logger::audit($type . '_created'");
        $response = strpos($source, 'return $this->find($type, $id)');

        self::assertNotFalse($capture);
        self::assertNotFalse($audit);
        self::assertNotFalse($response);
        self::assertLessThan($audit, $capture);
        self::assertLessThan($response, $audit);
        self::assertStringNotContainsString('return $this->find($type, (int) $wpdb->insert_id)', $source);
    }
}
