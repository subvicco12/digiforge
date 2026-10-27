<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/includes/Portal/ShopWorkflowNavigation.php';

final class ShopWorkflowNavigationTest extends TestCase
{
    public function testOrderAndResearchAssignmentsExposeTheirSelectedShopWorkflows(): void
    {
        $caps=['manage_digiforge','manage_digiforge_orders','manage_digiforge_research'];
        $views=DigiForge\Portal\ShopWorkflowNavigation::views('digital',static fn(string $shop,string $cap):bool=>in_array($cap,$caps,true));
        foreach (['orders','fulfillment','research','approvals','automation','audit'] as $view) self::assertArrayHasKey($view,$views);
        foreach (['products','digital','finance','analytics','integrations','settings'] as $view) self::assertArrayNotHasKey($view,$views);
    }
    public function testProductReviewersReachApprovalsWithoutResearchCapability(): void
    {
        $views=DigiForge\Portal\ShopWorkflowNavigation::views('personalized_pod',static fn(string $shop,string $cap):bool=>$cap==='manage_digiforge_products');
        self::assertArrayHasKey('approvals',$views);
        self::assertArrayNotHasKey('research',$views);
    }
    public function testEveryLinkRequiresExactShopCapabilityAndDenialsFailClosed(): void
    {
        foreach (['digital','personalized_pod','standard_pod','jewelry'] as $shop) {
            $seen=[];
            $views=DigiForge\Portal\ShopWorkflowNavigation::views($shop,static function(string $actual,string $cap) use ($shop,&$seen):bool {self::assertSame($shop,$actual);$seen[]=$cap;return true;});
            self::assertNotEmpty($seen);
            self::assertSame($shop==='digital',isset($views['digital']));
            self::assertSame($shop==='personalized_pod',isset($views['pod_personalized']));
            self::assertArrayNotHasKey('finance',$views);
            self::assertSame([],DigiForge\Portal\ShopWorkflowNavigation::views($shop,static fn()=>new stdClass()));
        }
        self::assertSame([],DigiForge\Portal\ShopWorkflowNavigation::views('all',static function(){self::fail('Unknown scope must not reach authorization');}));
    }
}
