<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EtsyAuthConfigContractTest extends TestCase
{
    private function source(): string
    {
        return (string)file_get_contents(dirname(__DIR__,2).'/includes/Listings/EtsyAuthConfig.php');
    }

    public function testConfigIsFailClosedAndDoesNotExposeSecrets(): void
    {
        $s=$this->source();
        foreach([
            "'credentials_exposed'=>false",
            "'token_request_permitted'=>false",
            "'adapter_invoked'=>false",
            "'external_execution_performed'=>false",
            "strtolower((string)(\$parts['scheme']??''))!=='https'"
        ] as $needle) self::assertStringContainsString($needle,$s);
        self::assertStringNotContainsString("'client_secret'=>",$s);
    }

    public function testScopesAreExplicitNormalizedAndBounded(): void
    {
        $s=$this->source();
        self::assertStringContainsString("'scopes'",$s);
        self::assertStringContainsString('sort($normalized,SORT_STRING)',$s);
        self::assertStringContainsString("strlen(\$scope)>128",$s);
    }

    public function testConfigContractContainsNoExternalOrPersistencePrimitive(): void
    {
        $s=$this->source();
        foreach(['wp_remote_','curl_exec(','api.etsy','etsy.com','$wpdb','->insert(','->update(','ExecutionNonceLedger::consume','->execute('] as $needle) {
            self::assertStringNotContainsString($needle,$s);
        }
    }
}
