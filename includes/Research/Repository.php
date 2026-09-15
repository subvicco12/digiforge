<?php
declare(strict_types=1);
namespace DigiForge\Research;

use DigiForge\Database\Tables;
use DigiForge\ProductFactory\Repository as ProductRepository;
use DigiForge\Security\Logger;

final class Repository {
    public const REVIEW_PENDING = 'PENDING';
    public const REVIEW_APPROVED = 'APPROVED';
    public const REVIEW_REJECTED = 'REJECTED';
    public const SCORE_VERSION = 'v1';

    public function createSource(array $input, ?string $key = null): array|\WP_Error {
        $name = sanitize_text_field((string)($input['name'] ?? ''));
        $kind = sanitize_key((string)($input['source_type'] ?? 'manual'));
        if ($name === '' || $kind === '') return $this->error('validation','Source name and type are required.');
        return $this->insertIdempotent(Tables::research_sources(), $key, [
            'name'=>$name,'source_type'=>$kind,'environment'=>$this->environment($input['environment'] ?? 'sandbox'),
            'enabled'=>0,'config'=>wp_json_encode($this->sanitizeConfig((array)($input['config'] ?? []))),
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)
        ], 'research_source');
    }

    public function ingest(array $input, ?string $key = null): array|\WP_Error {
        $sourceId = absint($input['source_id'] ?? 0);
        if ($sourceId < 1 || $this->find(Tables::research_sources(), $sourceId) === null) return $this->error('invalid_source','Valid source_id required.');
        $externalId = sanitize_text_field((string)($input['external_id'] ?? ''));
        $title = sanitize_text_field((string)($input['title'] ?? ''));
        $body = sanitize_textarea_field((string)($input['body'] ?? ''));
        if ($title === '' && $body === '') return $this->error('validation','Observation title or body is required.');
        $canonical = $this->canonical($title . ' ' . $body);
        $hash = hash('sha256', $sourceId.'|'.$externalId.'|'.$canonical);
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::research_observations().' WHERE content_hash=%s',$hash),ARRAY_A);
        if (is_array($existing)) return $this->normalize($existing)+['deduplicated'=>true];
        $row = $this->insertIdempotent(Tables::research_observations(), $key, [
            'source_id'=>$sourceId,'external_id'=>$externalId,'title'=>$title,'body'=>$body,'content_hash'=>$hash,
            'observed_at'=>$this->safeDate($input['observed_at'] ?? null),'provenance'=>wp_json_encode($this->sanitizeConfig((array)($input['provenance'] ?? []))),
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)
        ], 'research_observation');
        if (is_wp_error($row)) return $row;
        return $row;
    }

    public function addEvidence(int $observationId, array $input, ?string $key = null): array|\WP_Error {
        if ($this->find(Tables::research_observations(),$observationId)===null) return $this->error('not_found','Observation not found.',404);
        $kind = sanitize_key((string)($input['evidence_type'] ?? 'note'));
        $value = sanitize_textarea_field((string)($input['value'] ?? ''));
        if ($value==='') return $this->error('validation','Evidence value is required.');
        return $this->insertIdempotent(Tables::research_evidence(),$key,[
            'observation_id'=>$observationId,'evidence_type'=>$kind,'value'=>$value,
            'provenance'=>wp_json_encode($this->sanitizeConfig((array)($input['provenance'] ?? []))),
            'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true)
        ],'research_evidence');
    }

    public function createCandidate(array $input, ?string $key = null): array|\WP_Error {
        $title=sanitize_text_field((string)($input['title'] ?? ''));
        if ($title==='') return $this->error('validation','Candidate title is required.');
        $canonical=$this->canonical($title);
        $fingerprint=hash('sha256',$canonical);
        global $wpdb;
        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::research_candidates().' WHERE fingerprint=%s',$fingerprint),ARRAY_A);
        if(is_array($existing)) return $this->normalize($existing)+['deduplicated'=>true];
        $score=$this->score((array)($input['signals'] ?? []));
        return $this->insertIdempotent(Tables::research_candidates(),$key,[
            'title'=>$title,'canonical_title'=>$canonical,'fingerprint'=>$fingerprint,'summary'=>sanitize_textarea_field((string)($input['summary'] ?? '')),
            'score'=>$score,'score_version'=>self::SCORE_VERSION,'score_inputs'=>wp_json_encode($this->numericSignals((array)($input['signals'] ?? []))),
            'review_status'=>self::REVIEW_PENDING,'opportunity_id'=>0,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)
        ],'research_candidate');
    }

    public function linkEvidence(int $candidateId,int $evidenceId): bool|\WP_Error {
        if($this->find(Tables::research_candidates(),$candidateId)===null || $this->find(Tables::research_evidence(),$evidenceId)===null) return $this->error('not_found','Candidate or evidence not found.',404);
        global $wpdb;
        $ok=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.Tables::research_candidate_evidence().' (candidate_id,evidence_id,created_at) VALUES (%d,%d,%s)',$candidateId,$evidenceId,current_time('mysql',true)));
        if($ok===false) return $this->error('link_failed','Unable to link evidence.',500);
        Logger::audit('research_evidence_linked',['evidence_id'=>$evidenceId],'research_candidate',(string)$candidateId);
        return true;
    }

    public function review(int $candidateId,string $decision,string $notes=''): array|\WP_Error {
        $reviewer = get_current_user_id();
        if ($reviewer < 1) return $this->error('reviewer_required','Authenticated human reviewer required.',403);
        $decision=strtoupper(sanitize_key($decision));
        if(!in_array($decision,[self::REVIEW_APPROVED,self::REVIEW_REJECTED],true)) return $this->error('validation','Decision must be APPROVED or REJECTED.');
        $candidate=$this->find(Tables::research_candidates(),$candidateId);
        if($candidate===null) return $this->error('not_found','Candidate not found.',404);
        if (($candidate['review_status'] ?? '') === $decision) return $candidate + ['idempotent_review'=>true];
        if (($candidate['review_status'] ?? '') !== self::REVIEW_PENDING) return $this->error('review_conflict','Candidate has already received a final review.',409);
        global $wpdb;
        $now=current_time('mysql',true);
        $wpdb->insert(Tables::research_reviews(),['candidate_id'=>$candidateId,'decision'=>$decision,'notes'=>sanitize_textarea_field($notes),'reviewed_by'=>$reviewer,'reviewed_at'=>$now]);
        $updated=$wpdb->update(Tables::research_candidates(),['review_status'=>$decision,'updated_at'=>$now],['id'=>$candidateId,'review_status'=>self::REVIEW_PENDING]);
        if($updated!==1) return $this->error('review_conflict','Candidate review changed concurrently or could not be saved.',409);
        Logger::audit('research_candidate_reviewed',['decision'=>$decision],'research_candidate',(string)$candidateId);
        return $this->find(Tables::research_candidates(),$candidateId) ?? $this->error('not_found','Candidate not found.',404);
    }

    public function promote(int $candidateId, ?string $idempotencyKey = null): array|\WP_Error {
        $candidate=$this->find(Tables::research_candidates(),$candidateId);
        if($candidate===null) return $this->error('not_found','Candidate not found.',404);
        if(($candidate['review_status'] ?? '')!==self::REVIEW_APPROVED) return $this->error('approval_required','Candidate requires explicit approval before promotion.',409);
        if((int)($candidate['opportunity_id'] ?? 0)>0) {
            $existing=(new ProductRepository())->find('opportunity',(int)$candidate['opportunity_id']);
            if($existing!==null) return $existing+['idempotent_replay'=>true];
        }
        $key=$idempotencyKey ?: 'research-candidate-'.$candidateId;
        $result=(new ProductRepository())->create('opportunity',['title'=>$candidate['title'],'description'=>$candidate['summary'] ?? ''],$key);
        if(is_wp_error($result)) return $result;
        global $wpdb;
        $wpdb->update(Tables::research_candidates(),['opportunity_id'=>(int)$result['id'],'updated_at'=>current_time('mysql',true)],['id'=>$candidateId],['%d','%s'],['%d']);
        Logger::audit('research_candidate_promoted',['opportunity_id'=>(int)$result['id']],'research_candidate',(string)$candidateId);
        return $result;
    }

    public function list(string $entity,int $page=1,int $perPage=20): array {
        $map=['sources'=>Tables::research_sources(),'observations'=>Tables::research_observations(),'evidence'=>Tables::research_evidence(),'candidates'=>Tables::research_candidates(),'reviews'=>Tables::research_reviews()];
        if(!isset($map[$entity])) return ['items'=>[],'pagination'=>['page'=>1,'per_page'=>20,'total_items'=>0,'total_pages'=>0]];
        $page=max(1,$page);$perPage=min(100,max(1,$perPage));$offset=($page-1)*$perPage;global $wpdb;$table=$map[$entity];
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$table.' ORDER BY id DESC LIMIT %d OFFSET %d',$perPage,$offset),ARRAY_A);$total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$table);
        return ['items'=>array_map([$this,'normalize'],is_array($rows)?$rows:[]),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total_items'=>$total,'total_pages'=>(int)ceil($total/$perPage)]];
    }

    private function insertIdempotent(string $table,?string $key,array $data,string $type): array|\WP_Error {
        $key=$key===null?null:sanitize_text_field($key); if($key==='')$key=null; if($key!==null&&strlen($key)>191)return $this->error('validation','Idempotency key too long.');
        global $wpdb;
        if($key!==null){$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE idempotency_key=%s',$key),ARRAY_A);if(is_array($existing))return $this->normalize($existing)+['idempotent_replay'=>true];$data['idempotency_key']=$key;}
        if(!$wpdb->insert($table,$data))return $this->error('create_failed','Unable to create research record.',500);
        $row=$this->find($table,(int)$wpdb->insert_id); Logger::audit($type.'_created',['idempotency_key'=>$key===null?'':'[PRESENT]'],$type,(string)$wpdb->insert_id); return $row??$this->error('create_failed','Unable to read research record.',500);
    }
    private function find(string $table,int $id):?array{global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d',$id),ARRAY_A);return is_array($row)?$this->normalize($row):null;}
    public function normalize(array $row):array{foreach(['id','source_id','observation_id','candidate_id','evidence_id','created_by','reviewed_by','opportunity_id'] as $k){if(isset($row[$k]))$row[$k]=(int)$row[$k];}if(isset($row['score']))$row['score']=(float)$row['score'];unset($row['idempotency_key']);foreach(['config','provenance','score_inputs'] as $k){if(isset($row[$k])&&is_string($row[$k])){$d=json_decode($row[$k],true);$row[$k]=is_array($d)?$d:[];}}return $row;}
    private function canonical(string $value):string{$value=strtolower(trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($value))??''));return substr($value,0,255);}
    private function score(array $signals):float{$s=$this->numericSignals($signals);$weights=['demand'=>0.35,'competition_gap'=>0.25,'margin'=>0.20,'trend'=>0.10,'evidence_quality'=>0.10];$score=0.0;foreach($weights as $k=>$w)$score+=($s[$k]??0.0)*$w;return round(max(0,min(100,$score)),2);}
    private function numericSignals(array $signals):array{$out=[];foreach(['demand','competition_gap','margin','trend','evidence_quality'] as $k)$out[$k]=max(0,min(100,(float)($signals[$k]??0)));return $out;}
    private function sanitizeConfig(array $value):array{$out=[];foreach($value as $k=>$v){$key=sanitize_key((string)$k);if($key==='')continue;if(\DigiForge\Security\Logger::isCredentialKey($key))continue;$out[$key]=is_array($v)?$this->sanitizeConfig($v):sanitize_text_field((string)$v);}return $out;}
    private function environment(mixed $value):string{$env=sanitize_key((string)$value);return in_array($env,['sandbox','test','production'],true)?$env:'sandbox';}
    private function safeDate(mixed $value):string{$v=sanitize_text_field((string)$value);return $v!==''?$v:current_time('mysql',true);}
    private function error(string $code,string $message,int $status=400):\WP_Error{return new \WP_Error('digiforge_'.$code,__($message,'digiforge'),['status'=>$status]);}
}
