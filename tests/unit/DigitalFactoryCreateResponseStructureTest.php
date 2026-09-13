<?php

declare(strict_types=1);

namespace DigiForge\Tests;

use PHPUnit\Framework\TestCase;

final class DigitalFactoryCreateResponseStructureTest extends TestCase
{
    public function testCreateCapturesEntityInsertIdBeforeAuditWrite(): void
    {
        $path = __DIR__ . '/../../includes/DigitalFactory/Repository.php';
        $content = (string) file_get_contents($path);

        $capture = '$id = (int) $wpdb->insert_id;';
        $audit = "Logger::audit(\$type . '_created', "
            . "['idempotency_key' => \$key === null ? '' : '[PRESENT]'], "
            . "\$type, (string) \$id);";
        $read = 'return $this->find($type, $id)';

        self::assertStringContainsString($capture, $content);
        self::assertStringContainsString($audit, $content);
        self::assertStringContainsString($read, $content);

        $capturePosition = strpos($content, $capture);
        $auditPosition = strpos($content, $audit);
        $readPosition = strpos($content, $read);

        self::assertIsInt($capturePosition);
        self::assertIsInt($auditPosition);
        self::assertIsInt($readPosition);
        self::assertLessThan($auditPosition, $capturePosition);
        self::assertLessThan($readPosition, $auditPosition);
    }
}
