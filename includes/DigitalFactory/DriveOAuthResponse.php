<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** Non-secret token-response observation, NOT proof of account ownership or permission to dispatch. */
final class DriveOAuthResponse
{
    public const SCOPE='https://www.googleapis.com/auth/drive.file';
    /** Original OAuth response must remain transient in separately authorized credential custody. */
    public static function describe(#[\SensitiveParameter] array $response,int $receivedAt,int $now,bool $revoked):array
    {
        $token=$response['access_token']??null;$ttl=$response['expires_in']??null;
        if($revoked||isset($response['error'])||!is_string($token)||preg_match('/^[A-Za-z0-9._~+\/-]{1,4096}=*$/D',$token)!==1
            ||($response['token_type']??null)!=='Bearer'||($response['scope']??null)!==self::SCOPE
            ||!is_int($ttl)||$ttl<1||$ttl>3600||$receivedAt<1||$receivedAt>$now||$now>=$receivedAt+$ttl)
            throw new \InvalidArgumentException('Google OAuth evidence unavailable.');
        // No access/refresh token, credential fingerprint, ID token or raw response is returned.
        return ['scope'=>self::SCOPE,'received_at'=>$receivedAt,'expires_at'=>$receivedAt+$ttl,
            'state'=>'OAUTH_RESPONSE_OBSERVED','account_ownership'=>'UNKNOWN','revocation_checked_by_provider'=>false,
            'external_execution_authorized'=>false];
    }
}
