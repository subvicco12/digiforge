<?php
declare(strict_types=1);
namespace DigiForge\Queue;

/** Local readiness only; this policy never grants execution or dispatch authority. */
final class QueueDependencyPolicy
{
 /** @param list<int> $dependencyIds @param array<int,array<string,mixed>>|null $authoritativeRows */
 public static function evaluate(int $jobId,array $dependencyIds,?array $authoritativeRows):array
 {
  $base=['dependencies_ready'=>false,'external_execution_authorized'=>false];
  if($jobId<1||$authoritativeRows===null)return $base+['state'=>'BLOCKED','reason'=>'Authoritative dependency evidence is unavailable.'];
  $seen=[];$waiting=false;$failed=false;
  foreach($dependencyIds as $id){
   if(!is_int($id)||$id<1||$id===$jobId||isset($seen[$id]))return $base+['state'=>'BLOCKED','reason'=>'Dependency identities must be distinct positive jobs other than the dependent job.'];
   $seen[$id]=true;$row=$authoritativeRows[$id]??null;
   if(!is_array($row)||!preg_match('/^[1-9][0-9]*$/D',(string)($row['id']??''))||(int)$row['id']!==$id||!is_string($row['state']??null)||!JobState::valid($row['state']))return $base+['state'=>'BLOCKED','reason'=>'A matching dependency with a known durable state is required.'];
   if(in_array($row['state'],['FAILED','CANCELLED','DEAD_LETTER'],true))$failed=true;
   elseif($row['state']!=='SUCCESS')$waiting=true;
  }
  if($failed)return $base+['state'=>'HUMAN_REVIEW','reason'=>'A dependency terminated without success.'];
  if($waiting)return $base+['state'=>'WAITING','reason'=>'Required dependency success is not yet established.'];
  return ['dependencies_ready'=>true,'state'=>'QUEUED','reason'=>'All required dependencies succeeded.','external_execution_authorized'=>false];
 }
}
