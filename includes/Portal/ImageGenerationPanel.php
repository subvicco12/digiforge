<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\AI\{ImageGenerationAccess,ImageGenerationExecution,ImageGenerationJournal,ImageGenerationPolicyRepository,MonetaryReservationRepository};
use DigiForge\Research\DesignFactoryRepository;
use WP_Error;

/** Typed controls on the existing commercial-research action; no provider or route registration. */
final class ImageGenerationPanel
{
    public function render(string $shop,string $commercialHash,string $factorySpecHash):void
    {
        $source=$this->source($shop,$commercialHash,$factorySpecHash);
        if($source instanceof WP_Error)return;
        echo '<section class="df-image-generation"><h4>Governed original image generation</h4><p>Prepare an immutable request, review its exact inclusive provider quote, then request one independently authorized attempt. Raster candidates remain unapproved and not editable vector. STOP ALL and RELEASE HOLD remain.</p>';
        if(ImageGenerationAccess::authorize() instanceof WP_Error){echo '<p>Current Shop 2 AI authorization is required to inspect image policies and jobs. Network execution is held.</p></section>';return;}
        $policies=(new ImageGenerationPolicyRepository())->recent();$jobs=(new ImageGenerationJournal())->recent($factorySpecHash);
        if($policies instanceof WP_Error||$jobs instanceof WP_Error){echo '<p>Image policy or job evidence is unavailable. Network execution is held.</p></section>';return;}
        echo '<p>Network execution is held: NETWORK_CUSTODY_UNAVAILABLE. This server has no reviewed credential custodian. Preparation, quote review and inspection do not enable automation or authorize spending.</p>';
        $available=[];
        foreach($policies as $policy){$resolved=(new ImageGenerationPolicyRepository())->resolve($policy['hash']);if(is_array($resolved)&&in_array('ORIGINAL_ARTWORK',$resolved['policy']['engines'],true))$available[]=$resolved;}
        if($available!==[]){
            $this->form('prepare',$commercialHash,$factorySpecHash);echo '<label>Reviewed image policy <select name="policy_hash" required>';
            foreach($available as $policy){$p=$policy['policy'];echo '<option value="'.esc_attr($policy['hash']).'">'.esc_html($p['policy_key'].' / '.$p['version'].' · '.$p['size'].' · '.$p['quality'].' · maximum '.$p['currency'].' '.$p['maximum_task_charge'].($p['enabled']?' · reviewed enabled policy':' · disabled policy')).'</option>';}
            echo '</select></label><label>Previous attempt (optional successor) <select name="parent_job"><option value="">New independent request</option>';
            foreach($jobs as $job)if($this->matches($job,$factorySpecHash)&&in_array($job['state'],['SUCCEEDED','CANCELLED'],true))echo '<option value="'.esc_attr($job['id']).'">'.esc_html($job['state'].' · '.substr($job['id'],0,12)).'</option>';
            echo '</select></label>';$this->field('run_id','Run reference','image-'.wp_generate_uuid4());$this->field('started_at','Run start (UTC)',gmdate('Y-m-d H:i:s'));$this->confirm('Prepare this exact request only; this does not dispatch or approve artwork');echo '<button class="df-button">Prepare image request</button></form>';
        }else echo '<p>No current reviewed original-artwork image policy is available. Review an existing registered route below; new policies are saved disabled.</p>';
        $this->policyForm($commercialHash,$factorySpecHash);
        echo '<h5>Image attempts for this exact specification (latest 30)</h5>';
        if($jobs===[])echo '<p>No saved image attempts.</p>';
        foreach($jobs as $job)if($this->matches($job,$factorySpecHash))$this->job($job,$commercialHash,$factorySpecHash);
        echo '</section>';
    }

