<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyExecutionAdapterContractTest extends TestCase
{
    public function testEtsyAdapterExtendsProviderNeutralContract(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/POD/ExecutionAdapter.php';
        require_once dirname(__DIR__, 2) . '/includes/Listings/EtsyExecutionAdapter.php';

        $reflection = new ReflectionClass(\DigiForge\Listings\EtsyExecutionAdapter::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->implementsInterface(\DigiForge\POD\ExecutionAdapter::class));
        self::assertTrue($reflection->hasMethod('execute'));
    }

    public function testContractContainsNoExternalHttpImplementation(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/Listings/EtsyExecutionAdapter.php');
        self::assertIsString($source);
        foreach (['wp_remote_get(', 'wp_remote_post(', 'curl_exec(', 'http://', 'https://'] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }
}
