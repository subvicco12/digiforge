<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Research\OpportunityDesignRepository;
use WP_Error;

/** Manual, local-only path in the existing front-end portal. */
final class OpportunityDesignPanel
{
    private const ACTION='digiforge_opportunity_design';
    public function register(): void { (new CommercialResearchPanel())->register(); add_action('admin_post_'.self::ACTION,[$this,'submit']); }
    public function render(string $shop): void
    {
        if ($shop!=='personalized_pod') return;
        (new CommercialResearchPanel())->render($shop);
        if (!\DigiForge\Security\PersonalizedPodAccess::allows() || $shop!=='personalized_pod' || !current_user_can('manage_digiforge_pod') || !current_user_can('manage_digiforge_research')) return;
        echo '<section class="df-panel"><h2>Opportunity → three briefs → editable artwork</h2><p>Manual source and artwork import. External automation unavailable. Source claims are unverified operator evidence. STOP ALL and RELEASE HOLD remain in force.</p><p>Import a JSON object with shop_key=personalized_pod, title, sources [{url, observed_at (UTC ISO timestamp), excerpt}], exactly three briefs [{audience, visual_direction, rationale}], and artwork {svg, width_px, height_px, dpi:300, selected_brief:1–3, native_layers:[], rights_note, safe_area_reviewed:true, font_license_reviewed:true, mockup_reviewed:true}. SVG supports local shapes and text only. Supplier geometry and printed output require separate verification. Edit and reimport to create a new immutable version.</p>';
        $hash=$_GET['df_design_version'] ?? '';
        $version=is_string($hash)&&preg_match('/^[a-f0-9]{64}$/D',$hash)?(new OpportunityDesignRepository())->read($shop,$hash):null;
        if ($version instanceof WP_Error) echo '<p>'.esc_html($version->get_error_message()).'</p>';
        if (is_array($version)) {
            echo '<p>Version <code>'.esc_html($hash).'</code> · Actor '.esc_html((string)$version['record']['created_by']).' · '.esc_html($version['record']['created_at']).' · '.esc_html($version['approval']['state'] ?? 'PENDING_REVIEW').'</p>';
        }
        $this->form('import',$shop);
        $value=is_array($version)?wp_json_encode($version['record']['input'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):'';
        echo '<label>Editable evidence and SVG JSON<textarea name="evidence_json" rows="18" maxlength="400000" required>'.esc_textarea(is_string($value)?$value:'').'</textarea></label><button class="df-button">Save new immutable version</button></form>';
        $this->form('inspect',$shop);
        echo '<label>Open exact saved version <input name="version_hash" pattern="[a-f0-9]{64}" maxlength="64" required></label><button class="df-button">Inspect</button></form>';
        if (is_array($version) && $version['approval']===null) {
            $this->form('decide',$shop);
            echo '<input type="hidden" name="version_hash" value="'.esc_attr($hash).'"><p><label><input type="checkbox" name="confirm" value="1" required> I reviewed this exact source evidence, three briefs, rights, safe area, mockup and editable artwork. This approves local artwork only.</label></p><select name="decision"><option value="REJECT">Reject</option><option value="APPROVE">Approve artwork</option></select><button class="df-button">Record immutable decision</button></form>';
        }
        echo '</section>';
    }
    private function form(string $operation,string $shop): void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="shop_key" value="'.esc_attr($shop).'">';$id=get_queried_object_id();
        $return=$id>0?get_permalink($id):home_url('/');
        echo '<input type="hidden" name="return_url" value="'.esc_attr(is_string($return)?$return:home_url('/')).'">';wp_nonce_field(self::ACTION);
    }
    public function submit(): never
    {
        check_admin_referer(self::ACTION);
        $result=$this->process(wp_unslash($_POST));
        $args=['df_view'=>'research','df_shop'=>'personalized_pod'];
        if ($result instanceof WP_Error) $args+=['df_message'=>$result->get_error_message(),'df_error'=>'1'];
        else $args+=['df_design_version'=>$result['version_hash'],'df_message'=>'Local version evidence recorded. External execution remains locked.'];
        $return=$_POST['return_url'] ?? null;
        $return=is_string($return)?wp_validate_redirect(wp_unslash($return),home_url('/')):home_url('/');
        wp_safe_redirect(add_query_arg($args,$return));exit;
    }
    /** Shared POST parser; repository rechecks actor/capabilities and exact shop on every action. */
    public function process(array $post): array|WP_Error
    {
        if (($post['shop_key'] ?? null)!=='personalized_pod') return new WP_Error('opportunity_scope','Personalized POD shop required.');
        $repo=new OpportunityDesignRepository();
        if (($post['operation'] ?? null)==='import') {
            $raw=$post['evidence_json'] ?? null;
            if (!is_string($raw) || strlen($raw)>400000) return new WP_Error('opportunity_input','Bounded JSON required.');
            $input=json_decode($raw,true,32);
            if (!is_array($input)) return new WP_Error('opportunity_input','Valid evidence object required.');
            return $repo->save($input);
        }
        $hash=$post['version_hash'] ?? null;
        if (!is_string($hash)) return new WP_Error('opportunity_input','Exact version required.');
        if (($post['operation'] ?? null)==='inspect') return $repo->read('personalized_pod',$hash);
        if (($post['operation'] ?? null)==='decide' && ($post['confirm'] ?? null)==='1' && is_string($post['decision'] ?? null)) return $repo->decide('personalized_pod',$hash,$post['decision']);
        return new WP_Error('opportunity_input','Explicit review confirmation required.');
    }
}
