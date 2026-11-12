<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\DigitalFactory\DriveGoogleReadModel;
use DigiForge\Security\ShopAccess;

/** Selected Digital owner controls. Rendering is read-only and never supplies execution authority. */
final class GoogleDrivePanel
{
    public function __construct(private ?\Closure $reader=null){}
    public function render(string $shop):void
    {
        echo '<section class="df-panel df-google-panel"><h2>Google document preparation</h2>';
        if($shop!=='digital'){echo '<p>Select the Digital shop to inspect its document workflow.</p><a class="df-button" href="'.esc_url(add_query_arg(['df_view'=>'digital','df_shop'=>'digital'],home_url('/'))).'">Open Digital documents</a></section>';return;}
        if(ShopAccess::authorize('digital','manage_digiforge_products')!==true){echo '<p>Current Digital product access is required.</p></section>';return;}
        $focus=$_GET['df_google_plan']??null;$focus=is_string($focus)?$focus:null;
        $data=$this->reader!==null?($this->reader)($shop,$focus):(new DriveGoogleReadModel())->snapshot($shop,$focus);
        echo '<p>KINETIQ MATRIX DESIGNS · Digital shop. Connecting, authorizing a transfer, technical QA and commercial approval are separate steps. This page cannot activate automation or release a product.</p>';
        if(($data['state']??null)!=='AVAILABLE'){echo '<p class="df-notice df-notice-error">Document evidence unavailable. No empty history or execution permission is inferred.</p></section>';return;}
        echo '<p class="df-muted">Controls remain disabled while STOP ALL, the external lock, missing server configuration, expired authority or current ownership prevents execution. Never enter credentials here.</p>';
        foreach($data['sources'] as $source){
            echo '<article><h3>Product #'.esc_html((string)$source['product_id']).' · version #'.esc_html((string)$source['version_id']).' · source revision #'.esc_html((string)$source['revision_id']).'</h3><p>Source SHA-256 <code style="overflow-wrap:anywhere">'.esc_html($source['source_sha256']).'</code></p>';
            $this->form('digiforge_google_oauth_start',['revision_id'=>(string)$source['revision_id'],'request_id'=>bin2hex(random_bytes(32))],'Connect Google for this source',$source['enabled']);echo '</article>';
        }
        if($data['sources']===[])echo '<p>No eligible admitted DOCX source is visible in the recent bounded window. An original Library file alone is not a registered source revision.</p>';
        foreach($data['plans'] as $p){
            echo '<article><h3>Document transfer · product #'.esc_html((string)$p['product_id']).' · version #'.esc_html((string)$p['version_id']).'</h3><dl><dt>Source revision</dt><dd>#'.esc_html((string)$p['revision_id']).'</dd><dt>Workflow</dt><dd>'.esc_html($p['state']).'</dd><dt>Connection</dt><dd>'.esc_html($p['connection']).'</dd><dt>Grant</dt><dd>'.esc_html($p['grant_state']).'</dd><dt>Provider acceptance</dt><dd>UNKNOWN</dd><dt>Commercial approval</dt><dd>PENDING</dd></dl><p>Source SHA-256 <code style="overflow-wrap:anywhere">'.esc_html($p['source_sha256']).'</code></p>';
            if($p['jobs']!==[]){echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Google document tasks"><table class="df-table"><thead><tr><th>Job</th><th>Stage</th><th>Job state</th><th>Scheduler evidence</th></tr></thead><tbody>';foreach($p['jobs'] as $j)echo '<tr><td>#'.esc_html((string)$j['id']).'</td><td>'.esc_html($j['stage']).'</td><td>'.esc_html($j['state']).'</td><td>'.esc_html($j['scheduler']).'</td></tr>';echo '</tbody></table></div>';}
            $base=['plan_id'=>$p['id']];$a=$p['actions'];
            $this->form('digiforge_google_queue',$base,'Authorize source-bound transfer',$a['queue']);
            $this->form('digiforge_google_queue',$base+['refresh'=>'connection'],'Refresh connection only',$a['refresh']);
            $this->form('digiforge_google_queue',$base+['renew'=>'grant'],'Renew reviewed grant only',$a['renew']);
            foreach(['original_upload','native_conversion'] as $role)$this->form('digiforge_google_queue',$base+['reconcile'=>$role],'Reconcile '.str_replace('_',' ',$role).' by read only',$a[$role]);
            echo '<p class="df-muted">UNKNOWN needs review. Reconciliation cannot repeat a create. Refresh does not renew a grant; renewal does not schedule a transfer. Technical QA never grants visual, rights or commercial approval.</p></article>';
        }
        if($data['plans']===[])echo '<p>No owned transfer is visible in this bounded window.</p>';
        if($data['truncated'])echo '<p>Recent window only; older evidence is not asserted absent. Follow the exact source-bound return link to focus a transfer.</p>';
        echo '</section>';
    }
    private function form(string $action,array $fields,string $label,bool $enabled):void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php','https')).'" class="df-review-form"><input type="hidden" name="action" value="'.esc_attr($action).'"><input type="hidden" name="_wpnonce" value="'.esc_attr(wp_create_nonce($action)).'">';
        foreach($fields as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
        echo '<p><label><input type="checkbox" name="df_confirm_google_action" value="1" required'.($enabled?'':' disabled').'> I reviewed this exact source and request only the action below.</label></p><button class="df-button" type="submit"'.($enabled?'':' disabled').'>'.esc_html($label).'</button></form>';
    }
}
