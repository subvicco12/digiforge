<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
use DigiForge\Queue\JobRepository;
use DigiForge\Database\Tables;
use DigiForge\Database\QueryEvidence;

/** Existing queue, leases/dependencies and Action Scheduler; never enables the general scheduler. */
final class DriveWordPressQueueJobs implements DriveQueueJobs
{
    public function create(string $transfer,string $stage,?int $dependency):?int
    {
        $id=(new JobRepository())->enqueue('google_drive_stage',['google_transfer'=>$transfer,'stage'=>$stage,'shop_key'=>'digital'],
            'google:'.hash('sha256',$transfer.':'.$stage),$dependency===null?[]:[$dependency]);return is_int($id)&&$id>0?$id:null;
    }
    public function read(int $id):?array
    {
        global $wpdb;$wpdb->last_error='';$r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::jobs().' WHERE id=%d',$id),ARRAY_A);
        if(QueryEvidence::failed()||!is_array($r)||$r['job_type']!=='google_drive_stage')return null;
        $p=json_decode($r['payload'],true);if(!is_array($p)||($p['shop_key']??null)!=='digital'
            ||!is_string($p['google_transfer']??null)||preg_match('/^[a-f0-9]{64}$/D',$p['google_transfer'])!==1
            ||!is_string($p['stage']??null)||array_diff(array_keys($p),['google_transfer','stage','shop_key','_digiforge_dependencies'])!==[])return null;
        return ['id'=>$id,'transfer'=>$p['google_transfer'],'stage'=>$p['stage'],'state'=>$r['state'],'owner'=>$r['locked_by']];
    }
    public function wake(int $id):bool
    {
        if(!function_exists('as_enqueue_async_action'))return false;$r=$this->read($id);if($r===null)return false;
        if($r['state']==='SUCCESS')return true;
        if($r['state']==='BLOCKED'&&!(new JobRepository())->transition($id,'BLOCKED','QUEUED'))return false;
        if(!in_array($r['state'],['BLOCKED','QUEUED'],true))return false;
        $args=[$id];
        if(function_exists('as_has_scheduled_action')&&as_has_scheduled_action('digiforge_google_drive_job',$args,'digiforge-google'))return true;
        return (int)as_enqueue_async_action('digiforge_google_drive_job',$args,'digiforge-google',true)>0;
    }
    public function block(int $id):bool
    {
        $r=$this->read($id);return $r!==null&&($r['state']==='BLOCKED'||($r['state']==='QUEUED'&&(new JobRepository())->transition($id,'QUEUED','BLOCKED')));
    }
    public function claim(int $id,string $owner):bool
    {
        $repo=new JobRepository();return $repo->acquireLease($id,$owner,60)&&$repo->start($id,$owner);
    }
    public function finish(int $id,string $owner,string $state):bool
    {
        $r=$this->read($id);if($r===null||$r['state']!=='RUNNING'||$r['owner']!==$owner||!in_array($state,['SUCCESS','HUMAN_REVIEW','BLOCKED'],true))return false;
        return (new JobRepository())->finishLease($id,$owner,$state);
    }
    public function active(int $id,string $owner):bool { return (new JobRepository())->hasCurrentLease($id,$owner); }
}
