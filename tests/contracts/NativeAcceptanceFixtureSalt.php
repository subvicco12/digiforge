<?php
declare(strict_types=1);
/** Test harness only: parallel isolated sites must not share MariaDB advisory-lock identity. */
final class NativeAcceptanceFixtureSalt {
 public static function derive(string $base,string $scheme,string $prefix):string {
  if($scheme!=='nonce')return $base;
  if(!preg_match('/^df[a-f0-9]{9}_$/D',$prefix))throw new InvalidArgumentException('Exact private fixture namespace required.');
  return hash_hmac('sha256','digiforge-native-fixture:'.$prefix,$base);
 }
}
