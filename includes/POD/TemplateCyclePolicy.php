<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** UTC, half-open cycle boundaries derived from explicit operator policy. */
final class TemplateCyclePolicy {
 public static function fromForm(array $input):?array {
  $limit=trim((string)($input['template_cycle_limit']??''));$duration=trim((string)($input['template_cycle_seconds']??''));$anchor=trim((string)($input['template_cycle_anchor']??''));
  if($limit===''&&$duration===''&&$anchor==='')return null;
  if(!preg_match('/^[1-9][0-9]{0,2}$/',$limit)||!preg_match('/^[1-9][0-9]{1,7}$/',$duration))throw new \InvalidArgumentException('Template limit and duration must be bounded whole numbers.');
  $policy=['limit'=>(int)$limit,'duration_seconds'=>(int)$duration,'anchor_utc'=>$anchor];self::at($policy,time());return $policy;
 }
 public static function at(array $policy,int $now):array {
  $limit=$policy['limit']??null;$duration=$policy['duration_seconds']??null;$anchor=$policy['anchor_utc']??null;
  if(!is_int($limit)||$limit<1||$limit>500||!is_int($duration)||$duration<60||$duration>31536000||!is_string($anchor))throw new \InvalidArgumentException('Explicit bounded template limit, duration and UTC anchor are required.');
  $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$anchor,new \DateTimeZone('UTC'));
  if($date===false||$date->format('Y-m-d H:i:s')!==$anchor||$date->getTimestamp()>$now)throw new \InvalidArgumentException('Template cycle anchor must be a valid UTC timestamp at or before now.');
  $start=$date->getTimestamp()+intdiv($now-$date->getTimestamp(),$duration)*$duration;
  $identity=hash('sha256',$anchor.'|'.$duration.'|'.$start);
  return ['cycle_id'=>'template-'.$identity,'starts_at'=>gmdate('Y-m-d H:i:s',$start),'ends_at'=>gmdate('Y-m-d H:i:s',$start+$duration),'limit'=>$limit,'duration_seconds'=>$duration,'anchor_utc'=>$anchor,'external_execution_authorized'=>false];
 }
}
