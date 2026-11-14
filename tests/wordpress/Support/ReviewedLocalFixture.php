<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/fixtures/ShopAccessFixture.php';

/** Explicit synthetic local authority, never provider ownership or commercial evidence. */
final class ReviewedLocalFixture
{
    public static function actors(string $shop='digital'): array
    {
        // The real lifecycle commits audit + state atomically; custom audit tables
        // survive WordPress test rollbacks. Reset only this opt-in synthetic stream.
        global $wpdb;
        $table=DigiForge\Database\Tables::audit_log();
        $streams=$wpdb->get_col("SELECT DISTINCT object_id FROM {$table} WHERE object_type='reviewed_local_evidence'");
        foreach ($streams as $stream) {
            $wpdb->delete($table,['object_type'=>'reviewed_local_evidence','object_id'=>$stream]);
            delete_option('digiforge_local_review_'.$stream);
        }
        ShopAccessFixture::reset();
        $caps=['manage_digiforge','manage_digiforge_settings','manage_digiforge_connections','manage_digiforge_products','manage_digiforge_production','manage_digiforge_pod','manage_digiforge_ai'];
        $actors=[];
        foreach (['author','reviewer'] as $name) {
            $id=wp_insert_user(['user_login'=>'review_'.$name.'_'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>'administrator']);
            if (is_wp_error($id)) { throw new RuntimeException('Synthetic reviewer unavailable.'); }
            $actors[$name]=(int)$id;
            $actors[$name.'_owner']=ShopAccessFixture::authorizeActor((int)$id,[$shop],$caps);
        }
        wp_set_current_user($actors['author']);
        return $actors;
    }
}
