<?php
/** Shared isolated-fixture signer. Refuses all preconfigured trust; never a production provisioner. */
final class SyntheticShopAuthority {
 private static ?string $path=null;private static ?string $secret=null;private static array $issuedApprovals=[];
 public static function ownsApproval(string $hash):bool{return isset(self::$issuedApprovals[$hash]);}
 public static function clear():void{if(self::$path!==null)self::write([]);}
 public static function write(array $records):void{
  if(self::$path===null){if(defined('DIGIFORGE_SHOP_ACCESS_TRUST_KEY')||defined('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE'))throw new RuntimeException('Refusing configured authority.');$keys=sodium_crypto_sign_keypair();self::$secret=sodium_crypto_sign_secretkey($keys);self::$path=tempnam(sys_get_temp_dir(),'df-synthetic-shop-');if(self::$path===false)throw new RuntimeException('Synthetic authority fixture unavailable');define('DIGIFORGE_SHOP_ACCESS_TRUST_KEY',base64_encode(sodium_crypto_sign_publickey($keys)));define('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE',self::$path);register_shutdown_function(static function(){if(self::$path!==null)unlink(self::$path);if(self::$secret!==null)sodium_memzero(self::$secret);});}
  $manifest=wp_json_encode(['version'=>1,'authorities'=>$records]);if(file_put_contents(self::$path,wp_json_encode(['manifest'=>$manifest,'signature'=>base64_encode(sodium_crypto_sign_detached($manifest,self::$secret))]),LOCK_EX)===false)throw new RuntimeException('Synthetic authority write failed.');
  foreach($records as $record){$hash=is_array($record)?($record['source_approval_hash']??null):null;if(is_string($hash)&&preg_match('/^[a-f0-9]{64}$/D',$hash))self::$issuedApprovals[$hash]=true;}
 }
}
