<?php
declare(strict_types=1);
namespace DigiForge\Research;
use WP_Error;

/** Local append-only source→concept→selection→artwork lineage. Day1 approval envelope remains unchanged. */
final class CommercialResearchRepository
{
    public function __construct(private ?ResearchProvider $provider=null) { $this->provider??=new GovernedResearchProvider(); }
    public function create(array $input): array|WP_Error
    {
        $access=$this->access($input['shop_key']??null);if($access instanceof WP_Error)return $access;
        try {$research=CommercialResearchContract::research($input);$fingerprint=CommercialResearchContract::fingerprint($research);}catch(\Throwable){return self::error('Invalid research evidence or scope.');}
        $name='digiforge_commercial_candidate_'.$fingerprint;
        $existing=$this->stored($name);if($existing instanceof WP_Error)return $existing;
        if($existing!==null)return $this->read('personalized_pod',$existing);
        $result=$this->persist(['research'=>$research,'stage'=>'RESEARCH_READY','parent_hash'=>null,'response'=>null,'provenance'=>null,'selected_concept'=>null,'design_prompt'=>null,'artwork_version_hash'=>null,'review'=>null,'revision_note'=>'','reused_from'=>null]);
        if($result instanceof WP_Error)return $result;
        if(!add_option($name,$result['hash'],'',false))return self::error('Candidate already exists or index write is uncertain; inspect before retry.');
        $saved=$this->stored($name);return $saved===$result['hash']?$result:self::error('Candidate index confirmation failed; reconcile before retry.');
    }
    public function read(string $shop,string $hash): array|WP_Error
    {
        $access=$this->access($shop);if($access instanceof WP_Error)return $access;
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash))return self::error('Exact saved workflow hash required.');
        $raw=$this->stored('digiforge_commercial_version_'.$hash);
        if(!is_string($raw)||strlen($raw)>200000||!hash_equals($hash,hash('sha256',$raw)))return self::error('Workflow version missing, corrupt or unavailable.');
        try {$record=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($record)||($record['schema_version']??null)!==1||($record['publishing_enabled']??null)!==false||($record['order_execution_enabled']??null)!==false||($record['release_hold']??null)!==true||!is_int($record['created_by']??null)||$record['created_by']<1)throw new \RuntimeException();CommercialResearchContract::research($record['research']);}catch(\Throwable){return self::error('Stored workflow schema or shop evidence invalid.');}
        if($record['research']['shop_key']!==$shop)return self::error('Shop evidence conflict.');
        $artwork=null;
        if($record['artwork_version_hash']!==null){$artwork=(new OpportunityDesignRepository())->read($shop,$record['artwork_version_hash']);if($artwork instanceof WP_Error)return $artwork;}
        return ['hash'=>$hash,'record'=>$record,'artwork'=>$artwork];
    }
    public function importResponse(string $shop,string $hash,string $raw,array $provenance): array|WP_Error
    {
        // Imported provenance is a human declaration, not provider authenticity evidence.
        if(!in_array($provenance['method']??null,['manual','ai_import'],true))return self::error('Imported provenance must be manual or ai_import.');
        return $this->concepts($shop,$hash,$raw,$provenance);
    }
    public function assist(string $shop,string $hash): array|WP_Error
    {
        $version=$this->read($shop,$hash);if($version instanceof WP_Error)return $version;
        if($version['record']['stage']!=='RESEARCH_READY')return self::error('Prepare a research revision before another AI attempt.');
        if(!GovernedResearchProvider::executionAllowed())return self::error('STOP ALL, external lock or effective AI authorization blocks provider execution. Manual import remains available.');
        // Single-use attempt reservation is also enforced by the existing governed provider.
        $name='digiforge_commercial_attempt_'.$hash;
        if(!add_option($name,(string)get_current_user_id(),'',false))return self::error('AI attempt already started or outcome uncertain; no automatic retry.');
        try {$result=$this->provider->generate($version['record']['research'],$hash);}catch(\Throwable){return self::error('Provider failed; no automatic retry.');}
        if($result instanceof WP_Error)return self::error('Provider unavailable or failed. No automatic retry; use manual import.');
        if(!is_string($result['raw']??null)||!is_array($result['provenance']??null)||($result['provenance']['method']??null)!=='governed_ai')return self::error('Provider output/provenance malformed.');
        return $this->concepts($shop,$hash,$result['raw'],$result['provenance']);
    }
    private function concepts(string $shop,string $hash,string $raw,array $provenance): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;
        if($v['record']['stage']!=='RESEARCH_READY')return self::error('Create a research revision before replacing concepts.');
        if(array_diff(array_keys($provenance),['method','provider','model','response_id','generated_at'])||count($provenance)!==5)return self::error('Exact response provenance required.');
        foreach($provenance as $value)if(!is_string($value)||trim($value)===''||strlen($value)>300||preg_match('//u',$value)!==1)return self::error('Bounded nonempty response provenance required.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$provenance['generated_at'],new \DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d\TH:i:s\Z')!==$provenance['generated_at']||$date->getTimestamp()>time())return self::error('Exact non-future response timestamp required.');
        try {$response=CommercialResearchContract::response($raw,$v['record']['research']);}catch(\Throwable){return self::error('AI/manual response rejected: malformed, ungrounded, duplicated or unsupported fields.');}
        return $this->append($v,['stage'=>'CONCEPTS_READY','response'=>$response,'provenance'=>$provenance]);
    }
    public function select(string $shop,string $hash,int $concept): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;
        if($v['record']['stage']!=='CONCEPTS_READY'||!in_array($concept,[1,2,3],true))return self::error('Select one of this version’s three concepts.');
        return $this->append($v,['stage'=>'DESIGN_SELECTED','selected_concept'=>$concept,'design_prompt'=>CommercialResearchContract::designPrompt($v['record']['research'],$v['record']['response']['concepts'][$concept-1])]);
    }
    public function attach(string $shop,string $hash,array $artwork,array $review): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;
        if($v['record']['stage']!=='DESIGN_SELECTED'||($review['originality_reviewed']??null)!==true||($review['licensing_reviewed']??null)!==true||!is_string($review['note']??null)||trim($review['note'])===''||strlen($review['note'])>2000||array_diff(array_keys($review),['originality_reviewed','licensing_reviewed','note']))return self::error('Select a concept and record originality/licensing review before artwork intake.');
        // The caller cannot swap the chosen concept or overwrite evidence through artwork fields.
        if(array_key_exists('selected_brief',$artwork))return self::error('Selected concept is server-resolved.');
        $r=$v['record'];$input=['shop_key'=>$shop,'title'=>$r['research']['title'],'sources'=>array_map(static fn(array $s):array=>['url'=>$s['url'],'observed_at'=>$s['observed_at'],'excerpt'=>$s['excerpt']],$r['research']['sources']),'briefs'=>array_map(static fn(array $c):array=>['audience'=>$c['audience'],'visual_direction'=>$c['visual_direction'],'rationale'=>$c['rationale']],$r['response']['concepts']),'artwork'=>$artwork+['selected_brief'=>$r['selected_concept']]];
        $saved=(new OpportunityDesignRepository())->save($input);if($saved instanceof WP_Error)return $saved;
        return $this->append($v,['stage'=>'ARTWORK_READY','artwork_version_hash'=>$saved['version_hash'],'review'=>$review+['reviewed_by'=>get_current_user_id(),'reviewed_at'=>gmdate('c')]]);
    }
    public function decide(string $shop,string $hash,string $decision,string $note): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;
        if($v['record']['stage']!=='ARTWORK_READY'||!in_array($decision,['APPROVE','REJECT'],true)||trim($note)===''||strlen($note)>2000||preg_match('//u',$note)!==1)return self::error('Finished artwork and valid explicit human review note required.');
        if($v['artwork']['approval']!==null)return self::error('Artwork decision is already immutable. Start a revision.');
        // Persist and verify the rationale BEFORE making the immutable Day1 decision.
        // If decision persistence is uncertain, this intent remains available for reconciliation.
        $intent=$this->append($v,['stage'=>'REVIEW_SUBMITTED','revision_note'=>$note,'review_request'=>['decision'=>$decision,'requested_by'=>get_current_user_id()]]);
        if($intent instanceof WP_Error)return $intent;
        $result=(new OpportunityDesignRepository())->decide($shop,$v['record']['artwork_version_hash'],$decision);
        if($result instanceof WP_Error)return new WP_Error('commercial_review_uncertain','Review intent saved; artwork decision not confirmed. Inspect the intent and exact artwork before any retry.',['status'=>409,'review_intent_hash'=>$intent['hash'],'retry_permitted'=>false,'external_execution_authorized'=>false]);
        // Both records have durable confirmation. No further write follows approval.
        $intent['artwork']=$result;return $intent;
    }
    public function revise(string $shop,string $hash,string $note,?array $research=null): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;
        if(trim($note)===''||strlen($note)>2000)return self::error('Revision reason required.');
        try {$research=CommercialResearchContract::research($research??$v['record']['research']);}catch(\Throwable){return self::error('Valid revised research evidence required.');}
        return $this->append($v,['research'=>$research,'stage'=>'RESEARCH_READY','response'=>null,'provenance'=>null,'selected_concept'=>null,'design_prompt'=>null,'artwork_version_hash'=>null,'review'=>null,'revision_note'=>$note]);
    }
    public function reuse(string $shop,string $hash,string $family,string $title): array|WP_Error
    {
        $v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;
        $research=$v['record']['research'];$research['title']=$title;$research['product_family']=$family;
        try {$research=CommercialResearchContract::research($research);}catch(\Throwable){return self::error('Supported product family and title required.');}
        // Reuse evidence/creative learning, never old selection, artwork or approvals.
        return $this->append($v,['research'=>$research,'stage'=>$v['record']['response']===null?'RESEARCH_READY':'CONCEPTS_READY','selected_concept'=>null,'design_prompt'=>null,'artwork_version_hash'=>null,'review'=>null,'revision_note'=>'Family reuse requires fresh concept and artwork review.','reused_from'=>$hash]);
    }
    /** Bounded saved-version discovery; no remote research, writes or cross-shop reads. */
    public function recent(string $shop,int $limit=20):array|WP_Error
    {
        $access=$this->access($shop);if($access instanceof WP_Error)return $access;
        if($limit<1||$limit>50)return self::error('Saved research window must be 1–50 versions.');
        global $wpdb;$wpdb->last_error='';$names=$wpdb->get_col($wpdb->prepare('SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d',$wpdb->esc_like('digiforge_commercial_version_').'%', $limit));
        if($wpdb->last_error!==''||!is_array($names))return self::error('Saved research index unavailable.',503);
        $out=[];foreach($names as $name){$hash=substr($name,strlen('digiforge_commercial_version_'));$v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;$out[]=['hash'=>$hash,'title'=>$v['record']['research']['title'],'family'=>$v['record']['research']['product_family'],'stage'=>$v['record']['stage'],'created_at'=>$v['record']['created_at']];}
        return $out;
    }
    public function history(string $shop,string $hash): array|WP_Error
    {
        $history=[];$seen=[];
        while($hash!==null){if(count($history)>=50||isset($seen[$hash]))return self::error('History exceeds inspection bound or has a cycle.');$seen[$hash]=true;$v=$this->read($shop,$hash);if($v instanceof WP_Error)return $v;$history[]=['hash'=>$hash,'stage'=>$v['record']['stage'],'created_by'=>$v['record']['created_by'],'created_at'=>$v['record']['created_at'],'note'=>$v['record']['revision_note']];$hash=$v['record']['parent_hash'];}
        return $history;
    }
    private function append(array $version,array $patch): array|WP_Error {return $this->persist(array_replace($version['record'],$patch,['parent_hash'=>$version['hash']]));}
    private function persist(array $record): array|WP_Error
    {
        $record=array_replace($record,['schema_version'=>1,'created_by'=>get_current_user_id(),'created_at'=>gmdate('c'),'publishing_enabled'=>false,'order_execution_enabled'=>false,'release_hold'=>true]);
        try {$raw=CommercialResearchContract::json($record);}catch(\Throwable){return self::error('Encoding failed.');}
        if(strlen($raw)>200000)return self::error('Workflow exceeds local evidence bound.');
        $hash=hash('sha256',$raw);$name='digiforge_commercial_version_'.$hash;
        if(!add_option($name,$raw,'',false))return self::error('Version exists or write uncertain; inspect before retry.');
        return $this->read('personalized_pod',$hash);
    }
    private function access(mixed $shop): true|WP_Error {return \DigiForge\Security\PersonalizedPodAccess::allows()&&$shop==='personalized_pod'&&get_current_user_id()>0&&current_user_can('manage_digiforge_pod')&&current_user_can('manage_digiforge_research')?true:self::error('Authenticated personalized-POD research and artwork reviewer required.',403);}
    private function stored(string $name): string|WP_Error|null {global $wpdb;$wpdb->last_error='';$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE BINARY option_name=BINARY %s',$name));if(class_exists(\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::class))\DigiForge\Connector\Printify\NativeProtectedOptionDependencies::observeOption($name,$raw);return $wpdb->last_error===''&&($raw===null||is_string($raw))?$raw:self::error('Durable evidence read failed.',503);}
    private static function error(string $message,int $status=409): WP_Error {return new WP_Error('commercial_research_denied',$message,['status'=>$status,'retry_permitted'=>false,'external_execution_authorized'=>false]);}
}
