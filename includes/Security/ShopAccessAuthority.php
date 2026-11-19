<?php
declare(strict_types=1);
namespace DigiForge\Security;

/**
 * Independently provisioned authority, never inferred from WP administration or provider credentials.
 * No genuine owner identity or production assignment is installed by this class.
 * The independently provisioned public key is base64 Ed25519 (32 bytes), not a WP salt.
 * A local envelope contains exact manifest JSON as a string and its detached base64 signature.
 * Manifest schema: version=1, authorities=[actor_id:int, shop:canonical string,
 * caps:existing DigiForge capability strings, expires_at:UTC epoch integer,
 * source_approval_hash:64 lowercase hex, authority:OWNER|DELEGATE].
 * Only separately reviewed signed records establish delegation authority; a grant does not.
 * Removing/changing an authority record or allowing it to expire invalidates its grants.
 * Trust key, reviewed identities, signed envelope and filesystem provisioning remain external prerequisites.
 */
final class ShopAccessAuthority
{
    public static function records(): array|\WP_Error
    {
        // Local independently provisioned envelope supports revocation between requests.
        // No application route writes this file; its signature is checked on every read.
        if(defined('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE')){
            $path=constant('DIGIFORGE_SHOP_ACCESS_AUTHORITY_FILE');
            if(!is_string($path)||!str_starts_with($path,'/')||!defined('DIGIFORGE_SHOP_ACCESS_TRUST_KEY')||!is_string(constant('DIGIFORGE_SHOP_ACCESS_TRUST_KEY')))return self::error('missing');
            clearstatcache(true,$path);$stat=@lstat($path);
            if(!is_array($stat)||($stat['mode']&0170000)!==0100000||$stat['size']>131072)return self::error('invalid');
            $stream=@fopen($path,'rb');if($stream===false)return self::error('invalid');
            $opened=fstat($stream);
            if(!is_array($opened)||($opened['mode']&0170000)!==0100000||$opened['ino']!==$stat['ino']||$opened['dev']!==$stat['dev']||$opened['size']>131072){fclose($stream);return self::error('invalid');}
            $raw=stream_get_contents($stream,131073);fclose($stream);
            if(!is_string($raw)||strlen($raw)>131072)return self::error('invalid');
            $envelope=json_decode($raw,true);
            if(!is_array($envelope)||!is_string($envelope['manifest']??null)||!is_string($envelope['signature']??null))return self::error('invalid');
            return self::verify($envelope['manifest'],$envelope['signature'],constant('DIGIFORGE_SHOP_ACCESS_TRUST_KEY'),time());
        }
        foreach(['DIGIFORGE_SHOP_ACCESS_TRUST_KEY','DIGIFORGE_SHOP_ACCESS_AUTHORITY_MANIFEST','DIGIFORGE_SHOP_ACCESS_AUTHORITY_SIGNATURE'] as $name){
            if(!defined($name)||!is_string(constant($name)))return self::error('missing');
        }
        return self::verify(constant('DIGIFORGE_SHOP_ACCESS_AUTHORITY_MANIFEST'),constant('DIGIFORGE_SHOP_ACCESS_AUTHORITY_SIGNATURE'),constant('DIGIFORGE_SHOP_ACCESS_TRUST_KEY'),time());
    }
    public static function verify(string $json,string $signature,string $publicKey,int $now): array|\WP_Error
    {
        if(strlen($json)>65536||!function_exists('sodium_crypto_sign_verify_detached'))return self::error('invalid');
        $key=base64_decode($publicKey,true);$sig=base64_decode($signature,true);
        if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return self::error('invalid');
        if(!sodium_crypto_sign_verify_detached($sig,$json,$key))return self::error('signature');
        $data=json_decode($json,true);
        if(!is_array($data)||($data['version']??null)!==1||!self::validAuthorities($data['authorities']??null,$now))return self::error('invalid');
        return $data['authorities'];
    }
    /** Shape/expiry validation alone is not signature or ownership authorization. */
    public static function validAuthorities(mixed $records,int $now): bool
    {
        if(!is_array($records)||!array_is_list($records)||count($records)>100)return false;
        foreach($records as $record){if(!ShopAccessPolicy::validRecord($record)||!in_array($record['authority']??null,['OWNER','DELEGATE'],true)||$record['expires_at']<=$now)return false;}
        return true;
    }
    private static function error(string $code): \WP_Error{return new \WP_Error('digiforge_shop_authority_'.$code,'Independent reviewed shop authority is missing or invalid.',['status'=>403]);}
}
