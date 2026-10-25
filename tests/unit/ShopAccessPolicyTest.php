<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/Core/Capabilities.php';
require_once __DIR__.'/../../includes/Security/ShopAccessPolicy.php';
require_once __DIR__.'/../../includes/Security/ShopAccessAuthority.php';
final class ShopAccessPolicyTest extends PHPUnit\Framework\TestCase
{
    public function testOwnerEnrollmentRequiresExactSignedRootActiveShopAndApproval(): void
    {
        $r=['actor_id'=>7,'shop'=>'digital','caps'=>['manage_digiforge'],'expires_at'=>200,'source_approval_hash'=>str_repeat('a',64),'authority'=>'OWNER'];
        self::assertTrue(is_callable([DigiForge\Security\ShopAccessPolicy::class,'canEnrollOwner']), 'Explicit signed-owner enrollment policy is missing');
        self::assertTrue(DigiForge\Security\ShopAccessPolicy::canEnrollOwner($r,7,'digital',['manage_digiforge'],150,str_repeat('a',64),100));
        foreach([['actor_id'=>8],['shop'=>'personalized_pod'],['authority'=>'DELEGATE'],['expires_at'=>100],['source_approval_hash'=>str_repeat('b',64)]] as $change){
            self::assertFalse(DigiForge\Security\ShopAccessPolicy::canEnrollOwner(array_replace($r,$change),7,'digital',['manage_digiforge'],150,str_repeat('a',64),100));
        }
        self::assertFalse(DigiForge\Security\ShopAccessPolicy::canEnrollOwner($r,7,'digital',['manage_digiforge_ai'],150,str_repeat('a',64),100));
        $r['shop']='unsupported';self::assertFalse(DigiForge\Security\ShopAccessPolicy::canEnrollOwner($r,7,'unsupported',['manage_digiforge'],150,str_repeat('a',64),100));
    }

    public function testActorShopExpiryRevocationAndOrdinaryCapabilitiesAreAllRequired(): void
    {
        $r=['actor_id'=>7,'shop'=>'jewelry','caps'=>['manage_digiforge_research'],'expires_at'=>101,'source_approval_hash'=>str_repeat('a',64),'revoked'=>false];
        self::assertTrue(DigiForge\Security\ShopAccessPolicy::permits($r,7,'jewelry','manage_digiforge_research',100,true));
        foreach([[7,'jewelry','manage_digiforge_research',101,true],[8,'jewelry','manage_digiforge_research',100,true],[7,'digital','manage_digiforge_research',100,true],[7,'jewelry','publish_digiforge',100,true],[7,'jewelry','manage_digiforge_research',100,false]] as $args)self::assertFalse(DigiForge\Security\ShopAccessPolicy::permits($r,...$args));
        $r['revoked']=true;self::assertFalse(DigiForge\Security\ShopAccessPolicy::permits($r,7,'jewelry','manage_digiforge_research',100,true));
    }
    public function testMalformedRecordsNeverProvidePrivileges(): void
    {
        foreach([null,[],['actor_id'=>'1'],['caps'=>['manage_options']]] as $r)self::assertFalse(DigiForge\Security\ShopAccessPolicy::validRecord($r));
        self::assertSame(['digital','personalized_pod','standard_pod','jewelry'],DigiForge\Security\ShopAccessPolicy::canonicalShops());
    }
    public function testAssociativeCapabilityObjectsAndNewlineHashesFailClosed(): void
    {
        $r=['actor_id'=>7,'shop'=>'digital','caps'=>['named'=>'manage_digiforge_ai'],'expires_at'=>101,'source_approval_hash'=>str_repeat('a',64)];
        self::assertFalse(DigiForge\Security\ShopAccessPolicy::validRecord($r));
        $r['caps']=['manage_digiforge_ai'];$r['source_approval_hash'].="\n";
        self::assertFalse(DigiForge\Security\ShopAccessPolicy::validRecord($r));
    }
    public function testAuthorityRecordsMustBeTypedListWithCurrentExplicitKind(): void
    {
        $r=['actor_id'=>7,'shop'=>'digital','caps'=>['manage_digiforge_ai'],'expires_at'=>101,'source_approval_hash'=>str_repeat('a',64),'authority'=>'DELEGATE'];
        self::assertTrue(DigiForge\Security\ShopAccessAuthority::validAuthorities([$r],100));
        self::assertFalse(DigiForge\Security\ShopAccessAuthority::validAuthorities(['named'=>$r],100));
        self::assertFalse(DigiForge\Security\ShopAccessAuthority::validAuthorities([$r],101));
        $r['authority']='ADMIN';self::assertFalse(DigiForge\Security\ShopAccessAuthority::validAuthorities([$r],100));
    }
}
