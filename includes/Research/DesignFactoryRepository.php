<?php
declare(strict_types=1);
namespace DigiForge\Research;
use DigiForge\Core\Settings;
use WP_Error;

/** Approved concept→specification→real artwork. Immutable local records; no publishing/production authority. */
final class DesignFactoryRepository
{
    private const PREFIX='digiforge_design_personalized_pod_';
    public function __construct(private ?DesignGenerationProvider $provider=null) {$this->provider??=new DesignFactoryUnavailableProvider();}
    /** Bounded operator discovery; every returned version still passes current admission and lineage. */
    public function recent(string $shop,string $commercial): array|WP_Error
    {
        $access=$this->access($shop);if(is_wp_error($access))return $access;
        $context=$this->context($shop,$commercial);if(is_wp_error($context))return $context;
        global $wpdb;$wpdb->last_error='';$prefix=self::PREFIX.'version_';
        $names=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 50',$wpdb->esc_like($prefix).'%'));
        if($wpdb->last_error!==''||!is_array($names))return self::error('Saved design index unavailable.');
        $out=[];foreach($names as $name){$hash=substr($name,strlen($prefix));$v=$this->read($shop,$hash);if(is_wp_error($v))return $v;if($v['record']['commercial_hash']!==$commercial)continue;$out[]=['hash'=>$hash,'kind'=>$v['record']['kind'],'created_at'=>$v['record']['created_at'],'review_state'=>$v['review_state']];}
        return $out;
    }
    public function providerStatus(): array {return $this->provider->status();}
    public function approveConcept(string $shop,string $commercialHash,array $review): array|WP_Error
    {
        $access=$this->access($shop);if($access instanceof WP_Error)return $access;
        if(array_diff(array_keys($review),['sources_reviewed','buyer_intent_reviewed','gifting_occasion_reviewed','note'])||count($review)!==4||($review['sources_reviewed']??null)!==true||($review['buyer_intent_reviewed']??null)!==true||($review['gifting_occasion_reviewed']??null)!==true||!self::text($review['note']??null))return self::error('Explicit source, buyer-intent and gifting-occasion review required.');
        $context=$this->context($shop,$commercialHash);if($context instanceof WP_Error)return $context;
        $name=self::PREFIX.'concept_'.$commercialHash;$existing=$this->stored($name);if($existing instanceof WP_Error)return $existing;if(is_string($existing))return $this->read($shop,$existing);
        $record=['kind'=>'CONCEPT_APPROVED','parent_hash'=>null,'commercial_hash'=>$commercialHash,'context'=>$context,'concept_review'=>$review,'concept_approval_hash'=>null,'specification'=>null,'asset'=>null,'provenance'=>null,'decision'=>null,'criteria'=>null,'note'=>$review['note']];
        $v=$this->persist($record);if($v instanceof WP_Error)return $v;
        if(!add_option($name,$v['hash'],'',false)||$this->stored($name)!==$v['hash'])return self::error('Concept approval receipt uncertain; inspect before retry.');
        return $v;
    }
    public function prepare(string $shop,string $approvalHash,string $profile,array $choices): array|WP_Error
    {
        $approval=$this->read($shop,$approvalHash);if($approval instanceof WP_Error)return $approval;
        if($approval['record']['kind']!=='CONCEPT_APPROVED')return self::error('Exact persisted source-concept approval required; selection is not approval.');
        try {$spec=DesignFactoryContract::specification($approval['record']['context'],$profile,$choices);}catch(\Throwable){return self::error('Invalid specification, technical family/profile or native reservations.');}
        return $this->append($approval,['kind'=>'SPEC_READY','concept_approval_hash'=>$approvalHash,'specification'=>$spec,'asset'=>null,'provenance'=>null,'note'=>'Detailed specification; not finished artwork.']);
    }
    public function read(string $shop,string $hash): array|WP_Error
    {
        $access=$this->access($shop);if($access instanceof WP_Error)return $access;
        $record=$this->record($hash);if($record instanceof WP_Error)return $record;
        $context=$this->context($shop,$record['commercial_hash']);if($context instanceof WP_Error)return $context;
        if(CommercialResearchContract::json($context)!==CommercialResearchContract::json($record['context']))return self::error('Source context changed or is inconsistent.');
        if($record['kind']!=='CONCEPT_APPROVED'){
            $approval=$this->record($record['concept_approval_hash']);if($approval instanceof WP_Error)return $approval;
            if($approval['kind']!=='CONCEPT_APPROVED'||$approval['commercial_hash']!==$record['commercial_hash'])return self::error('Source approval missing or belongs to another concept.');
            try {$spec=DesignFactoryContract::specification($context,$record['specification']['profile'],$record['specification']['layout']);if(CommercialResearchContract::json($spec)!==CommercialResearchContract::json($record['specification']))throw new \RuntimeException();}catch(\Throwable){return self::error('Stored specification evidence invalid.');}
        }
        if(is_array($record['asset'])){
            try {DesignFactoryContract::svg($record['asset']['svg'],$record['specification']);if(!hash_equals($record['asset']['svg_sha256'],hash('sha256',$record['asset']['svg'])))throw new \RuntimeException();}catch(\Throwable){return self::error('Editable artwork bytes unavailable or corrupt.');}
            foreach($record['asset']['raster_references']as $ref){$raster=$this->rasterData($ref['reference'],$record['specification']);if($raster instanceof WP_Error)return $raster;if($raster['metadata']!==$ref)return self::error('Raster metadata conflict.');}
        }
        $state=null;$artwork=null;$reviewHash=$record['kind']==='REVIEW_INTENT'?$hash:null;
        if($record['kind']==='REVIEW_INTENT'){
            $parent=$this->record($record['parent_hash']);if($parent instanceof WP_Error)return $parent;
            if($parent['kind']!=='ASSET_READY'||$parent['commercial_hash']!==$record['commercial_hash']||$parent['asset']!==$record['asset'])return self::error('Review parent evidence conflict.');
            $receipt=$this->stored(self::PREFIX.'decision_'.$record['parent_hash']);if($receipt instanceof WP_Error)return $receipt;
            if($receipt===null){$state='REVIEW_INTENT_UNCONFIRMED';$reviewHash=null;}
            elseif($receipt!==$hash)return self::error('A different immutable decision owns this artwork.');
        }
        if($record['kind']==='ASSET_READY'){$pointer=$this->stored(self::PREFIX.'decision_'.$hash);if($pointer instanceof WP_Error)return $pointer;$reviewHash=$pointer;}
        if(is_string($reviewHash)){
            $intent=$this->record($reviewHash);if($intent instanceof WP_Error)return $intent;
            if($intent['kind']!=='REVIEW_INTENT'||$intent['commercial_hash']!==$record['commercial_hash']||!is_array($intent['asset'])||$intent['asset']!==$record['asset'])return self::error('Review intent does not bind this artwork.');
            try {DesignFactoryContract::review($intent['criteria'],$intent['decision']==='APPROVE');}catch(\Throwable){return self::error('Human quality criteria unavailable.');}
            if($intent['decision']==='REJECT')$state='HUMAN_REJECTED';
            else {
                $state='APPROVAL_UNCONFIRMED';$bridge=$this->stored(self::PREFIX.'bridge_'.$reviewHash);if($bridge instanceof WP_Error)return $bridge;
                if(is_string($bridge)){
                    $artwork=(new OpportunityDesignRepository())->read($shop,$bridge);if($artwork instanceof WP_Error)return $artwork;
                    if(($artwork['record']['input']['artwork']['svg']??null)!==$record['asset']['svg'])return self::error('Day1 artwork bridge conflict.');
                    if($artwork['approval']!==null){if(($artwork['approval']['state']??null)!=='HUMAN_APPROVED'||($artwork['approval']['decision']??null)!=='APPROVE'||($artwork['approval']['evidence_hash']??null)!==$bridge)return self::error('Artwork approval conflict.');$state='HUMAN_APPROVED';}
                }
            }
        }
        return ['hash'=>$hash,'record'=>$record,'review_state'=>$state,'review_hash'=>$reviewHash,'artwork'=>$artwork];
    }
    public function importArtwork(string $shop,string $specHash,array $payload): array|WP_Error
    {
        return $this->accept($shop,$specHash,$payload,['method'=>'MANUAL_ARTWORK_IMPORT','actor_id'=>get_current_user_id(),'imported_at'=>gmdate('c')]);
    }
    public function generate(string $shop,string $specHash): array|WP_Error
    {
        $v=$this->read($shop,$specHash);if($v instanceof WP_Error)return $v;
        if($v['record']['kind']!=='SPEC_READY')return self::error('Approved-concept specification required.');
        if(!self::executionAllowed())return self::error('STOP ALL/external lock or effective image-generation authorization blocks dispatch. Manual artwork import is available.');
        if(($this->provider->status()['available']??false)!==true)return self::error('No authorized image provider/credentials. Manual artwork import is available.');
        if(!add_option(self::PREFIX.'attempt_'.$specHash,(string)get_current_user_id(),'',false))return self::error('Design attempt already started or uncertain; no automatic retry.');
        try {$result=$this->provider->generate($v['record']['specification'],$specHash);}catch(\Throwable){return self::error('Design provider failed. No automatic retry; inspect attempt evidence.');}
        if($result instanceof WP_Error||array_diff(array_keys($result),['asset','provenance'])||!is_array($result['asset']??null)||!is_array($result['provenance']??null))return self::error('Provider failed or returned malformed output. No automatic retry.');
        $p=$result['provenance'];if(array_diff(array_keys($p),['provider','model','response_id','generated_at'])||count($p)!==4)return self::error('Exact provider provenance required.');foreach($p as $value)if(!self::text($value,300))return self::error('Bounded provider provenance required.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$p['generated_at'],new \DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$p['generated_at']||$date->getTimestamp()>time())return self::error('Valid non-future provider timestamp required.');
        return $this->accept($shop,$specHash,$result['asset'],$p+['method'=>'PROVIDER_ARTWORK_UNREVIEWED','actor_id'=>get_current_user_id()]);
    }
    public static function executionAllowed(): bool {return \DigiForge\Security\PersonalizedPodAccess::allows(true)&&get_current_user_id()>0&&current_user_can('manage_digiforge_research')&&current_user_can('manage_digiforge_pod')&&current_user_can('manage_digiforge_ai')&&!Settings::safety_locked()&&Settings::is_enabled('ai')&&Settings::is_enabled('product_development');}
    private function accept(string $shop,string $hash,array $payload,array $provenance): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;if($v['record']['kind']!=='SPEC_READY')return self::error('Start a specification/revision before artwork intake.');
        if(array_diff(array_keys($payload),['svg','raster_uploads','rights_note','source_note'])||count($payload)!==4||!is_string($payload['svg']??null)||!self::text($payload['rights_note']??null)||!self::text($payload['source_note']??null)||!is_array($payload['raster_uploads']??null)||!array_is_list($payload['raster_uploads'])||count($payload['raster_uploads'])>3)return self::error('Original editable SVG, bounded rights/source notes and up to three PNG uploads required.');
        try {DesignFactoryContract::svg($payload['svg'],$v['record']['specification']);}catch(\Throwable){return self::error('Unsafe, oversized or geometry-incompatible editable SVG. Raster images are not automatically editable SVG.');}
        $refs=[];
        foreach($payload['raster_uploads']as $upload){
            if(!is_array($upload)||array_diff(array_keys($upload),['mime_type','base64'])||count($upload)!==2||($upload['mime_type']??null)!=='image/png'||!is_string($upload['base64']??null)||strlen($upload['base64'])>2796204)return self::error('Only bounded canonical PNG uploads are supported; no paths or remote URLs.');
            $bytes=base64_decode($upload['base64'],true);if(!is_string($bytes)||base64_encode($bytes)!==$upload['base64'])return self::error('Canonical PNG bytes required.');
            try {$metadata=DesignFactoryContract::raster($bytes,$v['record']['specification']);}catch(\Throwable){return self::error('PNG bytes, CRC, dimensions or embedded 300dpi density invalid.');}
            $reference='digiforge-design://personalized_pod/png/'.$metadata['sha256'];$metadata['reference']=$reference;
            $blob=CommercialResearchContract::json(['base64'=>$upload['base64'],'metadata'=>$metadata]);$name=self::PREFIX.'png_'.$metadata['sha256'];$existing=$this->stored($name);if($existing instanceof WP_Error)return $existing;
            if($existing===null&&!add_option($name,$blob,'',false))return self::error('Raster persistence uncertain; inspect before retry.');
            if($this->stored($name)!==$blob)return self::error('Raster confirmation conflict.');$refs[]=$metadata;
        }
        if(count(array_unique(array_column($refs,'sha256')))!==count($refs))return self::error('Duplicate raster assets are not distinct evidence.');
        return $this->append($v,['kind'=>'ASSET_READY','asset'=>['svg'=>$payload['svg'],'svg_sha256'=>hash('sha256',$payload['svg']),'raster_references'=>$refs,'rights_note'=>$payload['rights_note'],'source_note'=>$payload['source_note']],'provenance'=>$provenance,'decision'=>null,'criteria'=>null,'note'=>'Actual artwork imported; human quality review pending.']);
    }
    public function review(string $shop,string $assetHash,string $decision,array $criteria,string $note): array|WP_Error
    {
        $v=$this->read($shop,$assetHash);if($v instanceof WP_Error)return $v;
        if($v['record']['kind']!=='ASSET_READY'||$v['review_state']!==null||!in_array($decision,['APPROVE','REJECT'],true)||!self::text($note))return self::error('Exact unreviewed asset and explicit human decision/note required.');
        try {DesignFactoryContract::review($criteria,$decision==='APPROVE');}catch(\Throwable){return self::error('All human commercial/originality/licensing/mockup criteria must pass before approval.');}
        $intent=$this->append($v,['kind'=>'REVIEW_INTENT','decision'=>$decision,'criteria'=>$criteria,'note'=>$note]);if($intent instanceof WP_Error)return $intent;
        $pointer=self::PREFIX.'decision_'.$assetHash;if(!add_option($pointer,$intent['hash'],'',false)||$this->stored($pointer)!==$intent['hash'])return self::error('Another review exists or decision receipt uncertain; inspect before retry.');
        if($decision==='REJECT')return $this->read($shop,$intent['hash']);
        $r=$v['record'];$spec=$r['specification'];$g=$spec['geometry'];
        $input=['shop_key'=>$shop,'title'=>$spec['research']['title'],'sources'=>array_map(static fn(array $s):array=>['url'=>$s['url'],'observed_at'=>$s['observed_at'],'excerpt'=>$s['excerpt']],$spec['research']['sources']),'briefs'=>$spec['source_briefs'],'artwork'=>['svg'=>$r['asset']['svg'],'width_px'=>$g['width_px'],'height_px'=>$g['height_px'],'dpi'=>300,'selected_brief'=>$spec['selected_concept'],'native_layers'=>array_column($spec['layout']['native_regions'],'name'),'rights_note'=>$r['asset']['rights_note'],'safe_area_reviewed'=>$criteria['print_geometry_compatibility'],'font_license_reviewed'=>$criteria['originality_licensing'],'mockup_reviewed'=>$criteria['mockup_reviewed']]];
        $day1=(new OpportunityDesignRepository())->save($input);if($day1 instanceof WP_Error)return self::uncertain($intent['hash']);
        $bridge=self::PREFIX.'bridge_'.$intent['hash'];if(!add_option($bridge,$day1['version_hash'],'',false)||$this->stored($bridge)!==$day1['version_hash'])return self::uncertain($intent['hash']);
        $approved=(new OpportunityDesignRepository())->decide($shop,$day1['version_hash'],'APPROVE');if($approved instanceof WP_Error)return self::uncertain($intent['hash']);
        $confirmed=$this->read($shop,$intent['hash']);return $confirmed instanceof WP_Error?self::uncertain($intent['hash']):$confirmed;
    }
    public function revise(string $shop,string $hash,string $note,?array $choices=null): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;if(!is_array($v['record']['specification'])||!self::text($note))return self::error('Existing specification and revision reason required.');
        try {$spec=DesignFactoryContract::specification($v['record']['context'],$v['record']['specification']['profile'],$choices??$v['record']['specification']['layout']);}catch(\Throwable){return self::error('Valid revised specification required.');}
        return $this->append($v,['kind'=>'SPEC_READY','specification'=>$spec,'asset'=>null,'provenance'=>null,'decision'=>null,'criteria'=>null,'note'=>$note]);
    }
    public function handoff(string $shop,string $hash): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;if($v['review_state']!=='HUMAN_APPROVED'||!is_array($v['artwork']))return self::error('Confirmed immutable artwork approval required; prompts, intents and raster-only output cannot be handed off.');
        $intent=$this->record($v['review_hash']);if($intent instanceof WP_Error)return $intent;
        return ['artwork'=>$v['artwork'],'design_metadata'=>['design_hash'=>$hash,'concept_approval_hash'=>$v['record']['concept_approval_hash'],'commercial_hash'=>$v['record']['commercial_hash'],'specification'=>$v['record']['specification'],'geometry'=>$v['record']['specification']['geometry'],'raster_references'=>$v['record']['asset']['raster_references'],'human_review'=>['review_hash'=>$v['review_hash'],'decision'=>$intent['decision'],'criteria'=>$intent['criteria'],'reviewed_by'=>$intent['created_by'],'reviewed_at'=>$intent['created_at'],'note'=>$intent['note']],'supplemental_rasters_in_listing_envelope'=>false,'supplier_certified'=>false,'release_hold'=>true]];
    }
    public function raster(string $shop,string $hash,string $reference): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;
        if(!is_array($v['record']['asset'])||!in_array($reference,array_column($v['record']['asset']['raster_references'],'reference'),true))return self::error('Raster does not belong to this version.');
        return $this->rasterData($reference,$v['record']['specification']);
    }
    private function rasterData(string $reference,array $spec): array|WP_Error
    {
        if(!preg_match('~^digiforge-design://personalized_pod/png/([a-f0-9]{64})$~D',$reference,$m))return self::error('Protected exact-shop raster reference required.');
        $raw=$this->stored(self::PREFIX.'png_'.$m[1]);if(!is_string($raw)||strlen($raw)>2800000)return self::error('Raster bytes unavailable.');
        try {$blob=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_string($blob['base64']??null))throw new \RuntimeException();$bytes=base64_decode($blob['base64'],true);if(!is_string($bytes)||base64_encode($bytes)!==$blob['base64']||!hash_equals($m[1],hash('sha256',$bytes)))throw new \RuntimeException();$meta=DesignFactoryContract::raster($bytes,$spec)+['reference'=>$reference];if($meta!==$blob['metadata'])throw new \RuntimeException();}catch(\Throwable){return self::error('Raster bytes or metadata corrupt.');}
        return ['metadata'=>$meta,'base64'=>$blob['base64']];
    }
    public function history(string $shop,string $hash): array|WP_Error
    {
        $out=[];$seen=[];while($hash!==null){if(count($out)>=50||isset($seen[$hash]))return self::error('History exceeds bound or cycles.');$seen[$hash]=true;$v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;$out[]=['hash'=>$hash,'kind'=>$v['record']['kind'],'created_by'=>$v['record']['created_by'],'created_at'=>$v['record']['created_at'],'note'=>$v['record']['note']];$hash=$v['record']['parent_hash'];}return $out;
    }
    private function context(string $shop,string $hash): array|WP_Error
    {
        $v=(new CommercialResearchRepository())->read($shop,$hash);if($v instanceof WP_Error)return $v;$r=$v['record'];
        if($r['stage']!=='DESIGN_SELECTED'||!in_array($r['selected_concept'],[1,2,3],true)||!is_array($r['response']))return self::error('A source-linked selected commercial concept is required.');
        try {$parsed=CommercialResearchContract::response(CommercialResearchContract::json(['scores'=>$r['response']['scores'],'concepts'=>$r['response']['concepts']]),$r['research']);}catch(\Throwable){return self::error('Source/brief evidence invalid.');}
        return ['commercial_hash'=>$hash,'research'=>$r['research'],'concept'=>$parsed['concepts'][$r['selected_concept']-1],'selected_concept'=>$r['selected_concept'],'briefs'=>$parsed['concepts']];
    }
    private function record(mixed $hash): array|WP_Error
    {
        if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('Exact saved design hash required.');$raw=$this->stored(self::PREFIX.'version_'.$hash);
        if(!is_string($raw)||strlen($raw)>300000||!hash_equals($hash,hash('sha256',$raw)))return self::error('Design version unavailable or corrupt.');
        try {$r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($r)||($r['shop_key']??null)!=='personalized_pod'||($r['schema_version']??null)!==1||!in_array($r['kind']??null,['CONCEPT_APPROVED','SPEC_READY','ASSET_READY','REVIEW_INTENT'],true)||!is_int($r['created_by']??null)||$r['created_by']<1||($r['publishing_enabled']??null)!==false||($r['order_execution_enabled']??null)!==false||($r['release_hold']??null)!==true)throw new \RuntimeException();}catch(\Throwable){return self::error('Design schema/identity invalid.');}
        return $r;
    }
    private function append(array $v,array $patch): array|WP_Error {return $this->persist(array_replace($v['record'],$patch,['parent_hash'=>$v['hash']]));}
    private function persist(array $r): array|WP_Error
    {
        $r=array_replace($r,['schema_version'=>1,'shop_key'=>'personalized_pod','created_by'=>get_current_user_id(),'created_at'=>gmdate('c'),'publishing_enabled'=>false,'order_execution_enabled'=>false,'release_hold'=>true]);
        try {$raw=CommercialResearchContract::json($r);}catch(\Throwable){return self::error('Encoding failed.');}if(strlen($raw)>300000)return self::error('Design version exceeds local evidence limit.');
        $hash=hash('sha256',$raw);if(!add_option(self::PREFIX.'version_'.$hash,$raw,'',false))return self::error('Version already exists or write uncertain; inspect before retry.');
        return $this->read('personalized_pod',$hash);
    }
    private function stored(string $name): string|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$name));return $wpdb->last_error===''&&($raw===null||is_string($raw))?$raw:self::error('Durable evidence unavailable.',503);}
    private function access(string $shop): true|WP_Error {return \DigiForge\Security\PersonalizedPodAccess::allows()&&$shop==='personalized_pod'&&get_current_user_id()>0&&current_user_can('manage_digiforge_pod')&&current_user_can('manage_digiforge_research')?true:self::error('Authenticated exact-shop design reviewer required.',403);}
    private static function text(mixed $value,int $max=2000): bool {return is_string($value)&&trim($value)!==''&&strlen($value)<=$max&&preg_match('//u',$value)===1;}
    private static function error(string $message,int $status=409): WP_Error {return new WP_Error('design_factory_denied',$message,['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
    private static function uncertain(string $hash): WP_Error {return new WP_Error('design_factory_review_uncertain','Review intent preserved; artwork approval not confirmed. Inspect the exact design before retry.',['status'=>409,'design_factory_hash'=>$hash,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
