<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Negative prerequisite for a sealed server-issued record, never approval or epoch authority. */
final class PrintifyAuthorizationLease
{
 /** @param array<string,mixed> $record */
 public static function isCurrent(array $record,string $planHash,int $actor,?int $now=null):bool {
  $now??=time();$issued=$record['issued_at']??null;$expires=$record['expires_at']??null;$nonce=$record['nonce']??null;
  return $now>0&&$actor>0&&preg_match('/^[a-f0-9]{64}$/D',$planHash)===1&&($record['plan_hash']??null)===$planHash&&($record['actor']??null)===$actor
   &&is_int($issued)&&is_int($expires)&&$issued>0&&$issued<=$now&&$expires>$now&&$expires>$issued&&$expires-$issued<=300
   &&is_string($nonce)&&preg_match('/^[A-Za-z0-9]{32,128}$/D',$nonce)===1;
 }
}
