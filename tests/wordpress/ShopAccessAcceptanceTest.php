<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Synthetic independently signed authority; no production owner identity is asserted. */
final class ShopAccessAcceptanceTest extends WP_UnitTestCase
{
    public function testExplicitSignedAuthorityAssignmentsRevokeExpiryScopesAndAuditFailClosed(): void
    {
        DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();ShopAccessFixture::reset();
        $owner=self::factory()->user->create(['role'=>'administrator']);$subject=self::factory()->user->create(['role'=>'administrator']);wp_set_current_user($owner);
        $repo=new DigiForge\Security\ShopAccessRepository();$cap='manage_digiforge_research';
        global $wpdb;$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".DigiForge\Database\Tables::audit_log()." WHERE event_type IN ('shop_access_grant','shop_access_revoke')");
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        self::assertWPError($repo->grant($subject,'digital','administrator',[$cap],time()+60,'missing_authority_123',0));
        $keys=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keys);$public=sodium_crypto_sign_publickey($keys);
        $record=ShopAccessFixture::authority($owner,'digital',[$cap]);
        $json=(string)wp_json_encode(['version'=>1,'authorities'=>[$record]]);$signature=base64_encode(sodium_crypto_sign_detached($json,$secret));sodium_memzero($secret);
        self::assertWPError(DigiForge\Security\ShopAccessAuthority::verify($json.' ',$signature,base64_encode($public),time()));
        self::assertWPError(DigiForge\Security\ShopAccessAuthority::verify($json,$signature,base64_encode($public),time()+4000));
        ShopAccessFixture::provision([$record]);
        $baseline=$repo->read('digital');self::assertNotWPError($baseline);$revision=$baseline['revision'];$initialGrants=count($baseline['grants']);
        self::assertWPError($repo->grant($owner,'digital','administrator',[$cap],time()+60,'self_grant_request1',$revision));
        self::assertWPError($repo->grant($subject,'jewelry','administrator',[$cap],time()+60,'cross_shop_request1',0));
        self::assertWPError($repo->grant($subject,'digital','administrator',['publish_digiforge'],time()+60,'scope_escalation_1',$revision));
        self::assertWPError($repo->grant($subject,'digital','administrator',[$cap],time()+7200,'expiry_escalation1',$revision));
        $grant=$repo->grant($subject,'digital','administrator',[$cap],time()+600,'accepted_grant_123',$revision);self::assertNotWPError($grant);self::assertSame($revision+1,$grant['revision']);
        self::assertWPError($repo->grant($subject,'digital','administrator',[$cap],time()+600,'stale_revision_123',$revision));
        self::assertWPError($repo->grant($subject,'digital','administrator',[$cap],time()+600,'accepted_grant_123',$revision+1));
        wp_set_current_user($subject);self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        // Corrupted/lost immutable evidence must not be replaced by a well-shaped snapshot.
        $eventKey='digiforge_shop_access_event_'.$grant['grant_id'];
        $missingEvent=static function(string $sql)use($eventKey):string{return str_contains($sql,$eventKey)&&str_starts_with($sql,'SELECT')?'SELECT NULL':$sql;};
        add_filter('query',$missingEvent);try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$missingEvent);}
        $auditTable=DigiForge\Database\Tables::audit_log();
        $missingAudit=static function(string $sql)use($auditTable):string{return str_starts_with($sql,'SELECT')&&str_contains($sql,$auditTable)&&str_contains($sql,'shop_access_grant')?'SELECT NULL':$sql;};
        add_filter('query',$missingAudit);try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$missingAudit);}
        $tamperedAudit=static function(string $sql)use($auditTable):string{return str_starts_with($sql,'SELECT')&&str_contains($sql,$auditTable)&&str_contains($sql,'shop_access_grant')?"SELECT '{\"action\":\"GRANT\"}'":$sql;};
        add_filter('query',$tamperedAudit);try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$tamperedAudit);}
        $brokenEvent=static function(string $sql)use($eventKey):string{return str_contains($sql,$eventKey)&&str_starts_with($sql,'SELECT')?"SELECT '{\"action\":\"GRANT\",\"grant\":{}}'":$sql;};
        add_filter('query',$brokenEvent);try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$brokenEvent);}
        $queryFailure=static function(string $sql)use($eventKey):string{return str_contains($sql,$eventKey)&&str_starts_with($sql,'SELECT')?'SELECT option_value FROM missing_shop_grant_evidence':$sql;};
        add_filter('query',$queryFailure);$wpdb->suppress_errors(true);try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$queryFailure);$wpdb->suppress_errors(false);}
        $forgedActor=self::factory()->user->create(['role'=>'administrator']);$forgedState=$repo->read('digital');$forgedState['grants'][$grant['grant_id']]['actor_id']=$forgedActor;
        $forged=static function(string $sql)use($wpdb,$forgedState):string{return str_starts_with($sql,'SELECT option_value')&&str_contains($sql,'digiforge_shop_access_state_digital')?$wpdb->prepare('SELECT %s',wp_json_encode($forgedState)):$sql;};
        add_filter('query',$forged);try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap,$forgedActor));}finally{remove_filter('query',$forged);}
        $negativeState=$repo->read('digital');$negativeState['revision']=-1;
        $negativeRevision=static function(string $sql)use($wpdb,$negativeState):string{return str_starts_with($sql,'SELECT option_value')&&str_contains($sql,'digiforge_shop_access_state_digital')?$wpdb->prepare('SELECT %s',wp_json_encode($negativeState)):$sql;};
        add_filter('query',$negativeRevision);try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$negativeRevision);}
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('personalized_pod',$cap));self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital','publish_digiforge'));
        self::assertSame(['digital'],DigiForge\Security\ShopAccess::allowedShops($cap));
        $user=get_userdata($subject);$user->remove_role('administrator');self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));$user->add_role('administrator');
        ShopAccessFixture::provision([]);self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        $expired=$record;$expired['expires_at']=time()-1;ShopAccessFixture::provision([$expired]);self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        ShopAccessFixture::provision([$record]);self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        wp_set_current_user($owner);$revoked=$repo->revoke('digital',$grant['grant_id'],'revoke_request_123',$revision+1);self::assertNotWPError($revoked);self::assertSame($revision+2,$revoked['revision']);
        wp_set_current_user($subject);self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        global $wpdb;$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".DigiForge\Database\Tables::audit_log()." WHERE event_type IN ('shop_access_grant','shop_access_revoke')");self::assertSame($before+2,$count);
        wp_set_current_user($owner);$auditTable=DigiForge\Database\Tables::audit_log();$filter=static function(string $sql)use($auditTable):string{return str_starts_with($sql,'INSERT INTO `'.$auditTable.'`')?str_replace('`'.$auditTable.'`','`missing_shop_acl_audit_table`',$sql):$sql;};
        add_filter('query',$filter);$wpdb->suppress_errors(true);try{$failed=$repo->grant($subject,'digital','administrator',[$cap],time()+60,'audit_failure_123',$revision+2);}finally{remove_filter('query',$filter);$wpdb->suppress_errors(false);}
        self::assertWPError($failed);self::assertSame($revision+2,$repo->read('digital')['revision']);self::assertCount($initialGrants+1,$repo->read('digital')['grants']);
        self::assertTrue(DigiForge\Core\Settings::get('stop_all',true));self::assertFalse(DigiForge\Core\Settings::get('automation_armed',false));
    }
}
