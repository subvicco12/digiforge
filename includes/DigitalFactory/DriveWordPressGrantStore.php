<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
use DigiForge\Operations\RecoveryDispatchLedger;
/** Existing uncached, separate-autocommit, CAS storage; no new schema. */
final class DriveWordPressGrantStore implements DriveGrantStore
{
    public function read(string $key):?array
    {
        if(!self::key($key))return null;
        try{$value=RecoveryDispatchLedger::read($key);return is_array($value)?$value:null;}catch(\Throwable){return null;}
    }
    public function compareAndSwap(string $key,array $expected,array $next):bool
    {
        if(!self::key($key))return false;
        try{return RecoveryDispatchLedger::compareAndSwap($key,$expected,$next);}catch(\Throwable){return false;}
    }
    private static function key(string $key):bool { return preg_match('/^digiforge_google_grant_[a-f0-9]{64}$/D',$key)===1; }
}
