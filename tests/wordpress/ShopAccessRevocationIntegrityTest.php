<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';

final class ShopAccessRevocationIntegrityTest extends WP_UnitTestCase
{
    private function resetSyntheticLedger(): void
    {
        global $wpdb;
        $rows=$wpdb->get_results("SELECT id,context FROM ".DigiForge\Database\Tables::audit_log()." WHERE object_type='shop_access' AND event_type IN ('shop_access_grant','shop_access_revoke')",ARRAY_A);
        foreach($rows as $row){
            $event=json_decode($row['context'],true);$grant=$event['grant']??null;
            if(!is_array($grant)||!is_int($grant['grantor_id']??null)||!DigiForge\Security\ShopAccessPolicy::validShop($event['shop']??''))continue;
            $synthetic=hash('sha256','Synthetic independent authority '.$grant['grantor_id'].':'.$event['shop']);
            if(($grant['source_approval_hash']??null)===$synthetic)$wpdb->delete(DigiForge\Database\Tables::audit_log(),['id'=>$row['id'],'context'=>$row['context']]);
        }
        ShopAccessFixture::reset();
    }
    public function testRetainedRevocationRejectsRolledBackMutableSnapshot(): void
    {
        DigiForge\Core\Activator::activate();
        $this->resetSyntheticLedger();
        $owner=self::factory()->user->create(['role'=>'administrator']);
        $subject=self::factory()->user->create(['role'=>'administrator']);
        $cap='manage_digiforge_research';
        ShopAccessFixture::provision([ShopAccessFixture::authority($owner,'digital',[$cap])]);
        $repo=new DigiForge\Security\ShopAccessRepository();
        $grant=ShopAccessFixture::grant($owner,$subject,'digital',[$cap]);
        $before=$repo->read('digital');
        wp_set_current_user($subject);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        wp_set_current_user($owner);
        self::assertNotWPError($repo->revoke('digital',$grant['grant_id'],'retained_revoke_request',$before['revision']));
        $after=$repo->read('digital');
        global $wpdb;
        foreach([$before,['revision'=>$after['revision'],'grants'=>$before['grants']]] as $rollback){
            $wpdb->update($wpdb->options,['option_value'=>wp_json_encode($rollback)],['option_name'=>'digiforge_shop_access_state_digital']);
            wp_set_current_user($subject);
            self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        }
        // Losing just the revoke audit cannot make its retained immutable event invisible.
        $wpdb->update($wpdb->options,['option_value'=>wp_json_encode($before)],['option_name'=>'digiforge_shop_access_state_digital']);
        $missingRevokeAudit=static function(string $sql):string{return str_starts_with($sql,'SELECT')&&str_contains($sql,"event_type IN ('shop_access_grant','shop_access_revoke')")?str_replace('ORDER BY id ASC',"AND event_type<>'shop_access_revoke' ORDER BY id ASC",$sql):$sql;};
        add_filter('query',$missingRevokeAudit);
        try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$missingRevokeAudit);}
        $wpdb->update($wpdb->options,['option_value'=>wp_json_encode($after)],['option_name'=>'digiforge_shop_access_state_digital']);
        self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));
    }

    public function testActiveGrantRequiresCompleteCurrentLifecycleEvidence(): void
    {
        DigiForge\Core\Activator::activate();
        $this->resetSyntheticLedger();
        $owner=self::factory()->user->create(['role'=>'administrator']);
        $subject=self::factory()->user->create(['role'=>'administrator']);
        $cap='manage_digiforge_research';
        ShopAccessFixture::provision([ShopAccessFixture::authority($owner,'digital',[$cap])]);
        $repo=new DigiForge\Security\ShopAccessRepository();
        $first=ShopAccessFixture::grant($owner,$subject,'digital',[$cap]);
        wp_set_current_user($owner);
        self::assertNotWPError($repo->revoke('digital',$first['grant_id'],'lifecycle_revoke_request',$first['revision']));
        ShopAccessFixture::grant($owner,$subject,'digital',[$cap]);
        wp_set_current_user($subject);
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital',$cap));
        global $wpdb;
        $eventKey='digiforge_shop_access_event_'.hash('sha256','digital:lifecycle_revoke_request');
        $missing=static function(string $sql)use($eventKey):string{return str_starts_with($sql,'SELECT')&&str_contains($sql,$eventKey)?'SELECT NULL':$sql;};
        add_filter('query',$missing);
        try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$missing);}
        $corrupt=static function(string $sql)use($eventKey):string{return str_starts_with($sql,'SELECT')&&str_contains($sql,$eventKey)?"SELECT '{}'":$sql;};
        add_filter('query',$corrupt);
        try{self::assertWPError(DigiForge\Security\ShopAccess::authorize('digital',$cap));}finally{remove_filter('query',$corrupt);}
        $broken=static function(string $sql)use($eventKey):string{return str_starts_with($sql,'SELECT')&&str_contains($sql,$eventKey)?'SELECT option_value FROM missing_acl_lifecycle_table':$sql;};
        add_filter('query',$broken);$wpdb->suppress_errors(true);
        try{$error=DigiForge\Security\ShopAccess::authorize('digital',$cap);self::assertWPError($error);self::assertSame(503,$error->get_error_data()['status']);}finally{remove_filter('query',$broken);$wpdb->suppress_errors(false);}
        self::assertTrue(DigiForge\Security\ShopAccess::authorize('digital',$cap));
    }
}
