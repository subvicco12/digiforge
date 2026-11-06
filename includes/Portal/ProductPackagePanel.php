<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Listings\Validator;
use DigiForge\POD\ProductPackageRepository;
use DigiForge\Research\DesignFactoryRepository;
use WP_Error;

/** Operator controls over existing held services. Uses the existing nonce-protected portal action. */
final class ProductPackagePanel
{
    public function render(string $shop, string $workflow, string $design, ?array $supplierTemplate=null): void
    {
        if (!$this->access($shop)) return;
        $handoff=$this->context($shop,$workflow,$design);
        if ($handoff instanceof WP_Error) { $this->message($handoff); return; }
        $repo=new ProductPackageRepository();$current=null;
        $hash=$_GET['df_package_version']??null;
        if (is_string($hash) && $hash!=='') {
            $current=$this->version($shop,$hash,$design);
            if ($current instanceof WP_Error) {$this->message($current);$current=null;}
        }
        echo '<section class="df-panel" id="df-product-package"><h3>Held product package</h3><p>Prepare and review a local Printify/Etsy package. No transfer, publication or order is available here. STOP ALL, external lock, Manual orders and RELEASE HOLD remain in force. Import declarations are unverified until reviewed.</p>';
        $this->form('package_inspect',$workflow,$design);
        $this->input('package_hash','Open saved package version (SHA-256)','',true);
        echo '<button class="df-button">Inspect held package</button></form>';
        $e=$current['record']['evidence']??$this->template($handoff);
        if ($e instanceof WP_Error) {$this->message($e);echo '</section>';return;}
        if($current===null&&$supplierTemplate!==null)$e['template']=$supplierTemplate;
        echo '<details'.($current===null?' open':'').'><summary>Create or revise product evidence</summary>';
        $this->form('package_prepare',$workflow,$design);
        if ($current!==null) echo '<input type="hidden" name="parent_hash" value="'.esc_attr($current['hash']).'">';
        echo '<h4>Listing copy and manual price</h4><p>Save fields applies the edits below; import complete JSON uses its own listing and price fields. Both create a fresh unapproved version. Never paste credentials.</p>';
        $this->input('title','Etsy title (maximum 140 characters)',(string)($e['listing']['title']??''),true,140);
        $this->textarea('description','Accurate product description',(string)($e['listing']['description']??''),20000);
        $this->textarea('tags','Exactly 13 relevant tags, comma or newline separated',implode(', ',$e['listing']['tags']??[]),1000);
        $this->input('price','Proposed sale price; manual decision required',(string)($e['economics']['sale_price']??''),true,20);
        $this->input('currency','Estimate currency',(string)($e['economics']['currency']??'USD'),true,3);
        echo '<label>Review context <select name="review_environment">';
        foreach(['test','sandbox','production'] as $environment) echo '<option value="'.esc_attr($environment).'"'.(($e['environment']??'test')===$environment?' selected':'').'>'.esc_html($environment).'</option>';
        echo '</select></label><p>This context does not authorize external execution.</p>';
        $this->textarea('evidence_json','Provider, geometry, native fields, media, attributes, shipping and cost evidence JSON',Validator::canonicalJson($e),65536);
        echo '<p>Keep unknown facts null/empty. Import exact provider/template/source bindings, native text/photo limits and media checksums when available. Costs must include shipping, seller fees, ads, FX, refunds and tax assumptions. Unknown costs suppress modeled contribution.</p><button class="df-button" name="evidence_mode" value="edit">Save fields and evidence as new package</button> <button class="df-button" name="evidence_mode" value="import" formnovalidate>Import complete evidence JSON as new package</button></form></details>';
        if ($current!==null) {
            $p=$current['record']['package'];$decision=$current['decision'];
            echo '<h4>Exact package review</h4><p>Version <code>'.esc_html($current['hash']).'</code> · '.esc_html($decision['state']??'PENDING_PACKAGE_REVIEW').' · Actor '.esc_html((string)$current['record']['created_by']).' · '.esc_html($current['record']['created_at']).'</p>';
            echo '<p>Proposed sale price: '.esc_html($p['economics']['currency'].' '.$p['economics']['assumptions']['sale_price']).'. Conditional contribution estimate: '.esc_html($p['economics']['modeled_contribution']===null?'UNKNOWN':(string)$p['economics']['modeled_contribution']).'. This is not realized profit.</p>';
            foreach(['listing'=>'SEO and attributes','provider'=>'Exact provider/product and geometry','native_personalization'=>'Native buyer questions','media'=>'Mockup/media references','economics'=>'Conditional economics'] as $key=>$label) $this->readback($label,$p[$key]);
            $this->readback('Native text/photo and shipping declarations',$current['record']['specification']);
            $this->readback('Commercial source/specification and supplemental PNG evidence',$current['record']['commercial_design_metadata']);
            echo '<h4>Unresolved acceptance gates</h4><ul>';
            $gates=array_unique(array_merge($p['blockers'],$current['record']['specification']['blockers']));
            if(($decision['state']??null)==='HUMAN_APPROVED')$gates=array_diff($gates,['OWNER_PACKAGE_APPROVAL_REQUIRED']);
            foreach($gates as $gate) echo '<li>'.esc_html($gate).'</li>';
            echo '</ul><p>These are review snapshots, not supplier certification, realized profit or a purchasable listing. Package approval below does not close the listed release gates.</p>';
            if ($decision===null) {
                $this->form('package_decide',$workflow,$design,$current['hash']);
                echo '<label>Decision <select name="decision"><option value="REJECT">Reject package</option><option value="APPROVE">Approve exact held package</option></select></label>';
                $this->input('note','Human SEO, price, native, geometry and media review rationale','',true,2000);
                $this->check('I reviewed this exact package and its unresolved gates. This approves a held review artifact only.');
                echo '<button class="df-button">Record immutable package decision</button></form>';
            } elseif ($decision['state']==='HUMAN_APPROVED') {
                $this->form('package_export',$workflow,$design,$current['hash']);
                $this->check('Download the exact approved held JSON for separate owner/provider review. No live transfer is authorized.');
                echo '<button class="df-button">Download approved held listing package</button></form>';
            }
            $history=$repo->history($shop,$current['hash']);echo '<h4>Immutable package history</h4>';
            if ($history instanceof WP_Error) $this->message($history);
            else foreach($history as $item) echo '<p><a href="'.esc_url(add_query_arg(['df_view'=>'research','df_shop'=>$shop,
                'df_research_version'=>$workflow,'df_factory_version'=>$design,'df_package_version'=>$item['hash']],$this->baseUrl())).'#df-product-package">'.esc_html($item['hash'].' · '.$item['created_at']).'</a> · '.esc_html($item['decision']['state']??'PENDING_REVIEW').'</p>';
        }
        echo '</section>';
    }

