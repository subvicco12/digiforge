<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/Core/Capabilities.php';
require_once __DIR__.'/../../includes/Security/ShopAccessPolicy.php';
if(is_file(__DIR__.'/../../includes/Security/FirstPartyOwnerPolicy.php'))require_once __DIR__.'/../../includes/Security/FirstPartyOwnerPolicy.php';

final class FirstPartyOwnerPolicyTest extends PHPUnit\Framework\TestCase
{
    private function identity(): array
    {
        return ['ID'=>1,'user_login'=>'original-owner','user_email'=>'subvicco12@gmail.com','user_registered'=>'2025-01-01 00:00:00','user_pass'=>'synthetic-password-hash'];
    }
    private function config(array $identity): array
    {
        return ['version'=>1,'account_sha256'=>DigiForge\Security\FirstPartyOwnerPolicy::fingerprint($identity),'installation_sha256'=>hash('sha256','https://synthetic.invalid/|wp_'),'email_verified_at'=>100,'expires_at'=>1000];
    }
    public function testExactProtectedIdentityProvidesFifteenCapabilitiesForFourScopes(): void
    {
        self::assertTrue(class_exists(DigiForge\Security\FirstPartyOwnerPolicy::class),'First-party OWNER policy missing');
        $i=$this->identity();$c=$this->config($i);$caps=DigiForge\Core\Capabilities::ALL;
        $records=DigiForge\Security\FirstPartyOwnerPolicy::evaluate($c,$i,['administrator'],$caps,$caps,$c['installation_sha256'],200);
        self::assertCount(4,$records);
        foreach($records as $r){self::assertSame(1,$r['actor_id']);self::assertSame('OWNER',$r['authority']);self::assertSame($caps,$r['caps']);self::assertTrue(DigiForge\Security\ShopAccessPolicy::validRecord($r));}
        self::assertSame(DigiForge\Security\ShopAccessPolicy::canonicalShops(),array_column($records,'shop'));
        self::assertSame($records,DigiForge\Security\FirstPartyOwnerPolicy::evaluate($c,$i,['administrator'],$caps,$caps,$c['installation_sha256'],201));
    }
    public function testIdentityReplacementRoleCapabilityAndInstallationDriftDeny(): void
    {
        self::assertTrue(class_exists(DigiForge\Security\FirstPartyOwnerPolicy::class),'First-party OWNER policy missing');
        $i=$this->identity();$c=$this->config($i);$caps=DigiForge\Core\Capabilities::ALL;$class=DigiForge\Security\FirstPartyOwnerPolicy::class;
        foreach(['ID'=>2,'user_login'=>'replacement','user_email'=>'other@example.invalid','user_registered'=>'2026-01-01 00:00:00','user_pass'=>'reset'] as $k=>$v){self::assertSame([],$class::evaluate($c,array_replace($i,[$k=>$v]),['administrator'],$caps,$caps,$c['installation_sha256'],200));}
        foreach([[[], $caps,$caps], [['editor'],$caps,$caps], [['administrator'],array_slice($caps,1),$caps], [['administrator'],$caps,array_slice($caps,1)]] as [$roles,$userCaps,$roleCaps])self::assertSame([],$class::evaluate($c,$i,$roles,$userCaps,$roleCaps,$c['installation_sha256'],200));
        self::assertSame([],$class::evaluate($c,$i,['administrator'],$caps,$caps,str_repeat('a',64),200));
    }
    public function testMissingMalformedUnverifiedExpiredOrOverlongConfigurationDenies(): void
    {
        self::assertTrue(class_exists(DigiForge\Security\FirstPartyOwnerPolicy::class),'First-party OWNER policy missing');
        $i=$this->identity();$c=$this->config($i);$caps=DigiForge\Core\Capabilities::ALL;$class=DigiForge\Security\FirstPartyOwnerPolicy::class;
        foreach([null,[],array_replace($c,['version'=>'1']),array_replace($c,['email_verified_at'=>0]),array_replace($c,['email_verified_at'=>201]),array_replace($c,['expires_at'=>200]),array_replace($c,['expires_at'=>40000000]),array_replace($c,['account_sha256'=>str_repeat('a',64)."\n"]),$c+['email'=>'subvicco12@gmail.com']] as $bad)self::assertSame([],$class::evaluate($bad,$i,['administrator'],$caps,$caps,$c['installation_sha256'],200));
    }
    public function testFreshStoredRoleCapabilitiesCannotUseCachedOrMalformedEvidence(): void
    {
        $class=DigiForge\Security\FirstPartyOwnerPolicy::class;
        self::assertTrue(is_callable([$class,'storedRoleCapabilities']),'Fresh stored role validation missing');
        $caps=DigiForge\Core\Capabilities::ALL;$roles=['administrator'=>['name'=>'Administrator','capabilities'=>array_fill_keys($caps,true)]];
        self::assertSame($caps,$class::storedRoleCapabilities(serialize($roles)));
        $roles['administrator']['capabilities']['manage_digiforge_ai']=false;
        self::assertNotContains('manage_digiforge_ai',$class::storedRoleCapabilities(serialize($roles)));
        $roles['administrator']['capabilities']['manage_digiforge_ai']=1;
        self::assertNotContains('manage_digiforge_ai',$class::storedRoleCapabilities(serialize($roles)));
        foreach([null,[],false,'',serialize(new stdClass()),serialize(['editor'=>$roles['administrator']]),str_repeat('x',1048577)] as $raw)self::assertSame([],$class::storedRoleCapabilities($raw));
    }
}
