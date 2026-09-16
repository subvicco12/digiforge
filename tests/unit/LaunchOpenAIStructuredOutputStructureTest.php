<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LaunchOpenAIStructuredOutputStructureTest extends TestCase
{
    public function testLaunchClientRequestsStructuredJsonOutputAndKeepsFailClosedDiagnostics(): void
    {
        $source = file_get_contents(__DIR__ . '/../../includes/Launch/OpenAIClient.php');
        self::assertIsString($source);
        self::assertStringContainsString("'text'=>['format'=>['type'=>'json_object']]", $source);
        self::assertStringContainsString("'digiforge_launch_ai_invalid_json'", $source);
        self::assertStringContainsString("'json_error'=>sanitize_text_field(json_last_error_msg())", $source);
        self::assertStringContainsString('json_decode($text,true)', $source);
    }
}
