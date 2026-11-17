<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Research\DesignFactoryRepository;
use DigiForge\Research\DesignFactoryContract;
use DigiForge\Research\CommercialResearchRepository;
use DigiForge\Research\CommercialResearchContract;
use WP_Error;

/** Uses the already registered CommercialResearchPanel action; no new route or registration. */
final class DesignFactoryPanel
{
    public function __construct(private ?\DigiForge\AI\ImageGenerationExecution $imageExecution=null){}
    public function render(string $shop,string $commercialHash,?array $supplierTemplate=null,bool $manualOnly=false): void
    {
        $commercial=(new CommercialResearchRepository())->read($shop,$commercialHash);if($commercial instanceof WP_Error||$commercial['record']['stage']!=='DESIGN_SELECTED')return;
        wp_enqueue_style('digiforge-design-controls',DIGIFORGE_URL.'assets/design-factory-controls.css',[],DIGIFORGE_VERSION);
        wp_enqueue_script('digiforge-design-controls',DIGIFORGE_URL.'assets/design-factory-controls.js',[],DIGIFORGE_VERSION,true);
        if(did_action('wp_head'))wp_print_styles('digiforge-design-controls');
        echo '<section class="df-panel" id="df-design-factory"><h3>Approved concept → design specification → original artwork</h3><p>Separate source approval is required. A selected direction or generated prompt is not approved artwork. All specifications and safe-region rectangles are operator instructions; supplier bleed/safe margins remain unknown. STOP ALL and RELEASE HOLD remain.</p>';
        $this->form('design_approve_concept',$commercialHash);foreach(['sources_reviewed'=>'I reviewed the linked source evidence and limitations','buyer_intent_reviewed'=>'I reviewed the buyer intent','gifting_occasion_reviewed'=>'I reviewed the gifting occasion']as $key=>$label)$this->check($key,$label);$this->field('note','Source-linked concept approval rationale');echo '<button class="df-button">Approve source-linked concept</button></form>';
        $saved=(new DesignFactoryRepository())->recent($shop,$commercialHash);
        echo '<details><summary>Saved design versions (latest 50 records)</summary>';
        if(is_wp_error($saved))echo '<p>'.esc_html($saved->get_error_message()).'</p>';
        else foreach($saved as $item)echo '<p><a href="'.esc_url(add_query_arg(['df_view'=>'research','df_shop'=>$shop,'df_research_version'=>$commercialHash,'df_factory_version'=>$item['hash']],$this->baseUrl())).'#df-design-factory">'.esc_html($item['kind'].' · '.$item['review_state'].' · '.$item['created_at']).'</a></p>';
        echo '</details>';
        $this->form('design_inspect',$commercialHash);$this->field('factory_hash','Open exact saved design version');echo '<button class="df-button">Inspect design version</button></form>';
        $hash=$_GET['df_factory_version']??null;if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){echo '</section>';return;}
        $repo=new DesignFactoryRepository();$v=$repo->read($shop,$hash);if($v instanceof WP_Error){echo '<p>'.esc_html($v->get_error_message()).'</p></section>';return;}
        $r=$v['record'];if($r['commercial_hash']!==$commercialHash){echo '<p>Design belongs to a different selected concept.</p></section>';return;}
        echo '<p>Design <code>'.esc_html($hash).'</code> · '.esc_html($r['kind']).' · Human '.esc_html((string)$r['created_by']).' · '.esc_html($r['created_at']).'</p>';
        if($r['kind']==='CONCEPT_APPROVED')$this->specificationForm($commercialHash,$hash,$r['context']['research']['product_family']);
        if(is_array($r['specification'])){
            $spec=$r['specification'];echo '<h4>Detailed production-adaptation specification</h4><textarea readonly rows="12">'.esc_textarea(CommercialResearchContract::json($spec)).'</textarea><textarea readonly rows="8">'.esc_textarea(DesignFactoryContract::prompt($spec)).'</textarea><p>This is NOT finished artwork. Native text/photos stay separate. Technical canvas: '.esc_html((string)$spec['geometry']['width_px'].'×'.$spec['geometry']['height_px']).' at 300 DPI. Supplier margins, SKU/variant and print output are not certified.</p>';
        }
        if($r['kind']==='SPEC_READY'){
            $status=$repo->providerStatus();echo '<p>Image provider: '.esc_html($status['reason']??'UNVERIFIED').'. Original editable artwork import is available; no paid API dependency is added.</p>';
            if(!$manualOnly&&class_exists(ImageGenerationPanel::class))(new ImageGenerationPanel($this->imageExecution))->render($shop,$commercialHash,$hash);
            if(!$manualOnly&&!class_exists(ImageGenerationPanel::class)){$this->form('design_generate',$commercialHash,$hash);$this->check('confirm','Request one authorized design-provider attempt; STOP ALL still blocks dispatch');echo '<button class="df-button"'.(($status['available']??false)?'':' disabled').'>Request provider artwork</button></form>';}
            $this->form('design_import',$commercialHash,$hash);echo '<h4>Import actual original artwork</h4><label>Editable SVG file (maximum 60000 bytes, exact width/height/viewBox)<input type="file" name="artwork_svg" accept=".svg,image/svg+xml" required></label><label>Optional supplemental PNG files (up to three, maximum 2 MiB each)<input type="file" name="artwork_pngs[]" accept=".png,image/png" multiple></label><p>Complete non-interlaced 8-bit PNGs require exact canvas dimensions and embedded 300-DPI density. PNGs remain raster assets and are not embedded into the vector-only listing handoff.</p><p data-df-upload-status role="status" aria-live="polite"></p>';$this->field('rights_note','Original artwork / font licensing evidence');$this->field('source_note','Creation/import provenance and tool reference');echo '<button class="df-button">Import original editable artwork</button></form>';
        }
        if(is_array($r['asset'])){
            if(($r['provenance']['method']??null)==='GENERATED_RASTER_EDITABLE_DERIVATIVE'){
                $origin=$r['provenance']['generated_source'];$png=$repo->generatedRaster($shop,$hash);
                echo '<details open><summary>Generated source and editable adaptation review</summary><p>'.esc_html($origin['mode'].' · '.$origin['provider'].' / '.$origin['model']).'. Raster source is not editable vector. The imported SVG is a human-declared adaptation; visual fidelity, rights and native/physical output are separate review gates.</p><p>Request '.esc_html($origin['request_sha256']).' · source PNG '.esc_html($origin['raster_sha256']).'</p>';
                if(isset($origin['artistic_binding'])){$b=$origin['artistic_binding'];$input=$repo->generatedInput($shop,$hash);echo '<p>'.esc_html($b['engine'].' · original product version '.$b['product_version_id'].' · input '.$b['input_sha256']).'. Original source/reference rights do not approve this generated output. Target geometry is an operator adaptation, not supplier qualification.</p>';if($input instanceof WP_Error)echo '<p role="alert">Original artistic input is unavailable. Review is held.</p>';else echo '<img alt="Exact original artistic input for independent identity review" style="max-width:100%;width:600px;height:auto" src="data:image/png;base64,'.esc_attr($input['base64']).'">';}
                if($png instanceof WP_Error)echo '<p role="alert">Generated source is unavailable. Review is held.</p>';
                else echo '<img alt="Exact generated raster source for independent human adaptation review" style="max-width:100%;width:600px;height:auto" src="data:image/png;base64,'.esc_attr($png['base64']).'">';
                echo '<p>Approval requires confirmed adoption of this exact derivative. Interrupted adoption must be inspected/recovered; no checkbox overrides source admission.</p></details>';
            }

            echo '<h4>Fixed artwork preview</h4><p>Native buyer text/photos are not applied in this preview. This is not proof of supplier print-output parity.</p><img alt="Approved-source fixed artwork preview" style="max-width:100%;width:600px;height:auto" src="data:image/svg+xml;base64,'.esc_attr(base64_encode($r['asset']['svg'])).'">';
            foreach($r['asset']['raster_references']as $ref){$png=$repo->raster($shop,$hash,$ref['reference']);
                if($png instanceof WP_Error)echo '<p>'.esc_html($png->get_error_message()).'</p>';
                else echo '<details><summary>Supplemental PNG preview — composition/print parity unverified</summary><img alt="Supplemental raster preview" style="max-width:100%;width:600px;height:auto" src="data:image/png;base64,'.esc_attr($png['base64']).'"></details>';
            }
            echo '<h4>Actual editable source and validated asset references</h4><textarea readonly rows="7">'.esc_textarea($r['asset']['svg']).'</textarea><p>SVG SHA-256 '.esc_html($r['asset']['svg_sha256']).'</p><p>'.esc_html($r['asset']['rights_note']).'</p>';
            foreach($r['asset']['raster_references']as $ref)echo '<p>PNG reference <code>'.esc_html($ref['reference']).'</code> · '.esc_html((string)$ref['byte_count']).' bytes · raster, not editable SVG · supplemental only.</p>';
            echo '<p>Review state: '.esc_html($v['review_state']??'PENDING_HUMAN_REVIEW').'</p>';
            if($r['kind']==='ASSET_READY'&&$v['review_state']===null){$this->form('design_review',$commercialHash,$hash);echo '<h4>Human commercial quality review (no AI quality score)</h4>';foreach(DesignFactoryContract::REVIEW_CRITERIA as $criterion)$this->check($criterion,str_replace('_',' ',$criterion),false);echo '<p>All criteria must be confirmed for APPROVE. Leave failed criteria unchecked for REJECT. Geometry review is against the recorded technical baseline, not supplier-certified margins.</p><select name="decision"><option value="REJECT">Reject artwork</option><option value="APPROVE">Approve exact artwork</option></select>';$this->field('note','Human quality/originality/licensing decision rationale');$this->check('confirm','I reviewed the actual source bytes and all criteria for this exact immutable version');echo '<button class="df-button">Record immutable artwork decision</button></form>';}
            if(in_array($v['review_state'],['APPROVAL_UNCONFIRMED','REVIEW_INTENT_UNCONFIRMED'],true))echo '<p>Review intent is preserved; approval is unconfirmed. Inspect the exact records before retry. No package handoff is allowed.</p>';
            if($v['review_state']==='HUMAN_APPROVED')echo '<p>Confirmed Day1 artwork reference <code>'.esc_html($v['artwork']['version_hash']).'</code>. Supplier/package validation remains a separate gate; supplemental PNGs require an explicitly integrated asset path.</p>';
            if($v['review_state']==='HUMAN_APPROVED')(new ArtworkPersonalizationPanel())->render($shop,$commercialHash,$hash);
            if($v['review_state']==='HUMAN_APPROVED')(new ProductPackagePanel())->render($shop,$commercialHash,$hash,$supplierTemplate);
        }
        if(is_array($r['specification'])){$this->form('design_revise',$commercialHash,$hash);$this->field('note','Artwork/specification revision reason');$this->check('revise_layout','Change the recorded layout in this new revision',false);echo '<details><summary>Revised layout fields</summary>';$this->layoutFields($r['specification']['layout']);echo '</details><button class="df-button">Create immutable design revision</button></form>';}
        $history=$repo->history($shop,$hash);echo '<h4>Immutable design history</h4>';if($history instanceof WP_Error)echo '<p>'.esc_html($history->get_error_message()).'</p>';else foreach($history as $item)echo '<p><a href="'.esc_url(add_query_arg(['df_view'=>'research','df_shop'=>$shop,'df_research_version'=>$commercialHash,'df_factory_version'=>$item['hash']],$this->baseUrl())).'#df-design-factory">'.esc_html($item['kind'].' · '.$item['created_at']).'</a> · '.esc_html($item['note']).'</p>';
        echo '</section>';
    }
    private function specificationForm(string $commercial,string $approval,string $family): void
    {
        $this->form('design_prepare',$commercial,$approval);echo '<h4>Detailed design specification</h4><label>Recorded technical profile <select name="profile">';foreach(DesignFactoryContract::PROFILES as $key=>$p)if($p['family']===$family)echo '<option value="'.esc_attr($key).'">'.esc_html($key.' — '.$p['width_px'].'×'.$p['height_px'].' @300dpi').'</option>';echo '</select></label>';
        $this->layoutFields();echo '<button class="df-button">Save approved-concept design specification</button></form>';
    }
    private function layoutFields(?array $layout=null): void
    {
        foreach(['layout_notes'=>'Layout/composition instructions','typography'=>'Typography hierarchy, native text and spacing','font_license_reference'=>'Font/licensing review reference','palette'=>'Palette hex colors, comma separated','asset_requirements'=>'Original asset requirements, one per line']as $key=>$label){
            $value=$layout[$key]??'';if(is_array($value))$value=implode($key==='palette'?', ':"\n",$value);
            echo '<p><label>'.esc_html($label).'<textarea name="'.esc_attr($key).'" rows="3" maxlength="'.($key==='asset_requirements'?'10000':'2000').'"'.($layout===null?' required':'').'>'.esc_textarea($value).'</textarea></label></p>';
        }
        $regions=$layout['native_regions']??[['name'=>'','kind'=>'text','x'=>'','y'=>'','width'=>'','height'=>'']];
        echo '<fieldset class="df-native-regions" data-df-regions><legend>Native personalization reservation regions</legend><p>Enter 1–10 named text/photo reservations in pixels. Rectangles must fit the recorded canvas and not overlap. These are operator layout instructions; supplier safe margins remain unknown.</p><div data-df-region-rows>';
        foreach($regions as $i=>$region)$this->regionRow($i,$region,$layout===null);
        echo '</div><button type="button" class="df-button" data-df-region-add>Add reservation region</button><p data-df-region-status role="status" aria-live="polite"></p><template data-df-region-template>';
        $this->regionRow('__INDEX__',['name'=>'','kind'=>'text','x'=>'','y'=>'','width'=>'','height'=>''],false);
        echo '</template></fieldset>';
    }
    private function regionRow(int|string $index,array $region,bool $required): void
    {
        echo '<fieldset class="df-region-row" data-df-region-row><legend>Reservation region <span data-df-region-number>'.esc_html(is_int($index)?(string)($index+1):'').'</span></legend>';
        foreach(['name'=>'Region name (letters, numbers, underscore)','kind'=>'Region kind','x'=>'Left (x px)','y'=>'Top (y px)','width'=>'Width (px)','height'=>'Height (px)']as $key=>$label){
            $name='native_regions['.$index.']['.$key.']';echo '<label>'.esc_html($label);
            if($key==='kind')echo '<select name="'.esc_attr($name).'"><option value="text"'.selected($region[$key],'text',false).'>Text</option><option value="image"'.selected($region[$key],'image',false).'>Photo / image</option></select>';
            else echo '<input name="'.esc_attr($name).'" value="'.esc_attr((string)$region[$key]).'"'.($key==='name'?' type="text" maxlength="32" pattern="[a-z][a-z0-9_]{0,31}"':' type="number" inputmode="numeric" step="1" min="'.(in_array($key,['x','y'],true)?'0':'1').'" max="10000"').($required?' required':'').'>';
            echo '</label>';
        }
        echo '<button type="button" class="df-button" data-df-region-remove>Remove region</button></fieldset>';
    }

    public function process(array $post): array|WP_Error
    {
        if(!\DigiForge\Security\PersonalizedPodAccess::allows()||($post['shop_key']??null)!=='personalized_pod'||!is_string($post['workflow_hash']??null)||get_current_user_id()<1||!current_user_can('manage_digiforge_pod')||!current_user_can('manage_digiforge_research'))return new WP_Error('design_factory_scope','Authenticated exact-shop selected concept required.');
        $commercial=$post['workflow_hash'];$repo=new DesignFactoryRepository();$op=$post['operation']??null;$result=null;
        if($op==='design_approve_concept')$result=$repo->approveConcept('personalized_pod',$commercial,['sources_reviewed'=>($post['sources_reviewed']??null)==='1','buyer_intent_reviewed'=>($post['buyer_intent_reviewed']??null)==='1','gifting_occasion_reviewed'=>($post['gifting_occasion_reviewed']??null)==='1','note'=>$post['note']??null]);
        else {
            $hash=$post['factory_hash']??null;if(!is_string($hash))return new WP_Error('design_factory_input','Exact design version required.');$current=$repo->read('personalized_pod',$hash);if($current instanceof WP_Error)return $current;if($current['record']['commercial_hash']!==$commercial)return new WP_Error('design_factory_input','Design belongs to another selected concept.');
            try {
                if(is_string($op)&&str_starts_with($op,'design_image_')&&class_exists(ImageGenerationPanel::class))return (new ImageGenerationPanel($this->imageExecution))->process($post);
                if($op==='design_inspect')$result=$current;
                elseif($op==='design_prepare'){
                    $regions=array_key_exists('native_regions',$post)?self::regions($post['native_regions']):self::json($post['native_regions_json']??null,16000);if(!is_string($post['palette']??null)||!is_string($post['asset_requirements']??null)||!is_string($post['profile']??null))throw new \InvalidArgumentException();
                    $choices=['layout_notes'=>$post['layout_notes']??null,'typography'=>$post['typography']??null,'font_license_reference'=>$post['font_license_reference']??null,'palette'=>array_map('trim',explode(',',$post['palette'])),'native_regions'=>$regions,'asset_requirements'=>array_values(array_filter(array_map('trim',preg_split('/[\r\n]+/',$post['asset_requirements']))))];
                    $result=$repo->prepare('personalized_pod',$hash,$post['profile'],$choices);
                }elseif($op==='design_import'){
                    $payload=self::artworkUploads($post);$result=$repo->importArtwork('personalized_pod',$hash,$payload);
                }
                elseif($op==='design_generate'&&($post['confirm']??null)==='1')$result=$repo->generate('personalized_pod',$hash);
                elseif($op==='design_review'&&($post['confirm']??null)==='1'&&is_string($post['decision']??null)&&is_string($post['note']??null)){$criteria=[];foreach(DesignFactoryContract::REVIEW_CRITERIA as $key)$criteria[$key]=($post[$key]??null)==='1';$result=$repo->review('personalized_pod',$hash,$post['decision'],$criteria,$post['note']);}
                elseif($op==='design_revise'&&is_string($post['note']??null))$result=$repo->revise('personalized_pod',$hash,$post['note'],($post['revise_layout']??null)==='1'?self::layout($post):(($post['choices_json']??'')===''?null:self::json($post['choices_json'],16000)));
            }catch(\Throwable){return new WP_Error('design_factory_input','Input or stored evidence invalid. Inspect exact versions before retry.');}
        }
        if($result instanceof WP_Error)return $result;if(!is_array($result))return new WP_Error('design_factory_input','Explicit valid design operation required.');
        return ['hash'=>$commercial,'design_factory_hash'=>$result['hash'],'design_factory'=>$result];
    }
    private static function regions(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>10)throw new \InvalidArgumentException();
        foreach($rows as &$row){
            if(!is_array($row)||count($row)!==6||array_diff(array_keys($row),['name','kind','x','y','width','height']))throw new \InvalidArgumentException();
            foreach(['x','y','width','height']as $key){$value=$row[$key]??null;if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,4})$/D',$value))throw new \InvalidArgumentException();$row[$key]=(int)$value;}
        }unset($row);return $rows;
    }
    private static function layout(array $post): array
    {
        if(!is_string($post['palette']??null)||!is_string($post['asset_requirements']??null))throw new \InvalidArgumentException();
        return ['layout_notes'=>$post['layout_notes']??null,'typography'=>$post['typography']??null,'font_license_reference'=>$post['font_license_reference']??null,'palette'=>array_map('trim',explode(',',$post['palette'])),'native_regions'=>self::regions($post['native_regions']??null),'asset_requirements'=>array_values(array_filter(array_map('trim',preg_split('/[\r\n]+/',$post['asset_requirements']))))];
    }
    private static function artworkUploads(array $post): array
    {
        if(!array_key_exists('artwork_svg',$_FILES)&&!array_key_exists('artwork_pngs',$_FILES))return ['svg'=>$post['svg']??null,'raster_uploads'=>self::json($post['rasters_json']??'[]',8400000),'rights_note'=>$post['rights_note']??null,'source_note'=>$post['source_note']??null];
        $svg=self::uploadedBytes($_FILES['artwork_svg']??null,60000,'svg');$rasters=[];
        if(isset($_FILES['artwork_pngs'])){
            $files=$_FILES['artwork_pngs'];if(!is_array($files)||!is_array($files['error']??null)||!array_is_list($files['error'])||count($files['error'])>3)throw new \InvalidArgumentException();
            foreach($files['error']as $i=>$error){if($error===UPLOAD_ERR_NO_FILE)continue;$file=[];foreach(['name','tmp_name','error','size']as $key)$file[$key]=$files[$key][$i]??null;$rasters[]=['mime_type'=>'image/png','base64'=>base64_encode(self::uploadedBytes($file,2097152,'png'))];}
        }
        return ['svg'=>$svg,'raster_uploads'=>$rasters,'rights_note'=>$post['rights_note']??null,'source_note'=>$post['source_note']??null];
    }
    private static function uploadedBytes(mixed $file,int $maximum,string $extension): string
    {
        if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_int($file['size']??null)||$file['size']<1||$file['size']>$maximum||!is_string($file['name']??null)||strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!==$extension||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new \InvalidArgumentException();
        $bytes=file_get_contents($file['tmp_name'],false,null,0,$maximum+1);if(!is_string($bytes)||strlen($bytes)!==$file['size']||strlen($bytes)>$maximum)throw new \InvalidArgumentException();return $bytes;
    }
    private static function json(mixed $raw,int $maximum): array {if(!is_string($raw)||strlen($raw)>$maximum)throw new \InvalidArgumentException();$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($v))throw new \InvalidArgumentException();return $v;}
    private function form(string $op,string $commercial,?string $factory=null): void {echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="digiforge_commercial_research"><input type="hidden" name="operation" value="'.esc_attr($op).'"><input type="hidden" name="shop_key" value="personalized_pod"><input type="hidden" name="workflow_hash" value="'.esc_attr($commercial).'"><input type="hidden" name="return_url" value="'.esc_attr($this->baseUrl()).'">';if($factory!==null)echo '<input type="hidden" name="factory_hash" value="'.esc_attr($factory).'">';wp_nonce_field('digiforge_commercial_research');}
    private function field(string $name,string $label): void {echo '<p><label>'.esc_html($label).' <input name="'.esc_attr($name).'" maxlength="2000" required></label></p>';}
    private function check(string $name,string $label,bool $required=true): void {echo '<p><label><input type="checkbox" name="'.esc_attr($name).'" value="1"'.($required?' required':'').'> '.esc_html($label).'</label></p>';}
    private function baseUrl(): string {$id=get_queried_object_id();$url=$id>0?get_permalink($id):home_url('/');return is_string($url)?$url:home_url('/');}
}
