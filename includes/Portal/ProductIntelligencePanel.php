<?php

declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\POD\ProductIntelligence;
use DigiForge\POD\ProductIntelligenceRepository;

/** Existing private Shop 2 portal extension. No provider transport or activation. */
final class ProductIntelligencePanel
{
    public static function canRead(): bool
    {
        if(!is_user_logged_in() || !current_user_can('manage_digiforge') || !current_user_can('manage_digiforge_pod'))return false;
        return \DigiForge\POD\PrintifyResearchDependencies::assigned(['manage_digiforge','manage_digiforge_pod']);
    }
    public static function canImport(): bool
    {
        return self::canRead() && current_user_can('manage_digiforge_research')
            && \DigiForge\POD\PrintifyResearchDependencies::assigned(['manage_digiforge_research']);
    }
    public function register(): void
    {
        (new \DigiForge\REST\PrintifyResearchController())->register();
        (new PrintifyResearchControls())->register();
        add_action('admin_post_digiforge_pod_intelligence_import',[$this,'import']);
        add_action('admin_post_digiforge_pod_intelligence_export',[$this,'export']);
    }
    public function import(): void
    {
        if (!self::canImport()) wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('digiforge_pod_intelligence_import');
        if (($_POST['shop_key']??'')!=='personalized_pod') wp_die('Shop 2 scope required.', '', ['response'=>400]);
        $file=$_FILES['research_packet']??[];
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name'])>ProductIntelligence::MAX_BYTES) wp_die('Invalid research packet upload.', '', ['response'=>400]);
        $raw=file_get_contents($file['tmp_name']);
        try {$packet=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($packet))throw new \InvalidArgumentException();}
        catch (\Throwable) {wp_die('Invalid JSON research packet.', '', ['response'=>400]);}
        $saved=(new ProductIntelligenceRepository())->import($packet);
        if (is_wp_error($saved))wp_die(esc_html($saved->get_error_message()), '', ['response'=>400]);
        $return=wp_get_referer()?:home_url('/');
        wp_safe_redirect(add_query_arg(['df_view'=>'pod_personalized','df_shop'=>'personalized_pod','df_pi_snapshot'=>$saved['snapshot_id']],$return));exit;
    }
    public function export(): void
    {
        if (!self::canRead())wp_die('Forbidden', '', ['response'=>403]);
        check_admin_referer('digiforge_pod_intelligence_export');
        if (($_GET['shop_key']??'')!=='personalized_pod')wp_die('Shop 2 scope required.', '', ['response'=>400]);
        $id=sanitize_text_field(wp_unslash((string)($_GET['snapshot']??'')));$section=sanitize_key((string)($_GET['section']??''));
        $packet=(new ProductIntelligenceRepository())->read($id);
        if (is_wp_error($packet)||!in_array($section,ProductIntelligence::SECTIONS,true)||!isset($packet['sections'][$section]))wp_die('Research export unavailable.', '', ['response'=>503]);
        $format=$_GET['format']??'csv';
        if($format==='json'){nocache_headers();header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="shop2-research-'.substr($id,0,12).'.json"');echo json_encode($packet,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
        if($format!=='csv')wp_die('Unsupported export format.', '', ['response'=>400]);
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="shop2-'.$section.'-'.substr($id,0,12).'.csv"');
        $binding=$packet['sections'][$section];$source=null;
        foreach($packet['sources'] as $candidate)if($candidate['id']===$binding['source_id'])$source=$candidate;
        echo ProductIntelligence::csv(ProductIntelligence::exportRows($packet,$section,$id));exit;
    }
    public function render(string $baseUrl): void
    {
        if (!self::canRead())return;
        echo '<section class="df-panel df-product-intelligence" id="df-product-intelligence"><div class="df-panel-head"><div><h2>Shop 2 · Printify Product Intelligence</h2><p>DigiCraftifyGoods · source-backed research snapshots</p></div><span class="df-status">UNREVIEWED · NO EXECUTION</span></div>';
        if (isset($_GET['df_shop']) && $_GET['df_shop']!=='personalized_pod') {echo '<p>Select Personalized POD to view Shop 2 research.</p></section>';return;}
        $pipeline=\DigiForge\POD\PrintifyResearchGateway::readiness();
        echo '<details><summary>WordPress catalog API readiness</summary><p>'.esc_html($pipeline['pipeline_state']).'</p><p>Existing grant ceiling: 10 requests. Batches advance one explicitly authorized provider-discovery step at a time. No scheduler or automatic retries. Readiness is not execution authority.</p>';
        if($pipeline['missing_dependencies']!==[])echo '<p>Required governed dependencies: '.esc_html(implode(', ',$pipeline['missing_dependencies'])).'. Collection fails closed until the existing pipeline is integrated.</p>';
        echo '<p>REST admission: '.(empty($pipeline['shared_global_guards_present'])?'OWN SCOPED HANDLER':(!empty($pipeline['rest_admission_mapped'])?'MAPPED':'OWNER MAPPING REQUIRED')).' · Fixed Shop 2 target: '.(!empty($pipeline['fixed_shop_target_mapped'])?'MAPPED':'OWNER MAPPING REQUIRED').' · Portal form admission: '.(empty($pipeline['shared_admin_guard_present'])?'OWN SCOPED HANDLER':(!empty($pipeline['admin_action_scope_verified'])?'VERIFIED CONTRACT':'OWNER MAPPING REQUIRED')).'</p>';
        echo '<p>Size-guide grant scope: '.($pipeline['size_guide_grant_scope_supported']?'SUPPORTED':'NOT AVAILABLE — retained source import remains supported').'</p></details>';
        echo '<p>Costs, shipping and geometry remain source evidence. Missing values are unknown; pixel placeholders do not certify physical templates. Finance, publishing and production are not activated.</p>';
        if(isset($_GET['df_pi_batch'])&&\DigiForge\POD\PrintifyResearchBatch::allowed()){
            $batch=(new \DigiForge\POD\PrintifyResearchBatch())->status(sanitize_text_field(wp_unslash((string)$_GET['df_pi_batch'])));
            echo '<details open><summary>Provider-discovery batch status</summary>';
            if(is_wp_error($batch))echo '<p>'.esc_html($batch->get_error_message()).'</p>';
            else foreach($batch['items'] as $item)echo '<p>Blueprint '.esc_html((string)$item['blueprint_id']).' · '.esc_html($item['state']).'</p>';
            echo '</details>';
        }
        $repo=new ProductIntelligenceRepository();$recent=$repo->recent();
        if(is_wp_error($recent)){echo '<div class="df-notice df-notice-error">'.esc_html($recent->get_error_message()).'</div></section>';return;}
        $id=isset($_GET['df_pi_snapshot'])?sanitize_text_field(wp_unslash((string)$_GET['df_pi_snapshot'])):($recent['items'][0]['snapshot_id']??'');
        if ($id==='')echo '<div class="df-empty">No local research snapshot imported. Use the offline converter on retained Drive sources; upload its JSON packet below.</div>';
        else {
            $packet=$repo->read($id);
            if(is_wp_error($packet)){echo '<div class="df-notice df-notice-error">'.esc_html($packet->get_error_message()).'</div></section>';return;}
            $section=sanitize_key((string)($_GET['df_pi_section']??'concepts'));if(!isset($packet['sections'][$section]))$section='concepts';
            $query=sanitize_text_field(wp_unslash((string)($_GET['df_pi_query']??'')));$page=ProductIntelligence::page($packet,$section,$query,(int)($_GET['df_pi_page']??1));
            echo '<form method="get" action="'.esc_url($baseUrl).'" class="df-pi-filters"><input type="hidden" name="df_view" value="pod_personalized"><input type="hidden" name="df_shop" value="personalized_pod"><label>Snapshot<select name="df_pi_snapshot">';
            $versions=$recent['items'];if(!in_array($id,array_column($versions,'snapshot_id'),true))array_unshift($versions,['snapshot_id'=>$id]);
            foreach($versions as $version){$v=$version['snapshot_id'];echo '<option value="'.esc_attr($v).'" '.selected($id,$v,false).'>'.esc_html(substr($v,0,16)).'</option>';}
            echo '</select></label><label>Evidence section<select name="df_pi_section">';
            foreach($packet['sections'] as $key=>$value)echo '<option value="'.esc_attr($key).'" '.selected($section,$key,false).'>'.esc_html(ucwords(str_replace('_',' ',$key))).' ('.count($value['rows']).')</option>';
            echo '</select></label><label>Search evidence<input name="df_pi_query" maxlength="120" value="'.esc_attr($query).'"></label><button class="df-button" type="submit">Filter</button></form>';
            if($recent['more_versions'])echo '<p class="df-muted">Latest 10 versions shown. Older snapshots remain accessible by their exact ID.</p>';
            echo '<details><summary>Provenance and uncertainty</summary><p>Snapshot SHA-256: <code>'.esc_html($id).'</code>. Content integrity verified; provider authenticity unverified. All records remain UNREVIEWED. Partial concept snapshots are not the full Master 500.</p>';
            foreach($packet['sources'] as $source)echo '<p>'.esc_html($source['name']).' · Source ID: '.esc_html($source['id']).' · Modified: '.esc_html($source['modified_at']).'<br><code>'.esc_html($source['sha256']).'</code></p>';
            echo '<p>Section source: '.esc_html($packet['sections'][$section]['source_id']).' · Worksheet/report: '.esc_html($packet['sections'][$section]['sheet']).'</p></details>';
            $url=wp_nonce_url(add_query_arg(['action'=>'digiforge_pod_intelligence_export','shop_key'=>'personalized_pod','snapshot'=>$id,'section'=>$section],admin_url('admin-post.php')),'digiforge_pod_intelligence_export');
            $jsonUrl=add_query_arg('format','json',$url);
            (new PrintifyResearchControls())->render($id,$pipeline);
            echo '<p><a class="df-button" href="'.esc_url($url).'">Export section CSV</a> <a class="df-button" href="'.esc_url($jsonUrl).'">Export research JSON</a> · XLSX export available with the offline converter.</p><p>'.esc_html((string)$page['total']).' matching rows · Page '.(int)$page['page'].' / '.(int)$page['pages'].'</p><div class="df-pi-cards">';
            foreach($page['rows'] as $row){echo '<article class="df-pi-card"><dl>';foreach($row as $key=>$value)echo '<div><dt>'.esc_html($key).'</dt><dd>'.($value===null?'<span class="df-muted">UNKNOWN / MISSING</span>':esc_html($value)).'</dd></div>';echo '</dl></article>';}
            echo '</div><nav aria-label="Research evidence pages" class="df-actions">';
            foreach(['Previous'=>-1,'Next'=>1] as $label=>$offset){$n=$page['page']+$offset;if($n<1||$n>$page['pages'])continue;$link=add_query_arg(['df_view'=>'pod_personalized','df_shop'=>'personalized_pod','df_pi_snapshot'=>$id,'df_pi_section'=>$section,'df_pi_query'=>$query,'df_pi_page'=>$n],$baseUrl);echo '<a class="df-button" href="'.esc_url($link).'#df-product-intelligence">'.esc_html($label).'</a>';}
            echo '</nav><details><summary>Controlled research batches</summary><p>Local plans confer no execution authority. Provider-discovery batches advance at most one endpoint per explicit submission, using existing grants limited to ten requests. Existing authenticated WordPress catalog-read authorization, STOP ALL, external lock, grant ceilings, idempotency and audit controls remain required under separate approval.</p></details>';
        }
        if(self::canImport()){
            echo '<details><summary>Import a versioned research packet</summary><p>Local JSON import only. Original Drive files are never changed. No catalog promotion or supplier approval.</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="digiforge_pod_intelligence_import"><input type="hidden" name="shop_key" value="personalized_pod">';wp_nonce_field('digiforge_pod_intelligence_import');echo '<label>Research JSON (maximum 16 MiB)<input type="file" name="research_packet" accept="application/json,.json" required></label><button type="submit" class="df-button">Import immutable local snapshot</button></form></details>';
        }
        echo '</section>';
    }
}
