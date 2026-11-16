<?php
declare(strict_types=1);
/** Restores only synthetic image fixtures across the module's intentional COMMIT boundary. */
final class ImageGenerationPolicyJournalFixture
{
 private array $floors=[];private array $enabled=[];private mixed $roles;
 public function capture():void {global $wpdb;foreach(['ai_models','ai_tasks','integrations','integration_secrets','audit_log']as $method){$table=DigiForge\Database\Tables::{$method}();$this->floors[$table]=(int)$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.$table);}$this->floors[$wpdb->options]=(int)$wpdb->get_var('SELECT COALESCE(MAX(option_id),0) FROM '.$wpdb->options);$this->enabled=$wpdb->get_results('SELECT id,enabled FROM '.DigiForge\Database\Tables::ai_models(),ARRAY_A);$this->roles=get_option($wpdb->prefix.'user_roles');}
 public function restore():void {global $wpdb;$wpdb->query('ROLLBACK');$wpdb->query('SET autocommit=1');foreach(array_reverse($this->floors,true)as $table=>$floor){$column=$table===$wpdb->options?'option_id':'id';if($wpdb->query('DELETE FROM '.$table.' WHERE '.$column.'>'.(int)$floor)===false)throw new RuntimeException('Synthetic image cleanup failed.');}foreach($this->enabled as $row)$wpdb->update(DigiForge\Database\Tables::ai_models(),['enabled'=>$row['enabled']],['id'=>$row['id']]);$wpdb->update($wpdb->options,['option_value'=>serialize($this->roles)],['option_name'=>$wpdb->prefix.'user_roles']);wp_cache_delete('alloptions','options');wp_cache_delete($wpdb->prefix.'user_roles','options');wp_roles()->for_site();}
}
