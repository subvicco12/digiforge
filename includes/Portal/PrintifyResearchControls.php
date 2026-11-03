<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\POD\PrintifyResearchBatch;

/** Explicit human forms inside the existing Shop 2 panel; never a scheduled execution path. */
final class PrintifyResearchControls
{
    public function register(): void
    {
        add_action('admin_post_digiforge_pod_research_prepare',[$this,'prepare']);
        add_action('admin_post_digiforge_pod_research_step',[$this,'step']);
    }
    public function prepare(): void
    {
        $this->guard('digiforge_pod_research_prepare');
        $snapshot=$_POST['snapshot_id']??null;$text=$_POST['blueprint_ids']??null;
        if(!is_string($snapshot)||!is_string($text)||strlen($text)>1200)wp_die('Invalid bounded research plan.', '', ['response'=>400]);
        $ids=[];foreach(explode(',',wp_unslash($text)) as $item){$item=trim($item);if(!preg_match('/^[1-9][0-9]{0,9}$/D',$item))wp_die('Explicit positive blueprint IDs required.', '', ['response'=>400]);$ids[]=(int)$item;}
        $result=(new PrintifyResearchBatch())->create(wp_unslash($snapshot),$ids);$this->finish($result);
    }
    public function step(): void
    {
        $this->guard('digiforge_pod_research_step');
        $id=$_POST['batch_id']??null;$grant=$_POST['grant_sha256']??null;
        if(!is_string($id)||!is_string($grant)||($_POST['confirm_catalog_get']??null)!=='1')wp_die('Explicit governed catalog collection confirmation required.', '', ['response'=>400]);
        $result=(new PrintifyResearchBatch())->step(wp_unslash($id),wp_unslash($grant));$this->finish($result);
    }
    private function guard(string $action): void
    {
        if(!PrintifyResearchBatch::allowed())wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer($action);
        if(($_POST['shop_key']??null)!=='personalized_pod')wp_die('Shop 2 scope required.', '', ['response'=>400]);
    }
    private function finish(array|\WP_Error $result): never
    {
        if(is_wp_error($result))wp_die(esc_html($result->get_error_message()), '', ['response'=>409]);
        $url=wp_get_referer()?:home_url('/');
        wp_safe_redirect(add_query_arg(['df_view'=>'pod_personalized','df_shop'=>'personalized_pod','df_pi_batch'=>$result['batch_id']],$url).'#df-product-intelligence');exit;
    }
    public function render(string $snapshot,array $readiness): void
    {
        if(!PrintifyResearchBatch::allowed())return;
        echo '<details><summary>Prepare or resume provider discovery</summary><p>Preparation writes local plan evidence only. Up to 100 source-backed blueprints; every existing grant remains limited to ten requests. Each collect/resume submission advances at most one blueprint. Interrupted attempts recover their receipt or stop for reconciliation.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="df-pi-filters"><input type="hidden" name="action" value="digiforge_pod_research_prepare"><input type="hidden" name="shop_key" value="personalized_pod"><input type="hidden" name="snapshot_id" value="'.esc_attr($snapshot).'">';wp_nonce_field('digiforge_pod_research_prepare');
        echo '<label>Blueprint IDs from this snapshot (comma-separated)<input name="blueprint_ids" maxlength="1200" required></label><button class="df-button" type="submit">Prepare local plan</button></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="df-pi-filters"><input type="hidden" name="action" value="digiforge_pod_research_step"><input type="hidden" name="shop_key" value="personalized_pod">';wp_nonce_field('digiforge_pod_research_step');
        echo '<label>Batch ID<input name="batch_id" pattern="[a-f0-9]{64}" maxlength="64" value="'.esc_attr(is_string($_GET['df_pi_batch']??null)?$_GET['df_pi_batch']:'').'" required></label><label>Existing approved grant SHA-256<input name="grant_sha256" pattern="[a-f0-9]{64}" maxlength="64" required></label><label><span><input type="checkbox" name="confirm_catalog_get" value="1" required> Explicitly request one governed catalog step</span></label><button class="df-button" type="submit" '.($readiness['pipeline_state']==='GOVERNED_PIPELINE_PRESENT'?'':'disabled').'>Collect / resume one step</button></form><p>Collection requires the existing account/shop binding, current owner-approved grant and any separately approved safety exception. This form cannot issue approvals, change STOP ALL or release the external lock.</p></details>';
    }
}
