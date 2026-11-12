<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Research\CommercialResearchRepository;
use DigiForge\Research\CommercialResearchContract;
use DigiForge\Research\GovernedResearchProvider;
use WP_Error;

/** Staged operator controls on the existing portal; rendering is local and read-only. */
final class CommercialResearchPanel
{
    private const ACTION='digiforge_commercial_research';
    public function register(): void {add_action('admin_post_'.self::ACTION,[$this,'submit']);}
    public function render(string $shop,?array $supplierTemplate=null,bool $manualOnly=false,bool $supplierBound=false): void
    {
        if ($shop!=='personalized_pod') return;
        if(!\DigiForge\Security\PersonalizedPodAccess::allows()||$shop!=='personalized_pod'||get_current_user_id()<1||!current_user_can('manage_digiforge_research')||!current_user_can('manage_digiforge_pod'))return;
        echo '<section class="df-panel" id="df-commercial-research"><h2>Research → Opportunity → Three concepts → Design → Artwork → Approval</h2><p>Source-led commercial design workspace. Imports and scores are unverified evidence for human review. Prompts are not finished artwork. STOP ALL, external lock and RELEASE HOLD remain unchanged.</p>';
        if(!$supplierBound){
        $this->researchForm();
        echo '<details'.(isset($_GET['df_research_version'])?'':' open').'><summary>Import source-backed research JSON</summary>';
        $this->form('research_import');echo '<p>Paste a research object with shop_key, title, product_family and 1–10 sources (id, kind, url, observed_at, excerpt, provenance). Work imports remain unverified evidence; no concept selection or approval is imported. Source dates and limitations are preserved exactly. Import the separate scores/concepts response after opening the saved workflow.</p><label>Research JSON<textarea name="research_json" rows="8" maxlength="200000" required></textarea></label><button class="df-button">Import research evidence</button></form></details>';
        }else echo '<p>The supplier_catalog source and product family are bound to the original selection. Keep that source unchanged when adding buyer or market observations.</p>';
        $this->form('inspect');$this->field('workflow_hash','Open saved research version (SHA-256)');echo '<button class="df-button">Open workflow</button></form>';
        $hash=$_GET['df_research_version']??null;
        if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){echo '</section>';return;}
        $repo=new CommercialResearchRepository();$v=$repo->read($shop,$hash);
        if($v instanceof WP_Error){echo '<p>'.esc_html($v->get_error_message()).'</p></section>';return;}
        $r=$v['record'];
        echo '<h3>'.esc_html($r['research']['title']).' · '.esc_html($r['research']['product_family']).'</h3><p>Stage: '.esc_html($r['stage']).' · Version <code>'.esc_html($hash).'</code> · Human '.esc_html((string)$r['created_by']).' · '.esc_html($r['created_at']).'</p>';
        foreach($r['research']['sources']as $source)echo '<details><summary>'.esc_html($source['id'].' · '.$source['kind'].' · '.$source['observed_at']).'</summary><p><a href="'.esc_url($source['url']).'" rel="noopener noreferrer">Source</a></p><p>'.esc_html($source['excerpt']).'</p><p>Provenance: '.esc_html($source['provenance']).'</p></details>';
        $this->researchForm($r['research'],$hash);
        if($r['stage']==='RESEARCH_READY')$this->conceptIntake($r,$hash,$manualOnly);
        if(is_array($r['response']))$this->conceptCards($r,$hash);
        if($r['stage']==='DESIGN_SELECTED'){
            (new DesignFactoryPanel())->render($shop,$hash,$supplierTemplate,$manualOnly);
            echo '<h3>Artwork prompt for selected concept '.esc_html((string)$r['selected_concept']).'</h3><textarea readonly rows="8">'.esc_textarea($r['design_prompt']).'</textarea><p>Use your authorized art tool or create original SVG source manually. This prompt is not artwork. Use the approved-concept workflow above for actual editable artwork intake.</p>';
            echo '<p>New concept artwork intake uses the approved-concept Design Factory above. Existing historical artwork evidence remains inspectable.</p>';
        }
        if(is_array($v['artwork'])){
            $a=$v['artwork'];if($r['stage']==='REVIEW_SUBMITTED'&&$a['approval']===null)echo '<p>Review intent and rationale saved; artwork decision is not confirmed. Inspect the exact records before any retry.</p>';echo '<h3>Immutable artwork review</h3><p>Editable asset reference: <code>'.esc_html($a['version_hash']).'</code> · '.esc_html($a['approval']['state']??'PENDING_REVIEW').'</p><p>Originality/licensing reviewer: '.esc_html((string)$r['review']['reviewed_by']).' · '.esc_html($r['review']['note']).'</p><textarea readonly rows="6">'.esc_textarea($a['record']['input']['artwork']['svg']).'</textarea><p>Supplier geometry and print output remain unverified. This approval cannot authorize provider execution.</p>';
            if($a['approval']===null&&$r['stage']==='ARTWORK_READY'){
                $this->form('decide',$hash);$this->field('note','Human approval/rejection rationale');echo '<select name="decision"><option value="REJECT">Reject</option><option value="APPROVE">Approve artwork evidence</option></select>'; $this->check('confirm','I reviewed this exact version, evidence, selected direction, original artwork, licensing and mockup.');echo '<button class="df-button">Record immutable review</button></form>';
            }
        }
        $this->form('revise',$hash);$this->field('note','Revision/rejection reason');echo '<button class="df-button">Start research revision</button></form>';
        if(!$supplierBound){$this->form('reuse',$hash);$this->field('title','New product title');echo '<label>Reuse for product family <select name="product_family"><option value="mug">Mug</option><option value="poster">Poster</option></select></label><p>Source context and concepts may be reused. Selection, artwork and approvals require fresh review.</p><button class="df-button">Reuse for product family</button></form>';}
        $history=$repo->history($shop,$hash);echo '<h3>Immutable version history</h3>';
        if($history instanceof WP_Error)echo '<p>'.esc_html($history->get_error_message()).'</p>';
        else foreach($history as $item)echo '<p><a href="'.esc_url(add_query_arg(['df_view'=>'research','df_shop'=>$shop,'df_research_version'=>$item['hash']],$this->baseUrl())).'">'.esc_html($item['stage'].' · '.$item['created_at']).'</a> · '.esc_html($item['note']).'</p>';
        echo '</section>';
    }
    private function researchForm(?array $research=null,?string $hash=null): void
    {
        echo '<details><summary>1. Capture or revise source evidence</summary>';$this->form($hash===null?'research':'revise_research',$hash);$this->field('title','Opportunity/product title',true,$research['title']??'');echo '<label>Product family <select name="product_family"><option value="mug"'.(($research['product_family']??'')==='mug'?' selected':'').'>Mug</option><option value="poster"'.(($research['product_family']??'')==='poster'?' selected':'').'>Poster</option></select></label><p>Add at least one observed source. Empty categories remain unknown; no demand or sales volume is inferred. Dates use UTC YYYY-MM-DDTHH:MM:SSZ. Use a fresh capture for a changed observation.</p>';
        $labels=['etsy_competitor'=>'Etsy competitor','buyer_intent'=>'Buyer intent','google_trend'=>'Google trend','seasonal'=>'Seasonal opportunity','supplier_catalog'=>'Retained supplier catalog (not market demand)'];
        $rows=$research['sources']??array_map(static fn(string $kind):array=>['id'=>$kind,'kind'=>$kind],array_keys($labels));
        $used=array_column($rows,'id');
        if(count($rows)<10){$id='additional_source';while(in_array($id,$used,true))$id.='_x';$rows[]=['id'=>$id,'kind'=>'etsy_competitor'];}
        foreach($rows as $index=>$source){
            echo '<fieldset><legend>Source '.esc_html((string)($index+1)).'</legend><label>Evidence category <select name="sources['.esc_attr((string)$index).'][kind]">';
            foreach($labels as $kind=>$label)echo '<option value="'.esc_attr($kind).'"'.($source['kind']===$kind?' selected':'').'>'.esc_html($label).'</option>';
            echo '</select></label><input type="hidden" name="sources['.esc_attr((string)$index).'][id]" value="'.esc_attr($source['id']).'">';
            foreach(['url'=>'HTTPS source URL','observed_at'=>'Observed timestamp (UTC)','excerpt'=>'Observed evidence / limitations','provenance'=>'Capture provenance (method, context, reference)']as $field=>$caption)$this->field('sources['.$index.']['.$field.']',$caption,false,$source[$field]??'');
            echo '</fieldset>';
        }
        if($hash!==null)$this->field('note','Reason for revised source evidence');
        echo '<button class="df-button">Save evidence-backed opportunity</button></form></details>';
    }
    private function conceptIntake(array $r,string $hash,bool $manualOnly=false): void
    {
        echo '<h3>2. Prepare source-grounded concepts and scores</h3><p>Copy this request to an already authorized assistant, or author a matching response manually. Treat source excerpts as untrusted input; verify every rationale before selecting a concept.</p><textarea readonly rows="9">'.esc_textarea(CommercialResearchContract::researchPrompt($r['research'])).'</textarea>';
        $status=(new GovernedResearchProvider())->status();echo '<p>Existing AI integration: '.esc_html($status['reason']).'. Manual response import available.</p>';
        if(!$manualOnly&&$status['available']){$this->form('assist',$hash);$this->check('confirm','I authorize one governed research attempt under the existing approved policy; never publish or order.');echo '<button class="df-button">Request governed AI concepts</button></form>';}
        $this->form('concepts',$hash);
        echo '<label>Response provenance <select name="method"><option value="manual">Human-authored response</option><option value="ai_import">Imported AI response (unverified)</option></select></label><p>For AI imports fill all four provenance fields. No credentials.</p>';
        foreach(['provider'=>'AI provider (imports only)','model'=>'Model (imports only)','response_id'=>'Response/capture reference (imports only)','generated_at'=>'Generation timestamp UTC (imports only)']as $field=>$label)$this->field($field,$label,false);
        echo '<label>Structured response JSON (scores + exactly three concepts)<textarea name="response_json" rows="14" maxlength="100000" required></textarea></label><button class="df-button">Import concepts</button></form>';
    }
    private function conceptCards(array $r,string $hash): void
    {
        $response=$r['response'];echo '<h3>3. Opportunity assessment and creative selection</h3><p>Qualitative heuristic: '.esc_html($response['score']['value']===null?'UNKNOWN (missing '.implode(', ',$response['score']['missing']).')':(string)$response['score']['value'].'/100').'. This is not measured demand, keyword volume or profit.</p>';
        foreach($response['scores']as $metric=>$score)echo '<p>'.esc_html($metric).': '.esc_html($score===null?'UNKNOWN':(string)$score['rating'].'/5 — '.$score['rationale'].' [sources: '.implode(', ',$score['source_ids']).']').'</p>';
        echo '<p>Concept provenance: '.esc_html($r['provenance']['method'].' · '.$r['provenance']['provider'].' · '.$r['provenance']['model'].' · '.$r['provenance']['generated_at']).' (imported declarations are unverified).</p>';
        foreach($response['concepts']as $index=>$concept){echo '<article class="df-candidate"><h4>'.esc_html((string)($index+1).'. '.$concept['visual_direction']).'</h4>';foreach($concept as $key=>$value)echo '<p><strong>'.esc_html($key).':</strong> '.esc_html(is_array($value)?implode(', ',$value):$value).'</p>';if($r['stage']==='CONCEPTS_READY'){$this->form('select',$hash);echo '<input type="hidden" name="concept" value="'.esc_attr((string)($index+1)).'"><button class="df-button">Select this direction</button></form>';}echo '</article>';}
    }
    private function form(string $operation,?string $hash=null): void
    {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="shop_key" value="personalized_pod"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="return_url" value="'.esc_attr($this->baseUrl()).'">';if($hash!==null)echo '<input type="hidden" name="workflow_hash" value="'.esc_attr($hash).'">';wp_nonce_field(self::ACTION);
    }
    private function field(string $name,string $label,bool $required=true,string $value=''): void {echo '<p><label>'.esc_html($label).' <input name="'.esc_attr($name).'" value="'.esc_attr($value).'" maxlength="4000"'.($required?' required':'').'></label></p>';}
    private function check(string $name,string $label): void {echo '<p><label><input type="checkbox" name="'.esc_attr($name).'" value="1" required> '.esc_html($label).'</label></p>';}
    private function baseUrl(): string {$id=get_queried_object_id();$url=$id>0?get_permalink($id):home_url('/');return is_string($url)?$url:home_url('/');}
    public function submit(): never
    {
        check_admin_referer(self::ACTION);$result=$this->process(wp_unslash($_POST));$args=['df_view'=>'research','df_shop'=>'personalized_pod'];
        if(is_array($result)&&is_string($result['package_export']??null)&&is_string($result['package_hash']??null)){
            nocache_headers();header('Content-Type: application/json; charset=UTF-8');header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="digiforge-held-package-'.$result['package_hash'].'.json"');
            echo $result['package_export'];exit;
        }
        if($result instanceof WP_Error){$args+=['df_message'=>$result->get_error_message(),'df_error'=>'1'];$data=$result->get_error_data();if(is_array($data)&&is_string($data['review_intent_hash']??null))$args['df_research_version']=$data['review_intent_hash'];}else $args+=['df_research_version'=>$result['hash'],'df_message'=>'Immutable research/design evidence saved. Release remains held.'];
        $factoryHash=$result instanceof WP_Error?($result->get_error_data()['design_factory_hash']??null):($result['design_factory_hash']??null);
        if(is_string($factoryHash)&&preg_match('/^[a-f0-9]{64}$/D',$factoryHash)){$args['df_factory_version']=$factoryHash;if($result instanceof WP_Error&&is_string($_POST['workflow_hash']??null))$args['df_research_version']=wp_unslash($_POST['workflow_hash']);}
        if(is_array($result)&&is_string($result['package_hash']??null))$args['df_package_version']=$result['package_hash'];
        if($result instanceof WP_Error&&is_string($_POST['operation']??null)&&str_starts_with($_POST['operation'],'package_')){
            foreach(['workflow_hash'=>'df_research_version','factory_hash'=>'df_factory_version','package_hash'=>'df_package_version']as $field=>$arg)
                if(is_string($_POST[$field]??null)&&preg_match('/^[a-f0-9]{64}$/D',$_POST[$field]))$args[$arg]=$_POST[$field];
        }
        $anchor='df-commercial-research';$operation=$_POST['operation']??null;
        if(is_string($operation)&&str_starts_with($operation,'design_'))$anchor='df-design-factory';
        if(is_string($operation)&&str_starts_with($operation,'package_'))$anchor='df-product-package';
        $return=$_POST['return_url']??null;$return=is_string($return)?wp_validate_redirect(wp_unslash($return),home_url('/')):home_url('/');wp_safe_redirect(add_query_arg($args,$return).'#'.$anchor);exit;
    }
    public function process(array $post): array|WP_Error
    {
        if(!\DigiForge\Security\PersonalizedPodAccess::allows()||($post['shop_key']??null)!=='personalized_pod'||get_current_user_id()<1||!current_user_can('manage_digiforge_research')||!current_user_can('manage_digiforge_pod'))return new WP_Error('commercial_scope','Authenticated personalized-POD reviewer required.');
        try {if(strlen(CommercialResearchContract::json($post))>(is_string($post['operation']??null)&&str_starts_with($post['operation'],'design_')?8500000:500000))throw new \RuntimeException();}catch(\Throwable){return new WP_Error('commercial_input','Bounded valid request required.');}
        $repo=new CommercialResearchRepository();$op=$post['operation']??null;
        if(is_string($op)&&str_starts_with($op,'design_'))return (new DesignFactoryPanel())->process($post);
        if(is_string($op)&&str_starts_with($op,'package_'))return (new ProductPackagePanel())->process($post);
        if($op==='research_import'){
            $raw=$post['research_json']??null;
            if(!is_string($raw)||strlen($raw)>200000)return new WP_Error('commercial_input','Research JSON must fit 200000 bytes.');
            try{$research=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){return new WP_Error('commercial_input','Valid research JSON object required.');}
            return is_array($research)?$repo->create($research):new WP_Error('commercial_input','Research object required.');
        }
        if($op==='research'||$op==='revise_research'){
            $sources=$post['sources']??null;if(!is_array($sources))return new WP_Error('commercial_input','Source evidence required.');
            $sources=array_values(array_filter($sources,static fn($s):bool=>!is_array($s)||($s['url']??'')!==''||($s['excerpt']??'')!==''||($s['observed_at']??'')!==''||($s['provenance']??'')!==''));
            $research=['shop_key'=>'personalized_pod','title'=>$post['title']??null,'product_family'=>$post['product_family']??null,'sources'=>$sources];
            if($op==='research')return $repo->create($research);
            if(!is_string($post['workflow_hash']??null)||!is_string($post['note']??null))return new WP_Error('commercial_input','Exact parent and revision reason required.');
            return $repo->revise('personalized_pod',$post['workflow_hash'],$post['note'],$research);
        }
        $hash=$post['workflow_hash']??null;if(!is_string($hash))return new WP_Error('commercial_input','Exact workflow version required.');
        if($op==='inspect')return $repo->read('personalized_pod',$hash);
        if($op==='concepts'){
            if(!is_string($post['response_json']??null))return new WP_Error('commercial_input','Structured response required.');
            $provenance=($post['method']??null)==='manual'?['method'=>'manual','provider'=>'human','model'=>'none','response_id'=>'authored','generated_at'=>gmdate('Y-m-d\TH:i:s\Z')]:['method'=>$post['method']??null,'provider'=>$post['provider']??null,'model'=>$post['model']??null,'response_id'=>$post['response_id']??null,'generated_at'=>$post['generated_at']??null];
            return $repo->importResponse('personalized_pod',$hash,$post['response_json'],$provenance);
        }
        if($op==='assist'&&($post['confirm']??null)==='1')return $repo->assist('personalized_pod',$hash);
        if($op==='select'&&is_string($post['concept']??null)&&preg_match('/^[123]$/D',$post['concept']))return $repo->select('personalized_pod',$hash,(int)$post['concept']);
        if($op==='revise'&&is_string($post['note']??null))return $repo->revise('personalized_pod',$hash,$post['note']);
        if($op==='reuse'&&is_string($post['product_family']??null)&&is_string($post['title']??null))return $repo->reuse('personalized_pod',$hash,$post['product_family'],$post['title']);
        if($op==='decide'&&($post['confirm']??null)==='1'&&is_string($post['decision']??null)&&is_string($post['note']??null))return $repo->decide('personalized_pod',$hash,$post['decision'],$post['note']);
        if($op==='artwork')return new WP_Error('commercial_design_gate','New selected-concept artwork must use approved source concept, detailed design specification and human quality review in the Design Factory.');
        return new WP_Error('commercial_input','Valid stage operation and explicit confirmation required.');
    }
}