    public function process(array $post): array|WP_Error
    {
        $shop=$post['shop_key']??null;
        if (!is_string($shop)||!$this->access($shop)) return $this->error('Authenticated personalized-POD reviewer required.');
        $workflow=$post['workflow_hash']??null;$design=$post['factory_hash']??null;
        if (!is_string($workflow)||!is_string($design)) return $this->error('Exact research and approved design versions required.');
        $handoff=$this->context($shop,$workflow,$design);if ($handoff instanceof WP_Error) return $handoff;
        $repo=new ProductPackageRepository();$op=$post['operation']??null;$result=null;
        try {
            if ($op==='package_prepare') {
                $raw=$post['evidence_json']??null;
                if (!is_string($raw)||strlen($raw)>65536) return $this->error('Product evidence JSON must fit 65536 bytes.');
                $e=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                if (!is_array($e)||array_is_list($e)) return $this->error('A complete evidence object is required.');
                $mode=$post['evidence_mode']??'edit';
                if (!in_array($mode,['edit','import'],true)) return $this->error('Choose fields or complete JSON import.');
                if ($mode==='edit') {
                    foreach(['title','description','tags','price','currency'] as $key) if (!is_string($post[$key]??null)) return $this->error('Listing copy, 13 tags and manual price required.');
                    if (!preg_match('/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/D',$post['price'])||(float)$post['price']<=0||(float)$post['price']>1000000) return $this->error('Finite positive price with at most two decimals required.');
                    if (!is_array($e['listing']??null)||!is_array($e['economics']??null)) return $this->error('Listing and economics evidence objects required.');
                    $e['listing']=array_replace($e['listing'],['title'=>$post['title'],'description'=>$post['description'],
                        'tags'=>array_values(array_filter(array_map('trim',preg_split('/[,\r\n]+/',$post['tags'])),static fn($v)=>$v!==''))]);
                    $e['economics']=array_replace($e['economics'],['sale_price'=>(float)$post['price'],'currency'=>strtoupper(trim($post['currency']))]);
                    if (isset($post['review_environment'])) $e['environment']=$post['review_environment'];
                }
                $parent=$post['parent_hash']??'';
                if (!is_string($parent)) return $this->error('Valid exact parent version required.');
                if ($parent!=='') {$v=$this->version($shop,$parent,$design);if ($v instanceof WP_Error)return $v;}
                $result=$repo->prepareCommercial($shop,$design,$e,$parent===''?null:$parent);
            } else {
                $hash=$post['package_hash']??null;if (!is_string($hash)) return $this->error('Exact saved package required.');
                $v=$this->version($shop,$hash,$design);if ($v instanceof WP_Error) return $v;
                if ($op==='package_inspect') $result=$v;
                elseif ($op==='package_decide'&&($post['confirm']??null)==='1'&&is_string($post['decision']??null)&&is_string($post['note']??null)) $result=$repo->decide($shop,$hash,$post['decision'],$post['note']);
                elseif ($op==='package_export'&&($post['confirm']??null)==='1') {
                    $json=$repo->export($shop,$hash);if ($json instanceof WP_Error)return $json;
                    return ['hash'=>$workflow,'design_factory_hash'=>$design,'package_hash'=>$hash,'package_export'=>$json];
                }
            }
        } catch (\Throwable) { return $this->error('Malformed or unavailable package evidence; inspect exact versions before retry.'); }
        if ($result instanceof WP_Error) return $result;
        if (!is_array($result)) return $this->error('Valid operation and explicit review/export confirmation required.');
        return ['hash'=>$workflow,'design_factory_hash'=>$design,'package_hash'=>$result['hash'],'package'=>$result];
    }

