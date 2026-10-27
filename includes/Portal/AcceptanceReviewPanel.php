<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Security\ShopAccess;

/** Website editor over the existing append-only acceptance review lifecycle. */
final class AcceptanceReviewPanel
{
    public static function render(string $shop): void
    {
        if (!in_array($shop,['digital','personalized_pod'],true)
            || is_wp_error(ShopAccess::authorize($shop,'manage_digiforge_settings'))) return;
        wp_enqueue_script('digiforge-acceptance-review',DIGIFORGE_URL.'assets/acceptance-review.js',[],
            DIGIFORGE_VERSION.'.'.substr(hash_file('sha256',DIGIFORGE_PATH.'assets/acceptance-review.js'),0,12),true);
        echo '<details data-acceptance-review data-shop="'.esc_attr($shop).'" data-endpoint="'.esc_url(rest_url('digiforge/v1/reviewed-contracts/'.$shop.'/')).'" data-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'"><summary>Propose and independently review acceptance evidence</summary><p>Local declarations only. A separate authorized reviewer must inspect the complete proposal before deciding. Review does not certify supplier facts or authorize production. Revisions and hashes must match current evidence. Never enter credentials or buyer personal data.</p>';
        echo '<form data-acceptance-write="inspect" class="df-review-form"><label>Criterion <select name="criterion">';
        foreach (ReviewedAcceptanceContract::criteria() as $criterion) {
            if ($shop==='digital' && in_array($criterion,['BP-seam-panel-render','BP-template-qa-spelling'],true)) continue;
            echo '<option value="'.esc_attr($criterion).'">'.esc_html($criterion).'</option>';
        }
        echo '</select></label><label>Exact scope <input name="scope" required maxlength="96" pattern="[A-Za-z0-9][A-Za-z0-9_.:-]*"></label><button class="df-button" type="submit">Inspect complete current proposal</button></form>';
        foreach (['proposal'=>'Propose local acceptance evidence','decision'=>'Record independent decision'] as $kind=>$label) {
            $example=$kind==='proposal'
                ? ['input'=>['version'=>2,'criterion'=>'BP-portal-7-supplier-readiness','scope'=>'replace-with-exact-product-scope','workflow'=>'supplier-review','definition'=>['policy'=>ApprovedAcceptancePolicy::rules('BP-portal-7-supplier-readiness'),'environment'=>'sandbox','subject'=>['scope'=>'PRODUCT_VERSION','product_id'=>'replace-product','product_version'=>'replace-version','supplier_id'=>'replace-supplier'],'period_start'=>'2026-01-01T00:00:00Z','period_end'=>'2027-01-01T00:00:00Z','source_records'=>[],'observations'=>null]],'effective_at'=>time(),'expires_at'=>time()+3600,'expected_revision'=>0]
                : ['criterion'=>'BP-portal-7-supplier-readiness','scope'=>'replace-with-exact-product-scope','expected_revision'=>1,'proposal_hash'=>'replace-with-inspected-proposal-hash','decision'=>'REJECTED'];
            echo '<form data-acceptance-write="'.esc_attr($kind).'" class="df-review-form"><fieldset><legend>'.esc_html($label).'</legend><label>Exact typed review document (JSON)<textarea name="payload" rows="16" required spellcheck="false">'.esc_textarea((string)wp_json_encode($example,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</textarea></label><p>Replace example identities. Missing observations stay UNKNOWN. Decision options: APPROVED, REJECTED, REVOKED. Approval requires a different authorized account; inspect the current revision and proposal hash above.</p><label><input type="checkbox" name="confirm" value="CONFIRM_LOCAL_ACCEPTANCE_REVIEW" required> I confirm this exact local proposal or independent decision.</label><button class="df-button" type="submit">'.esc_html($label).'</button></fieldset></form>';
        }
        echo '<pre role="status" aria-live="polite" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre></details>';
    }
}
