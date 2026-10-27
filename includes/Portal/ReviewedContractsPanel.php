<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Security\ShopAccess;
use DigiForge\Security\ShopAccessPolicy;
use DigiForge\Core\Config;

/** Selected-shop local contract editor; every request is independently authorized server-side. */
final class ReviewedContractsPanel
{
    public static function render(string $shop): void
    {
        if (!ShopAccessPolicy::validShop($shop)) { echo '<section class="df-panel"><h2>Reviewed local contracts</h2><p>Select one explicitly assigned shop to inspect or propose a contract.</p></section>'; return; }
        if (is_wp_error(ShopAccess::authorize($shop,'manage_digiforge'))) { return; }
        wp_enqueue_script('digiforge-reviewed-contracts',DIGIFORGE_URL.'assets/reviewed-contracts.js',[],DIGIFORGE_VERSION.'.'.substr(hash_file('sha256',DIGIFORGE_PATH.'assets/reviewed-contracts.js'),0,12),true);
        echo '<section class="df-panel" data-reviewed-contracts data-endpoint="'.esc_url(rest_url('digiforge/v1/reviewed-contracts/'.$shop.'/')).'" data-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'"><h2>Reviewed local contracts</h2><p>Selected shop: <strong>'.esc_html($shop).'</strong>. Local restrictions and assertions only. Separate authorized review is mandatory. STOP ALL, external lock, parent limits and existing budget/quantity gates prevail. No provider activity or certification is authorized.</p>';
        foreach (['policy','ownership'] as $kind) {
            $id='df-contract-'.$kind;
            echo '<details><summary>'.($kind==='policy'?'Shop / workflow policy':'Provider ownership binding').'</summary><form data-contract-kind="'.esc_attr($kind).'" class="df-review-form"><fieldset><legend>Propose a new local version</legend>';
            if ($kind==='policy') {
                self::select($id,'environment','Environment',['sandbox','production']);
                self::select($id,'scope','Policy scope',['SHOP','WORKFLOW']);
                self::select($id,'workflow','Workflow (empty for shop policy)',['','research','product_factory']);
                self::select($id,'capability','Existing feature',array_values(array_diff(Config::SWITCHES,['stop_all','gst_automation'])));
                self::select($id,'enabled','Narrower restriction',['false','true']);
            } else {
                foreach (['integration_id'=>'Integration record ID','provider_mapping_id'=>'Provider mapping record ID','production_template_id'=>'Production template record ID'] as $name=>$label) { self::input($id,$name,$label,'number'); }
                self::input($id,'source_sha256','Local source SHA-256');
                self::input($id,'evidence_reference','Local evidence reference (local://)');
            }
            self::input($id,'effective_at','Effective date/time (your browser timezone)','datetime-local');
            self::input($id,'expires_at','Expiry date/time (your browser timezone)','datetime-local');
            self::input($id,'expected_revision','Current revision (0 for first proposal)','number','0');
            echo '<button class="df-button" type="submit">Propose local version</button></fieldset></form>';
            echo '<form data-contract-read="'.esc_attr($kind).'" class="df-review-form"><label for="'.esc_attr($id.'-key').'">Exact contract key</label><input id="'.esc_attr($id.'-key').'" name="key" required maxlength="240"><button class="df-button" type="submit">Load current evidence</button></form><div data-contract-result="'.esc_attr($kind).'" role="status" aria-live="polite"></div><div data-contract-decisions="'.esc_attr($kind).'"></div></details>';
        }
        if(in_array($shop,['digital','personalized_pod'],true)) {
            AcceptanceReviewPanel::render($shop);
            wp_enqueue_script('digiforge-acceptance-status',DIGIFORGE_URL.'assets/acceptance-contract-status.js',[],DIGIFORGE_VERSION.'.'.substr(hash_file('sha256',DIGIFORGE_PATH.'assets/acceptance-contract-status.js'),0,12),true);
            echo '<details data-acceptance-status><summary>Reviewed acceptance status (read only)</summary><p>UNKNOWN measurements and UNVERIFIED evidence remain blocked. A reviewed engineering contract is not supplier certification, product approval or production authority. Resolved local identity does not verify the purpose or contents of evidence.</p><form><label for="df-acceptance-criterion">Criterion</label><select id="df-acceptance-criterion" name="criterion">';
            foreach(ReviewedAcceptanceContract::criteria() as $criterion) {
                if($shop==='digital' && in_array($criterion,['BP-seam-panel-render','BP-template-qa-spelling'],true))continue;
                echo '<option value="'.esc_attr($criterion).'">'.esc_html($criterion).'</option>';
            }
            echo '</select><label for="df-acceptance-scope">Exact reviewed scope</label><input id="df-acceptance-scope" name="scope" required maxlength="96" pattern="[A-Za-z0-9_:.-]+"><label for="df-acceptance-environment">Evidence environment (not activation)</label><select id="df-acceptance-environment" name="environment" required><option value="">Select evidence environment</option><option value="sandbox">sandbox</option><option value="production">production</option></select><button type="submit">Refresh read-only status</button></form><pre role="status" aria-live="polite" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre></details>';
        }
        echo '<noscript><p>JavaScript is required for this local REST editor; existing server authorization applies to every request.</p></noscript></section>';
    }
    private static function select(string $id,string $name,string $label,array $values): void
    {
        $field=$id.'-'.$name;echo '<label for="'.esc_attr($field).'">'.esc_html($label).'</label><select id="'.esc_attr($field).'" name="'.esc_attr($name).'">';
        foreach ($values as $value) { echo '<option value="'.esc_attr($value).'">'.esc_html($value===''?'None':$value).'</option>'; }
        echo '</select>';
    }
    private static function input(string $id,string $name,string $label,string $type='text',string $value=''): void
    {
        $field=$id.'-'.$name;echo '<label for="'.esc_attr($field).'">'.esc_html($label).'</label><input id="'.esc_attr($field).'" name="'.esc_attr($name).'" type="'.esc_attr($type).'" value="'.esc_attr($value).'" required'.($type==='number'?' min="0" step="1"':'').'>';
    }
}