    private function context(string $shop,string $workflow,string $design): array|WP_Error
    {
        $repo=new DesignFactoryRepository();$v=$repo->read($shop,$design);if ($v instanceof WP_Error)return $v;
        if ($v['record']['commercial_hash']!==$workflow) return $this->error('Design belongs to a different selected concept.');
        return $repo->handoff($shop,$design);
    }
    private function version(string $shop,string $hash,string $design): array|WP_Error
    {
        $v=(new ProductPackageRepository())->read($shop,$hash);if ($v instanceof WP_Error)return $v;
        return ($v['record']['commercial_design_metadata']['design_hash']??null)===$design?$v:$this->error('Package belongs to a different approved design.');
    }
    private function template(array $handoff): array|WP_Error
    {
        $product=$handoff['design_metadata']['geometry']['draft_reference'];
        $id=['6ae98d61eb5d7e24b80ca0a6'=>'FL-03','6ae9913d9541969415088814'=>'FL-04'][$product]??null;
        if ($id===null) return $this->error('No matching supported commercial product profile.');
        return ['shop_key'=>'personalized_pod','environment'=>'test',
            'fixture'=>['fixture_id'=>$id,'printify_product_id'=>$product,'etsy_listing_id'=>null,'etsy_state'=>'NOT_TRANSFERRED'],
            'listing'=>['title'=>$handoff['artwork']['record']['input']['title'],'description'=>'','tags'=>[],'category'=>null,'attributes'=>[]],
            'template'=>null,'provider_binding'=>null,'native_fields'=>[],'media'=>[],'reviews'=>[],'parity'=>[],
            'specification'=>['native_fields'=>[],'shipping'=>['destination'=>'UNKNOWN','processing_min_days'=>null,
                'processing_max_days'=>null,'source_reference'=>null,'source_sha256'=>null]],
            'economics'=>['currency'=>'USD','sale_price'=>null,'base_cost'=>null,'shipping_cost'=>null,'seller_fees'=>null,
                'ads_cost'=>null,'fx_cost'=>null,'refund_reserve'=>null,'tax_cost'=>null]];
    }
    private function form(string $operation,string $workflow,string $design,?string $package=null): void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="digiforge_commercial_research"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="shop_key" value="personalized_pod"><input type="hidden" name="workflow_hash" value="'.esc_attr($workflow).'"><input type="hidden" name="factory_hash" value="'.esc_attr($design).'"><input type="hidden" name="return_url" value="'.esc_attr($this->baseUrl()).'">';
        if ($package!==null) echo '<input type="hidden" name="package_hash" value="'.esc_attr($package).'">';
        wp_nonce_field('digiforge_commercial_research');
    }
    private function input(string $name,string $label,string $value,bool $required,int $maximum=64): void
    { echo '<p><label>'.esc_html($label).' <input name="'.esc_attr($name).'" value="'.esc_attr($value).'" maxlength="'.esc_attr((string)$maximum).'"'.($required?' required':'').'></label></p>'; }
    private function textarea(string $name,string $label,string $value,int $maximum): void
    { echo '<p><label>'.esc_html($label).'<textarea name="'.esc_attr($name).'" rows="8" maxlength="'.esc_attr((string)$maximum).'" required>'.esc_textarea($value).'</textarea></label></p>'; }
    private function readback(string $label,mixed $data): void
    { echo '<details><summary>'.esc_html($label).'</summary><pre>'.esc_html((string)wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</pre></details>'; }
    private function check(string $label): void
    { echo '<p><label><input type="checkbox" name="confirm" value="1" required> '.esc_html($label).'</label></p>'; }
    private function message(WP_Error $e): void { echo '<p>'.esc_html($e->get_error_message()).'</p>'; }
    private function access(string $shop): bool { return $shop==='personalized_pod'&&get_current_user_id()>0&&current_user_can('manage_digiforge_pod')&&current_user_can('manage_digiforge_research'); }
    private function baseUrl(): string { $id=get_queried_object_id();$url=$id>0?get_permalink($id):home_url('/');return is_string($url)?$url:home_url('/'); }
    private function error(string $message): WP_Error { return new WP_Error('product_package_portal_denied',$message,['status'=>409,'release_hold'=>true,'external_execution_authorized'=>false]); }
}
