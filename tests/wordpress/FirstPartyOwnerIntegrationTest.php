<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

final class FirstPartyOwnerIntegrationTest extends WP_UnitTestCase
{
    /**
     * The protected constant and ID 1 fixture must not leak into other test cases.
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNativeOwnerEnrollmentLifecycleAndIdentityRevocation(): void
    {
        DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
        $user=get_userdata(1);self::assertInstanceOf(WP_User::class,$user);
        wp_update_user(['ID'=>1,'user_email'=>'subvicco12@gmail.com']);$user=new WP_User(1);$user->set_role('administrator');wp_set_current_user(1);
        global $wpdb;
        $identity=$wpdb->get_row("SELECT ID,user_login,user_email,user_registered,user_pass FROM {$wpdb->users} WHERE ID=1",ARRAY_A);$identity['ID']=1;
        define('DIGIFORGE_FIRST_PARTY_OWNER',['version'=>1,'account_sha256'=>DigiForge\Security\FirstPartyOwnerPolicy::fingerprint($identity),'installation_sha256'=>hash('sha256',site_url('/').'|'.$wpdb->prefix),'email_verified_at'=>time()-1,'expires_at'=>time()+3600]);
        $records=DigiForge\Security\ShopAccessAuthority::records();self::assertNotWPError($records);self::assertCount(4,$records);
        $panel=new DigiForge\Portal\ShopAccessPanel();$repo=new DigiForge\Security\ShopAccessRepository();
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge'));
        $html=$panel->render();self::assertStringContainsString('First-party OWNER',$html);
        foreach($records as $record){
            $p=['_wpnonce'=>wp_create_nonce($panel::ACTION),'confirm'=>'CONFIRM_EXPLICIT_SHOP_ACCESS','operation'=>'ENROLL','shop'=>$record['shop'],'role'=>'administrator','caps'=>DigiForge\Core\Capabilities::ALL,'expires_at'=>(string)(time()+600),'approval'=>$record['source_approval_hash'],'request_id'=>'native_owner_'.$record['shop'].'_001','expected_revision'=>'0'];
            foreach([['_wpnonce'=>'bad'],['confirm'=>''],['approval'=>str_repeat('b',64)],['role'=>'editor'],['subject_id'=>'2'],['email'=>'subvicco12@gmail.com']] as $change)self::assertWPError($panel->processBrowser(array_replace($p,$change)));
            self::assertWPError($panel->processBrowser($p,'GET'));
            $result=$panel->processBrowser($p);self::assertNotWPError($result);self::assertFalse($result['external_execution_authorized']);
            foreach(DigiForge\Core\Capabilities::ALL as $cap)self::assertTrue(DigiForge\Security\ShopAccess::authorize($record['shop'],$cap));
            $p['expected_revision']='1';self::assertWPError($panel->processBrowser($p));
        }
        // Ordinary administrators and matching request email cannot enroll themselves.
        $operator=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($operator);
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge'));
        self::assertWPError($panel->processBrowser(array_replace($p,['_wpnonce'=>wp_create_nonce($panel::ACTION),'request_id'=>'native_foreign_actor_001'])));
        wp_set_current_user(1);
        // Delegation is explicit and local; Finance remains forbidden in browser GRANT.
        $p=array_replace($p,['_wpnonce'=>wp_create_nonce($panel::ACTION),'operation'=>'GRANT','subject_id'=>(string)$operator,'shop'=>'digital','caps'=>['manage_digiforge'],'expected_revision'=>'1','request_id'=>'native_operator_grant_001']);
        self::assertWPError($panel->processBrowser(array_replace($p,['caps'=>['manage_digiforge_finance']])));
        $grant=$panel->processBrowser($p);self::assertNotWPError($grant);
        wp_set_current_user($operator);self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge'));self::assertWPError(DigiForge\Security\ShopAccess::authorize('jewelry','manage_digiforge'));
        // Direct DB mutation intentionally leaves the old WP object cache populated.
        $wpdb->update($wpdb->users,['user_registered'=>'2026-01-01 01:01:01'],['ID'=>1]);
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge'));
        wp_set_current_user(1);self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge'));
        $wpdb->update($wpdb->users,['user_registered'=>$identity['user_registered']],['ID'=>1]);clean_user_cache(1);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge'));
        $role=get_role('administrator');$role->remove_cap('manage_digiforge_ai');
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge'));$role->add_cap('manage_digiforge_ai');
        $state=$repo->read('digital');$ownerId=array_key_first($state['grants']);
        self::assertNotWPError($repo->revoke('digital',$ownerId,'native_owner_revoke_001',$state['revision']));
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital','manage_digiforge'));
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertTrue(DigiForge\Core\Settings::safety_locked());self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }
}
