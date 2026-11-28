<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyResearchDependencies;
if(is_file(__DIR__.'/../../includes/POD/PrintifyResearchDependencies.php'))require_once __DIR__.'/../../includes/POD/PrintifyResearchDependencies.php';
final class PrintifyResearchDependencyTest extends TestCase
{
    public function testMissingDependenciesNeverReportIntegrationReady(): void
    {
        self::assertTrue(class_exists(PrintifyResearchDependencies::class));
        $report=PrintifyResearchDependencies::inspect();self::assertFalse($report['integration_ready']);self::assertFalse($report['external_execution_authorized']);self::assertNotEmpty($report['missing_dependencies']);
    }
    public function testRequirementsIncludeTransitiveOwnershipAndDispatchMethods(): void
    {
        $r=PrintifyResearchDependencies::requirements();
        self::assertContains('scopedRead',$r['DigiForge\\POD\\PrintifyCatalogClient']);
        self::assertContains('verified',$r['DigiForge\\POD\\ScopedProviderConnection']);
        self::assertContains('approved',$r['DigiForge\\Security\\ReviewedLocalEvidence']);
        self::assertContains('records',$r['DigiForge\\Security\\ShopAccessAuthority']);
        self::assertArrayNotHasKey('DigiForge\\Security\\FirstPartyOwnerPolicy',$r);
    }
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInstalledScopeChecksDenyMalformedOrUnavailableAuthority(): void
    {
        eval('namespace DigiForge\\Security; final class ShopAccess {public static string $mode="allow"; public static array $calls=[]; public static function authorize($shop,$cap){self::$calls[]=[$shop,$cap];if(self::$mode==="throw")throw new \\RuntimeException("partial dependency");return self::$mode==="allow"?true:new \\stdClass();}}');
        $caps=['manage_digiforge','manage_digiforge_pod','manage_digiforge_research','manage_digiforge_connections'];
        self::assertTrue(PrintifyResearchDependencies::assigned($caps));self::assertCount(4,\DigiForge\Security\ShopAccess::$calls);
        foreach(\DigiForge\Security\ShopAccess::$calls as $call)self::assertSame('personalized_pod',$call[0]);
        \DigiForge\Security\ShopAccess::$mode='deny';self::assertFalse(PrintifyResearchDependencies::assigned($caps));
        \DigiForge\Security\ShopAccess::$mode='throw';self::assertFalse(PrintifyResearchDependencies::assigned($caps));
    }
    public function testRouteContractIsExactAndFixedToShopTwo(): void
    {
        $routes=PrintifyResearchDependencies::routes();self::assertCount(6,$routes);
        foreach($routes as $route){self::assertStringStartsWith('/digiforge/v1/shops/personalized_pod/printify-research',$route['route']);self::assertContains($route['method'],['GET','POST']);}
        self::assertCount(5,PrintifyResearchDependencies::actions());
    }
}