    public function process(array $post):array|WP_Error
    {
        $shop=$post['shop_key']??null;$commercial=$post['workflow_hash']??null;$spec=$post['factory_hash']??null;
        if(!is_string($shop)||!is_string($commercial)||!is_string($spec))return self::error('scope');
        $source=$this->source($shop,$commercial,$spec);if($source instanceof WP_Error)return $source;
        $gate=ImageGenerationAccess::authorize();if($gate instanceof WP_Error)return $gate;
        $op=$post['operation']??null;$service=new ImageGenerationExecution();$result=null;
        try{
            if($op==='design_image_review_policy'){
                if(($post['confirm']??null)!=='1')return self::error('confirmation');
                $model=self::positiveInteger($post['model_id']??null);
                $policy=(new ImageGenerationPolicyRepository())->review(['policy_key'=>$post['policy_key']??null,'version'=>$post['policy_version']??null,'binding'=>['task_id'=>self::positiveInteger($post['task_id']??null),'provider_models'=>[$model=>'gpt-image-1']],'integration_id'=>self::positiveInteger($post['integration_id']??null),'size'=>$post['size']??null,'quality'=>$post['quality']??null,'maximum_task_charge'=>$post['maximum_task_charge']??null,'currency'=>$post['currency']??null,'timeout_seconds'=>self::positiveInteger($post['timeout_seconds']??null),'max_output_bytes'=>self::positiveInteger($post['max_output_bytes']??null),'engines'=>['ORIGINAL_ARTWORK'],'prompt_version'=>$post['prompt_version']??null,'terms_source_sha256'=>$post['terms_source_sha256']??null,'enabled'=>false]);
                return $policy instanceof WP_Error?$policy:['hash'=>$commercial,'design_factory_hash'=>$spec,'image_policy'=>$policy];
            }
            if($op==='design_image_prepare'){
                if(($post['confirm']??null)!=='1'||!is_string($post['policy_hash']??null)||!is_string($post['run_id']??null)||!is_string($post['started_at']??null))return self::error('confirmation');
                $parent=$post['parent_job']??'';if(!is_string($parent))return self::error('parent');
                if($parent!==''){$job=$service->read($shop,$parent);if($job instanceof WP_Error)return $job;if(!$this->matches($job,$spec))return self::error('job_source');}
                $result=$service->prepareDesign($shop,$spec,$post['policy_hash'],['run_id'=>$post['run_id'],'started_at'=>$post['started_at']],$parent===''?null:$parent);
            }else{
                if(!is_string($post['image_job_id']??null))return self::error('job');
                $job=$service->read($shop,$post['image_job_id']);if($job instanceof WP_Error)return $job;if(!$this->matches($job,$spec))return self::error('job_source');
                if($op==='design_image_inspect')$result=$job;
                else{
                    if(($post['confirm']??null)!=='1')return self::error('confirmation');
                    if($op==='design_image_run'){
                        $policy=(new ImageGenerationPolicyRepository())->resolve($job['identity']['policy_sha256']);if($policy instanceof WP_Error)return $policy;
                        if($job['identity']['mode']!=='NETWORK'||!$policy['policy']['enabled']||!ImageGenerationAccess::networkAllowed())return self::error('network_held');
                        // This default service has no server custodian; request data cannot supply one.
                        return new WP_Error('image_panel_network_custody_unavailable','NETWORK_CUSTODY_UNAVAILABLE: a reviewed server credential custodian is required before network execution.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);
                    }elseif($op==='design_image_cancel')$result=$service->cancel($shop,$job['id']);
                    elseif($op==='design_image_recover')$result=$service->recover($shop,$job['id']);
                    elseif($op==='design_image_quote'){
                        $document=self::upload($_FILES['quote_file']??null,'json',32768);$quote=json_decode($document,true,16,JSON_THROW_ON_ERROR);$policy=(new ImageGenerationPolicyRepository())->resolve($job['identity']['policy_sha256']);if($policy instanceof WP_Error)return $policy;
                        if(!is_array($quote)||($quote['model']??null)!==$job['identity']['wire_contract']['model']||($quote['request_sha256']??null)!==$job['identity']['wire_contract']['request_sha256']||($quote['currency']??null)!==$policy['policy']['currency'])return self::error('quote_source');
                        $saved=(new MonetaryReservationRepository())->reviewQuote($shop,$document,'CONFIRM_PROVIDER_QUOTE');if($saved instanceof WP_Error)return $saved;$result=$job;
                    }elseif($op==='design_image_derivative'){
                        if(!is_string($post['expected_event']??null)||!preg_match('/^[a-f0-9]{64}$/D',$post['expected_event'])||!is_string($post['source_sha256']??null)||!is_string($post['rights_note']??null)||!is_string($post['source_note']??null))return self::error('derivative');
                        $result=$service->linkDerivative($shop,$job['id'],$post['source_sha256'],['svg'=>self::upload($_FILES['derivative_svg']??null,'svg',60000),'raster_uploads'=>[],'rights_note'=>$post['rights_note'],'source_note'=>$post['source_note']],true,$post['expected_event']);
                    }
                }
            }
        }catch(\Throwable){return self::error('input');}
        if($result instanceof WP_Error)return $result;if(!is_array($result))return self::error('operation');
        return ['hash'=>$commercial,'design_factory_hash'=>($result['state']??null)==='DERIVATIVE_LINKED'?$result['derivative_hash']:$spec,'image_job'=>$result];
    }

    private function source(string $shop,string $commercial,string $spec):array|WP_Error
    {
        if($shop!=='personalized_pod'||get_current_user_id()<1||!current_user_can('manage_digiforge_pod')||!current_user_can('manage_digiforge_research')||!\DigiForge\Security\PersonalizedPodAccess::allows())return self::error('scope');
        $source=(new DesignFactoryRepository())->read($shop,$spec);
        return $source instanceof WP_Error?$source:($source['record']['kind']==='SPEC_READY'&&$source['record']['commercial_hash']===$commercial?$source:self::error('specification'));
    }
    private function matches(array $job,string $spec):bool
    {
        return ($job['identity']['shop_key']??null)==='personalized_pod'&&($job['identity']['source_type']??null)==='DESIGN'&&($job['identity']['source_key']??null)===$spec&&($job['identity']['source_version']??null)===$spec&&($job['identity']['engine']??null)==='ORIGINAL_ARTWORK';
    }
    private function job(array $job,string $commercial,string $spec):void
    {
        $id=$job['id'];echo '<details><summary>'.esc_html($job['state'].' · '.substr($id,0,12).' · '.$job['recorded_at']).'</summary><p>Exact image attempt <code>'.esc_html($id).'</code>. Request <code>'.esc_html($job['identity']['wire_contract']['request_sha256']).'</code>; model '.esc_html($job['identity']['wire_contract']['model']).'.</p><p>Actual provider charge is not assumed. Reserved or unknown amounts require monetary reconciliation; cancellation does not refund a hold.</p>';
        if(isset($job['error_class']))echo '<p>Held reason: '.esc_html($job['error_class']).'</p>';
        $this->form('inspect',$commercial,$spec,$id);echo '<button class="df-button">Inspect exact image attempt</button></form>';
        if($job['state']==='PREPARED'){
            $this->form('quote',$commercial,$spec,$id);echo '<label>Provider quote document file (JSON, up to 32768 bytes)<input type="file" name="quote_file" accept=".json,application/json" required></label>';$this->confirm('I reviewed this provider-issued inclusive quote for the exact request fingerprint, model, currency and validity interval');echo '<button class="df-button">Record reviewed provider quote</button></form>';
            $p=(new ImageGenerationPolicyRepository())->resolve($job['identity']['policy_sha256']);$allowed=false; // Default server service intentionally has no reviewed credential custodian.
            $this->form('run',$commercial,$spec,$id);$this->confirm('Request one provider attempt against the reviewed quote and hard budgets; candidate remains unapproved');echo '<button class="df-button"'.($allowed?'':' disabled').'>Request one network image attempt</button></form>';
        }
        if(in_array($job['state'],['PREPARED','RESERVED','BLOCKED','UNKNOWN','HELD'],true)){$this->form('cancel',$commercial,$spec,$id);$this->confirm('Cancel this attempt; existing monetary holds remain for reconciliation');echo '<button class="df-button">Cancel exact attempt</button></form>';}
        if(in_array($job['state'],['RESERVED','DISPATCHING','ADOPTING'],true)){$this->form('recover',$commercial,$spec,$id);$this->confirm('Record interrupted execution as unknown, without retry or assumed refund');echo '<button class="df-button">Record interrupted attempt</button></form>';}
        if(is_array($job['result']??null)){
            $artifact=(new ImageGenerationExecution())->artifact('personalized_pod',$id);
            if(is_array($artifact))echo '<p>Unapproved raster candidate — not editable vector; composition, rights, print parity and supplier suitability require human review.</p><img alt="Unapproved raster candidate" style="max-width:100%;height:auto" src="data:image/png;base64,'.esc_attr(base64_encode($artifact['bytes'])).'">';
            else echo '<p>Candidate bytes are unavailable or changed; preview is held.</p>';
        }
        if($job['state']==='SUCCEEDED'){
            $this->form('derivative',$commercial,$spec,$id);echo '<input type="hidden" name="expected_event" value="'.esc_attr($job['event_sha256']).'"><input type="hidden" name="source_sha256" value="'.esc_attr($job['result']['sha256']).'"><label>Human-adapted editable SVG file (up to 60000 bytes, exact design canvas)<input type="file" name="derivative_svg" accept=".svg,image/svg+xml" required></label>';$this->field('rights_note','Originality / artwork and font licensing evidence');$this->field('source_note','Human adaptation provenance and tool reference','',1500);$this->confirm('I adapted this exact raster source into actual editable SVG. This declaration is not artwork approval');echo '<button class="df-button">Link manual editable derivative for human artwork review</button></form>';
        }
        if(is_string($job['derivative_hash']??null))echo '<p><a href="'.esc_url(add_query_arg(['df_view'=>'research','df_shop'=>'personalized_pod','df_research_version'=>$commercial,'df_factory_version'=>$job['derivative_hash']],$this->baseUrl())).'#df-design-factory">Open linked editable derivative for separate human artwork review</a></p>';
        echo '</details>';
    }
    private function policyForm(string $commercial,string $spec):void
    {
        $ai=new \DigiForge\AI\Repository();$tasks=$ai->list('tasks',1,100);$models=$ai->list('models',1,100);$connections=(new \DigiForge\Integrations\Repository())->all(1,100);
        if($tasks instanceof WP_Error||$models instanceof WP_Error||$connections instanceof WP_Error){echo '<p>Registered image route choices are unavailable.</p>';return;}
        echo '<details><summary>Review a disabled original-artwork image policy</summary><p>Choose existing registered production rows (latest 100). No credentials, registry entries or global switches are created or enabled. The policy is saved disabled.</p>';$this->form('review_policy',$commercial,$spec);
        echo '<label>Registered production image task <select name="task_id" required>';
        foreach($tasks['items'] as $row)if($row['environment']==='production'&&$row['status']==='ACTIVE'&&in_array('image_generation',$row['required_capabilities']??[],true))echo '<option value="'.esc_attr((string)$row['id']).'">'.esc_html($row['name'].' (#'.$row['id'].')').'</option>';
        echo '</select></label><label>Registered production image model (provider model gpt-image-1) <select name="model_id" required>';
        foreach($models['items'] as $row)if($row['environment']==='production'&&$row['provider']==='openai'&&$row['model_class']==='image'&&!empty($row['enabled'])&&in_array('image_generation',$row['capabilities']??[],true))echo '<option value="'.esc_attr((string)$row['id']).'">'.esc_html($row['model_key'].' (#'.$row['id'].')').'</option>';
        echo '</select></label><label>Existing configured production AI connector <select name="integration_id" required>';
        foreach($connections['items'] as $row)if($row['provider']==='ai'&&$row['environment']==='production'&&$row['status']==='CONFIGURED')echo '<option value="'.esc_attr((string)$row['id']).'">'.esc_html($row['display_name'].' (#'.$row['id'].')').'</option>';
        echo '</select></label>';
        foreach(['policy_key'=>'Policy reference','policy_version'=>'Immutable policy version','prompt_version'=>'Prompt instruction version','terms_source_sha256'=>'Reviewed provider terms / pricing source SHA-256','maximum_task_charge'=>'Inclusive maximum task charge (decimal)','currency'=>'Original currency (three uppercase letters)']as $key=>$label)$this->field($key,$label,$key==='currency'?'USD':'');
        echo '<p>Only 1024×1024 candidates fit the current bounded local raster verifier. Larger provider sizes are unavailable.</p><label>Candidate size <select name="size"><option>1024x1024</option></select></label><label>Candidate quality <select name="quality"><option>low</option><option>medium</option><option>high</option></select></label><label>Request timeout (seconds, 1–120)<input name="timeout_seconds" type="number" inputmode="numeric" min="1" max="120" step="1" value="20" required></label><label>Maximum candidate PNG bytes (1–2097152)<input name="max_output_bytes" type="number" inputmode="numeric" min="1" max="2097152" step="1" value="2097152" required></label>';
        $this->confirm('I reviewed the exact registered image route, credential metadata and source terms; save a disabled policy');echo '<button class="df-button">Review and save disabled image policy</button></form></details>';
    }
    private function form(string $operation,string $commercial,string $spec,?string $job=null):void
    {
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="digiforge_commercial_research"><input type="hidden" name="operation" value="design_image_'.esc_attr($operation).'"><input type="hidden" name="shop_key" value="personalized_pod"><input type="hidden" name="workflow_hash" value="'.esc_attr($commercial).'"><input type="hidden" name="factory_hash" value="'.esc_attr($spec).'"><input type="hidden" name="return_url" value="'.esc_attr($this->baseUrl()).'">';
        if($job!==null)echo '<input type="hidden" name="image_job_id" value="'.esc_attr($job).'">';wp_nonce_field('digiforge_commercial_research');
    }
    private function field(string $name,string $label,string $value='',int $maximum=2000):void {echo '<label>'.esc_html($label).'<input name="'.esc_attr($name).'" value="'.esc_attr($value).'" maxlength="'.esc_attr((string)$maximum).'" required></label>';}
    private function confirm(string $label):void {echo '<p><label><input type="checkbox" name="confirm" value="1" required> '.esc_html($label).'</label></p>';}
    private function baseUrl():string {$id=get_queried_object_id();$url=$id>0?get_permalink($id):home_url('/');return is_string($url)?$url:home_url('/');}
    private static function positiveInteger(mixed $value):int {if(!is_string($value)||!preg_match('/^[1-9][0-9]{0,8}$/D',$value))throw new \InvalidArgumentException();return (int)$value;}
    private static function upload(mixed $file,string $extension,int $maximum):string
    {
        if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_int($file['size']??null)||$file['size']<1||$file['size']>$maximum||!is_string($file['name']??null)||strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!==$extension||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new \InvalidArgumentException();
        $bytes=file_get_contents($file['tmp_name'],false,null,0,$maximum+1);if(!is_string($bytes)||strlen($bytes)!==$file['size']||strlen($bytes)>$maximum)throw new \InvalidArgumentException();return $bytes;
    }
    private static function error(string $reason):WP_Error {return new WP_Error('image_panel_'.$reason,'Exact current specification, image authorization, reviewed policy and explicit human confirmation are required. Network execution remains held unless independently authorized.',['status'=>409,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
