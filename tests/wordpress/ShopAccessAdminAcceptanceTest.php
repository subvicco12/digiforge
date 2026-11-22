<?php
declare(strict_types=1);
/** Actual administrative request admission before a legacy global projection. */
final class ShopAccessAdminAcceptanceTest extends WP_UnitTestCase
{
    public function testUnassignedAdministratorCannotReachGlobalResearchPage(): void
    {
        DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        // Isolate this request-admission hook from unrelated WordPress update jobs.
        // Actual HTTP remains globally blocked by the native test bootstrap.
        $adminInit=$GLOBALS['wp_filter']['admin_init']??null;
        $GLOBALS['wp_filter']['admin_init']=new WP_Hook();
        if (class_exists(DigiForge\Security\ShopAccessAdminGuard::class)) (new DigiForge\Security\ShopAccessAdminGuard())->register();
        $request=$_REQUEST;$get=$_GET;$_REQUEST=$_GET=['page'=>'digiforge-research'];$denied=false;$reads=0;
        $die=static fn()=>static function():never { throw new RuntimeException('shop-admin-denied'); };
        $queries=static function(string $sql) use (&$reads):string { if (preg_match('/^SELECT/i',ltrim($sql))&&str_contains($sql,DigiForge\Database\Tables::research_candidates())) $reads++;return $sql; };
        add_filter('wp_die_handler',$die);add_filter('query',$queries);ob_start();
        try { do_action('admin_init');(new DigiForge\Research\Admin())->render(); }
        catch (RuntimeException $e) { self::assertSame('shop-admin-denied',$e->getMessage());$denied=true; }
        finally { ob_end_clean();remove_filter('wp_die_handler',$die);remove_filter('query',$queries);$_REQUEST=$request;$_GET=$get;if ($adminInit!==null) $GLOBALS['wp_filter']['admin_init']=$adminInit;else unset($GLOBALS['wp_filter']['admin_init']); }
        self::assertTrue($denied);self::assertSame(0,$reads);
    }
}
