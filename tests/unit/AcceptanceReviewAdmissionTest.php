<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/includes/Security/ShopAccessRestGuard.php';

final class AcceptanceReviewAdmissionTest extends TestCase
{
    public function testExactAcceptanceMethodsRequireReadAndSettingsCapabilities(): void
    {
        foreach (['digital','personalized_pod'] as $shop) {
            $base='/digiforge/v1/reviewed-contracts/'.$shop.'/acceptance';
            self::assertSame([['manage_digiforge']],DigiForge\Security\ShopAccessRestGuard::capabilitySets($base,'GET'));
            foreach ([[$base,'POST'],[$base.'/proposal','GET'],[$base.'/decision','POST']] as [$route,$method]) {
                self::assertSame([['manage_digiforge','manage_digiforge_settings']],DigiForge\Security\ShopAccessRestGuard::capabilitySets($route,$method));
            }
            foreach ([[$base,'DELETE'],[$base.'/proposal','POST'],[$base.'/decision','GET'],[$base.'/decision/extra','POST']] as [$route,$method]) {
                self::assertSame([],DigiForge\Security\ShopAccessRestGuard::capabilitySets($route,$method));
            }
        }
        foreach (['standard_pod','jewelry'] as $shop) {
            foreach (['','/proposal','/decision'] as $suffix) self::assertSame([],DigiForge\Security\ShopAccessRestGuard::capabilitySets('/digiforge/v1/reviewed-contracts/'.$shop.'/acceptance'.$suffix,'POST'));
        }
    }
}
