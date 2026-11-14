<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Research\{ArtworkPersonalizationRepository,DesignFactoryRepository,CommercialResearchContract};
use WP_Error;

/** Uses the existing authenticated research route; no new orchestrator or external action. */
final class ArtworkPersonalizationPanel
{
    public function render(string $shop,string $commercial,string $factory):void
    {
        $context=(new DesignFactoryRepository())->read($shop,$factory);
        if(is_wp_error($context)||($context['review_state']??null)!=='HUMAN_APPROVED'||$context['record']['commercial_hash']!==$commercial)return;
        echo '<section class="df-panel" id="df-artwork-personalization"><h3>Customer fields → local sample proof → human review</h3><p>Original fixed artwork stays separate. Local generic-font proofs are DO NOT IMPORT, not production files. Native provider sizing, glyphs, physical floors and preview/final parity remain UNVERIFIED. Image upload/crop is not supported in this text-proof screen: use MANUAL REVIEW, never a fake image preview.</p>';
        echo '<p>Product design: '.esc_html($context['record']['context']['research']['title']).' · '.esc_html($context['record']['specification']['profile']).'</p>';
        $repo=new ArtworkPersonalizationRepository();$versions=$repo->recent($shop,$factory);
        if(is_wp_error($versions)){echo '<p>'.esc_html($versions->get_error_message()).'</p></section>';return;}
        echo '<details><summary>Saved schema and sample versions (latest 50 records)</summary><ul>';
        foreach($versions as $v)echo '<li><a href="'.esc_url($this->url($commercial,$factory,$v['hash'])).'">'.esc_html($v['record']['kind'].' · '.$v['record']['created_at'].' · '.substr($v['hash'],0,12)).'</a></li>';
        echo '</ul></details>';
        $selected=null;$hash=$_GET['df_personalization_version']??null;
        if(is_string($hash)&&preg_match('/^[a-f0-9]{64}$/D',$hash)){
            $selected=$repo->read($shop,$hash);
            if(is_wp_error($selected)||$selected['record']['factory_hash']!==$factory){echo '<p>Exact personalization version does not belong to this artwork.</p>';$selected=null;}
        }
        $fields=[];foreach($context['record']['specification']['layout']['native_regions']as $region)$fields[]=['key'=>$region['name'],'type'=>$region['name']==='date'?'date':'text','required'=>true,'max_length'=>$region['name']==='date'?10:20];
        if($selected!==null&&$selected['record']['kind']==='SCHEMA')$fields=$selected['record']['data']['fields'];
        $this->form('personalization_schema',$commercial,$factory);
        if($selected!==null&&$selected['record']['kind']==='SCHEMA')echo '<input type="hidden" name="parent_hash" value="'.esc_attr($selected['hash']).'">';
        echo '<h4>Save customer-input schema</h4><p>Each key must match one recorded native text rectangle. Types: text/date; explicit character caps 1–80. Changing a schema creates another immutable version.</p><textarea name="fields_json" rows="6" maxlength="16000" required>'.esc_textarea(CommercialResearchContract::json($fields)).'</textarea><button class="df-button">Save field schema</button></form>';
        if($selected===null){echo '</section>';return;}
        $r=$selected['record'];$schema=$r['kind']==='SCHEMA'?$selected:$repo->read($shop,$r['data']['schema_hash']);
        if(is_wp_error($schema)){echo '<p>Source schema unavailable.</p></section>';return;}
        echo '<p>Local design workspace <code>'.esc_html($r['workspace_id']).'</code> · Exact version <code>'.esc_html($selected['hash']).'</code>. These are not provider product IDs or business versions.</p>';
        $this->form('personalization_sample',$commercial,$factory);echo '<input type="hidden" name="schema_hash" value="'.esc_attr($schema['hash']).'">';
        if($r['kind']==='PROOF')echo '<input type="hidden" name="parent_hash" value="'.esc_attr($selected['hash']).'">';
        echo '<h4>Enter sample customer values</h4>';
        foreach($schema['record']['data']['fields']as $field){$value=$r['kind']==='PROOF'?($r['data']['result']['samples'][$field['key']]??''):'';echo '<p><label>'.esc_html(str_replace('_',' ',$field['key'])).' <input type="'.esc_attr($field['type']==='date'?'date':'text').'" name="samples['.esc_attr($field['key']).']" maxlength="'.esc_attr((string)$field['max_length']).'" value="'.esc_attr($value).'"'.($field['required']?' required':'').'></label></p>';}
        echo '<button class="df-button">Save immutable local proof</button></form>';
        if($r['kind']==='PROOF'){
            $result=$r['data']['result'];echo '<h4>'.esc_html($result['state']).' — DO NOT IMPORT</h4>';
            if(is_string($result['svg']))echo '<img alt="Local personalization proof — not production artwork" style="max-width:100%;width:600px" src="data:image/svg+xml;base64,'.esc_attr(base64_encode($result['svg'])).'">';
            else echo '<p>Manual HOLD: '.esc_html(implode(', ',$result['reasons'])).'. No output or substitution generated.</p>';
            echo '<p>Fixed-art SHA-256 <code>'.esc_html($result['artwork_sha256']).'</code> · Proof SHA-256 <code>'.esc_html($result['proof_sha256']??'NONE — HOLD').'</code></p>';
            if($selected['review']!==null)echo '<p>Human local-proof decision: '.esc_html($selected['review']['decision']).' · '.esc_html($selected['review']['note']).'. Production authority: NO.</p>';
            else{
                $this->form('personalization_review',$commercial,$factory);echo '<input type="hidden" name="proof_hash" value="'.esc_attr($selected['hash']).'"><select name="decision"><option value="REJECT">Reject / revise sample</option><option value="APPROVE"'.($result['state']==='MANUAL_HOLD'?' disabled':'').'>Approve this local sample only</option></select><textarea name="note" rows="2" maxlength="2000" required></textarea><label><input type="checkbox" name="confirm" value="1" required> I reviewed these exact source/sample bytes; provider qualification and production stay held.</label><button class="df-button">Record immutable sample review</button></form>';
            }
        }
        $history=$repo->history($shop,$selected['hash']);echo '<h4>Sample/schema revision history</h4>';
        if(is_wp_error($history))echo '<p>'.esc_html($history->get_error_message()).'</p>';
        else foreach($history as $v)echo '<p><a href="'.esc_url($this->url($commercial,$factory,$v['hash'])).'">'.esc_html($v['record']['kind'].' · '.$v['record']['created_at']).'</a></p>';
        echo '</section>';
    }
    public function process(array $post):array|WP_Error
    {
        if(($post['shop_key']??null)!=='personalized_pod'||!is_string($post['workflow_hash']??null)||!is_string($post['factory_hash']??null))return $this->error();
        $factory=$post['factory_hash'];$commercial=$post['workflow_hash'];$context=(new DesignFactoryRepository())->read('personalized_pod',$factory);
        if(is_wp_error($context))return $context;if($context['record']['commercial_hash']!==$commercial)return $this->error();
        $repo=new ArtworkPersonalizationRepository();$result=null;
        try{
            $op=$post['operation']??null;$parent=($post['parent_hash']??'')===''?null:$post['parent_hash'];
            if($op==='personalization_schema'){$raw=$post['fields_json']??null;if(!is_string($raw)||strlen($raw)>16000)throw new \RuntimeException();$fields=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($fields))throw new \RuntimeException();$result=$repo->define('personalized_pod',$factory,$fields,$parent);}
            elseif(in_array($op,['personalization_sample','personalization_review'],true)){
                $hash=$post[$op==='personalization_sample'?'schema_hash':'proof_hash']??null;if(!is_string($hash))throw new \RuntimeException();$source=$repo->read('personalized_pod',$hash);if(is_wp_error($source))return $source;if($source['record']['factory_hash']!==$factory)return $this->error();
                if($op==='personalization_sample'){if(!is_array($post['samples']??null))throw new \RuntimeException();$result=$repo->sample('personalized_pod',$hash,$post['samples'],$parent);}
                elseif(($post['confirm']??null)==='1'&&is_string($post['decision']??null)&&is_string($post['note']??null))$result=$repo->decide('personalized_pod',$hash,$post['decision'],$post['note']);
            }
        }catch(\Throwable){return $this->error();}
        if(is_wp_error($result))return $result;if(!is_array($result))return $this->error();
        return ['hash'=>$commercial,'design_factory_hash'=>$factory,'personalization_hash'=>$result['hash']];
    }
    private function form(string $op,string $commercial,string $factory):void {echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="digiforge_commercial_research"><input type="hidden" name="shop_key" value="personalized_pod"><input type="hidden" name="operation" value="'.esc_attr($op).'"><input type="hidden" name="workflow_hash" value="'.esc_attr($commercial).'"><input type="hidden" name="factory_hash" value="'.esc_attr($factory).'"><input type="hidden" name="return_url" value="'.esc_attr($this->base()).'">';wp_nonce_field('digiforge_commercial_research');}
    private function base():string {$id=get_queried_object_id();$v=$id>0?get_permalink($id):home_url('/');return is_string($v)?$v:home_url('/');}
    private function url(string $commercial,string $factory,string $version):string {return add_query_arg(['df_view'=>'research','df_shop'=>'personalized_pod','df_research_version'=>$commercial,'df_factory_version'=>$factory,'df_personalization_version'=>$version],$this->base()).'#df-artwork-personalization';}
    private function error():WP_Error {return new WP_Error('artwork_personalization_input','Exact Shop 2 artwork/schema and explicit bounded customer input required.',['external_execution_authorized'=>false,'retry_permitted'=>false]);}
}
