<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!defined('ABSPATH')) exit;

/** Private credential custody and one explicitly approved Shops GET. */
final class ManagedConnection
{
    private const CREDENTIAL = 'credential.json';
    private const PENDING = 'credential-save.pending';
    private const UNCERTAIN = 'CREDENTIAL_SAVE_UNCERTAIN';
    private const SAVE_FAILED = 'CREDENTIAL_SAVE_FAILED';
    private const ACTION = 'df_printify_managed_connection';
    private const MAX_WINDOW = 300;

    public static function enabled(): bool
    {
        return defined('DIGIFORGE_PRINTIFY_MANAGED_DIR');
    }

    private static function directory(): ?string
    {
        $path = self::enabled() ? DIGIFORGE_PRINTIFY_MANAGED_DIR : null;
        $document = $_SERVER['DOCUMENT_ROOT'] ?? null;
        if (!is_string($path) || !str_starts_with($path,'/') || str_contains($path,"\0")
            || !is_string($document) || !str_starts_with($document,'/')) return null;
        clearstatcache(true);
        $stat = @lstat($path);
        if (realpath($path) !== $path || $stat === false || ($stat['mode'] & 0170777) !== 0040700
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) return null;
        foreach ([realpath($document),realpath(ABSPATH)] as $web) {
            if ($web === false || $path === $web || str_starts_with($path,rtrim($web,'/').'/')) return null;
        }
        return $path;
    }

    private static function safeFile(string $path, string $dir): ?array
    {
        clearstatcache(true,$path);
        $stat = @lstat($path);
        return $stat !== false && ($stat['mode'] & 0170777) === 0100600 && $stat['nlink'] === 1
            && $stat['uid'] === fileowner($dir) && $stat['size'] <= 4096 ? $stat : null;
    }

    private static function matches($file, array $expected): bool
    {
        $opened=fstat($file);
        return $opened !== false && $opened['ino'] === $expected['ino'] && $opened['dev'] === $expected['dev'];
    }

    private static function locked(\Closure $work, string $failure): string
    {
        $dir=self::directory();
        if ($dir===null) return $failure;
        $path=$dir.'/connection.lock';
        $before=self::safeFile($path,$dir);
        $file=$before===null ? self::create($path) : @fopen($path,'r+b');
        if ($file===false) return $failure;
        try {
            $stat=self::safeFile($path,$dir);
            if ($stat===null || !self::matches($file,$stat) || !flock($file,LOCK_EX)) return $failure;
            return $work($dir);
        } catch (\Throwable) {
            return $failure;
        } finally {
            fclose($file);
        }
    }

    private static function create(string $path)
    {
        $prior=umask(0077);
        try { return @fopen($path,'xb'); } finally { umask($prior); }
    }

    private static function syncDir(string $dir): bool
    {
        $handle=@fopen($dir,'rb');
        if ($handle===false) return false;
        try { return @fsync($handle); } finally { fclose($handle); }
    }

    private static function pending(string $dir): bool
    {
        clearstatcache(true,$dir.'/'.self::PENDING);
        return @lstat($dir.'/'.self::PENDING)!==false;
    }

    private static function replace(string $dir,string $path,string $data): string
    {
        $tmp=$dir.'/.pending-'.bin2hex(random_bytes(16));
        $file=self::create($tmp);
        if ($file===false) return self::SAVE_FAILED;
        $marker=$dir.'/'.self::PENDING;
        try {
            $stat=self::safeFile($tmp,$dir);
            if ($stat===null || !self::matches($file,$stat) || fwrite($file,$data)!==strlen($data)
                || !fflush($file) || !fsync($file)) return self::SAVE_FAILED;

            // Commit the deny marker before attempting to publish new credential bytes.
            if (!self::writeMarker($dir,$marker)) return self::SAVE_FAILED;
            if (!@rename($tmp,$path)) {
                @unlink($marker);
                return self::SAVE_FAILED;
            }
            if (!self::syncDir($dir) || !@unlink($marker)) return self::UNCERTAIN;

            // The credential rename is already durable. Losing the marker removal
            // in a crash can only return the connection to quarantine.
            return 'CREDENTIAL_SAVED_HELD';
        } finally {
            fclose($file);
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private static function tokenValid(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9._~-]{16,512}$/D',$token)===1;
    }

    public static function save(array $scope,string $token,?\Closure $valid=null): string
    {
        if (!self::tokenValid($token)) return self::SAVE_FAILED;
        return self::locked(static function(string $dir) use ($scope,$token,$valid): string {
            if ($valid!==null && !$valid()) return self::SAVE_FAILED;
            if (self::pending($dir)) return self::UNCERTAIN;
            $path=$dir.'/'.self::CREDENTIAL;
            if (@lstat($path)!==false && self::safeFile($path,$dir)===null) return self::SAVE_FAILED;
            return self::replace($dir,$path,json_encode(['scope'=>$scope,'token'=>$token],JSON_THROW_ON_ERROR));
        },self::SAVE_FAILED);
    }

    public static function remove(?\Closure $valid=null): bool
    {
        return self::locked(static function(string $dir) use ($valid): string {
            if ($valid!==null && !$valid()) return 'FAILED';
            $path=$dir.'/'.self::CREDENTIAL;
            $paths=glob($dir.'/.pending-*');
            if ($paths===false) return 'FAILED';
            if (@lstat($path)!==false) $paths[]=$path;
            foreach ($paths as $file) {
                if (self::safeFile($file,$dir)===null || !@unlink($file)) return 'FAILED';
            }
            if (!self::syncDir($dir)) return 'FAILED';
            $marker=$dir.'/'.self::PENDING;
            if (!self::pending($dir)) return 'OK';
            return self::safeFile($marker,$dir)!==null && @unlink($marker)?'OK':'FAILED';
        },'FAILED')==='OK';
    }

    private static function token(string $dir,array $scope): ?string
    {
        if (self::pending($dir)) return null;
        $path=$dir.'/'.self::CREDENTIAL;
        $stat=self::safeFile($path,$dir);
        if ($stat===null || ($file=@fopen($path,'rb'))===false) return null;
        try {
            if (!self::matches($file,$stat)) return null;
            $raw=stream_get_contents($file,4097);
            if (!is_string($raw) || strlen($raw)!==$stat['size']) return null;
            $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            return ($data['scope']??null)===$scope && self::tokenValid($data['token']??null) ? $data['token'] : null;
        } finally { fclose($file); }
    }

    public static function state(array $scope): string
    {
        return self::locked(static fn(string $dir): string=>self::pending($dir) ? self::UNCERTAIN
            : (self::token($dir,$scope)===null ? 'CREDENTIAL_MISSING_OR_INVALID':'CREDENTIAL_HELD'),'PRIVATE_STORAGE_UNAVAILABLE');
    }

    private static function approval(array $scope,int $actor): ?array
    {
        $grant=defined('DIGIFORGE_PRINTIFY_SHOPS_PILOT') ? DIGIFORGE_PRINTIFY_SHOPS_PILOT : null;
        $now=time();
        if (!is_array($grant) || !is_string($grant['id']??null) || !preg_match('/^[a-f0-9]{32}$/D',$grant['id'])
            || !is_string($grant['profile_revision']??null) || $grant['profile_revision']!==AccountProfile::revisionToken()
            || ($grant['actor']??null)!==$actor || ($grant['site']??null)!==home_url('/')
            || !is_array($grant['scope']??null) || count($grant['scope'])!==count($scope)
            || !is_int($grant['issued']??null) || !is_int($grant['expires']??null) || $grant['issued']>$now
            || $grant['expires']<=$now || $grant['expires']-$grant['issued']>self::MAX_WINDOW) return null;
        foreach ($scope as $key=>$value) {
            if (($grant['scope'][$key]??null)!==$value) return null;
        }
        return $grant;
    }

    private static function claim(string $dir,string $id): bool
    {
        return self::writeMarker($dir,$dir.'/used-'.$id);
    }

    private static function writeMarker(string $dir,string $path): bool
    {
        $file=self::create($path);
        if ($file===false) return false;
        try {
            $stat=self::safeFile($path,$dir);
            return $stat!==null && self::matches($file,$stat) && fwrite($file,"consumed\n")===9
                && fflush($file) && fsync($file) && self::syncDir($dir);
        } finally { fclose($file); }
    }

    private static function authorized(array $profile,string $revision,int $actor,string $session): bool
    {
        return is_ssl() && $actor>0 && get_current_user_id()===$actor && $session!=='' && wp_get_session_token()===$session
            && \WP_Session_Tokens::get_instance($actor)->verify($session)
            && current_user_can('manage_options') && current_user_can('digiforge_printify_review_shop_'.$profile['shop'])
            && AccountProfile::current()===$profile && AccountProfile::revisionToken()===$revision;
    }

    private static function pilot(array $profile,string $revision,int $actor,string $session,string $id,?int &$status): string
    {
        return self::locked(static function(string $dir) use ($profile,$revision,$actor,$session,$id,&$status): string {
            $approval=self::approval($profile,$actor);
            if (($approval['id']??null)!==$id) return 'PILOT_EXPIRED_OR_UNAPPROVED';
            if (self::pending($dir)) return self::UNCERTAIN;
            $token=self::token($dir,$profile);
            if ($token===null) return 'CREDENTIAL_MISSING_OR_INVALID';
            $credential=self::safeFile($dir.'/'.self::CREDENTIAL,$dir);
            if ($credential===null || $credential['mtime'] >= $approval['issued']) return 'PILOT_EXPIRED_OR_UNAPPROVED';
            if (@lstat($dir.'/used-'.$id)!==false) return 'PILOT_ALREADY_USED';
            $valid=static fn(): bool=>self::authorized($profile,$revision,$actor,$session)
                && self::approval($profile,$actor)===$approval;
            if (!$valid()) return 'OPERATOR_OR_SCOPE_CHANGED';
            $scope=$profile+['site'=>'wp-'.hash('sha256',home_url('/')),'actor'=>$actor,
                'session'=>hash_hmac('sha256',$session,wp_salt('auth')),'revision'=>$revision];
            $request=ProtectedGet::request($scope,'SHOPS_READ',[]);
            if (!self::claim($dir,$id) || !$valid()) return 'PILOT_NOT_ADMITTED';
            $snapshot=ProtectedGetWorkspace::snapshot(true);
            if ($snapshot===null || !ProtectedGetWorkspace::reserveDirect($scope,$snapshot['revision'],$valid)) return 'PILOT_BUDGET_OR_STORAGE_UNAVAILABLE';
            return self::sendShops($request,$token,$valid,$status);
        },'PRIVATE_STORAGE_OR_PILOT_UNAVAILABLE');
    }

    private static function sendShops(array $request,string $token,\Closure $valid,?int &$status): string
    {
        if ($request['path']!=='/v1/shops.json' || !$valid()) return 'OPERATOR_OR_SCOPE_CHANGED';
        try {
            $response=wp_safe_remote_get('https://api.printify.com/v1/shops.json',[
                'method'=>'GET','sslverify'=>true,'reject_unsafe_urls'=>true,'redirection'=>0,'timeout'=>5,
                'limit_response_size'=>262144,'user-agent'=>'DigiForge Printify API',
                'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],
            ]);
        } catch (\Throwable) {
            return $valid()?'PILOT_TRANSPORT_ERROR':'OPERATOR_OR_SCOPE_CHANGED';
        }
        if (!$valid()) return 'OPERATOR_OR_SCOPE_CHANGED';
        if (is_wp_error($response)) return 'PILOT_TRANSPORT_ERROR';
        if (!is_array($response)) return 'PILOT_UNEXPECTED_RESPONSE';
        $code=wp_remote_retrieve_response_code($response);
        if (is_string($code) && preg_match('/^[1-5][0-9]{2}$/D',$code)) $code=(int)$code;
        if (!is_int($code) || $code<100 || $code>599) return 'PILOT_UNEXPECTED_RESPONSE';
        $status=$code;
        if ($code===401) return 'PILOT_UNAUTHORIZED';
        if ($code===403) return 'PILOT_FORBIDDEN';
        if ($code===429) return 'PILOT_RATE_LIMITED';
        if ($code>=500) return 'PILOT_PROVIDER_ERROR';
        if ($code!==200) return 'PILOT_UNEXPECTED_RESPONSE';
        if (!preg_match('/^application\/json(?:\s*;|$)/i',(string)wp_remote_retrieve_header($response,'content-type'))) {
            return 'PILOT_MALFORMED_RESPONSE';
        }
        $raw=wp_remote_retrieve_body($response);
        if (!is_string($raw) || strlen($raw)>262144 || str_contains($raw,$token)) return 'PILOT_MALFORMED_RESPONSE';
        try {
            ProtectedGet::project($request,$raw);
            return 'PILOT_SHOP_CONFIRMED';
        } catch (\InvalidArgumentException $error) {
            // This is a fixed message from the local Shops response validator.
            return $error->getMessage()==='Configured shop absent from account.'
                ? 'PILOT_SHOP_NOT_CONFIRMED' : 'PILOT_MALFORMED_RESPONSE';
        } catch (\Throwable) {
            return 'PILOT_MALFORMED_RESPONSE';
        }
    }

    public static function render(array $post): void
    {
        if (!self::enabled() || !current_user_can('manage_options')) return;
        echo '<section><h2>Printify connection</h2>';
        $profile=AccountProfile::current();
        $revision=AccountProfile::revisionToken();
        $actor=get_current_user_id();
        $session=wp_get_session_token();
        if ($profile===null || $revision===null || !self::authorized($profile,$revision,$actor,$session)) {
            echo '<p>Use HTTPS and sign in as an administrator assigned to the configured shop.</p></section>';
            return;
        }
        $action=$post[self::ACTION]??null;
        $grant=self::approval($profile,$actor);
        if (is_string($action) && in_array($action,['save','disconnect','pilot'],true)) {
            $nonce=$post[self::ACTION.'_nonce']??null;
            $formRevision=$post[self::ACTION.'_revision']??null;
            $id=$action==='pilot' ? ($post[self::ACTION.'_approval']??null) : '';
            $result='OPERATOR_OR_SCOPE_CHANGED';
            $httpStatus=null;
            if ($formRevision===$revision && is_string($id) && is_string($nonce)
                && wp_verify_nonce($nonce,self::ACTION.':'.$action.':'.$revision.':'.$id)) {
                $valid=static fn(): bool=>self::authorized($profile,$revision,$actor,$session);
                if ($action==='save') {
                    $token=$post[self::ACTION.'_token']??null;
                    $result=is_string($token)?self::save($profile,$token,$valid):self::SAVE_FAILED;
                    unset($token);
                } elseif ($action==='disconnect') {
                    $result=self::remove($valid)?'CREDENTIAL_REMOVED':'CREDENTIAL_REMOVE_FAILED';
                } else {
                    $result=self::pilot($profile,$revision,$actor,$session,$id,$httpStatus);
                }
            }
            echo '<p role="status">'.esc_html(self::message($result)).'</p>';
            if ($action==='pilot') {
                $receipt=['schema'=>1,'operation'=>'SHOPS_READ','outcome'=>$result,'http_status'=>$httpStatus];
                echo '<details><summary>Diagnostic receipt</summary><pre>'
                    .esc_html(json_encode($receipt,JSON_THROW_ON_ERROR)).'</pre></details>';
            }
        }
        $state=self::state($profile);
        echo '<p>'.esc_html(self::message($state)).'</p>';
        echo '<p>Shop '.esc_html($profile['shop']).'. Use a scoped read-only token. Saving a token leaves the connection held.</p>';
        $spent=$grant!==null && self::locked(static fn(string $dir): string=>@lstat($dir.'/used-'.$grant['id'])!==false?'YES':'NO','YES')==='YES';
        if ($spent) echo '<p>This test approval is consumed. Connection held.</p>';
        foreach (['save'=>'Save token','pilot'=>'Test one Shops request','disconnect'=>'Disconnect'] as $kind=>$label) {
            if ($kind==='pilot' && ($grant===null || $spent || $state!=='CREDENTIAL_HELD')) continue;
            $id=$kind==='pilot'?$grant['id']:'';
            echo '<form method="post" autocomplete="off">';
            wp_nonce_field(self::ACTION.':'.$kind.':'.$revision.':'.$id,self::ACTION.'_nonce');
            echo '<input type="hidden" name="'.self::ACTION.'_revision" value="'.esc_attr($revision).'">';
            if ($kind==='save') echo '<label>Printify token <input type="password" required name="'.self::ACTION.'_token" autocomplete="new-password" minlength="16" maxlength="512"></label> ';
            if ($kind==='pilot') echo '<input type="hidden" name="'.self::ACTION.'_approval" value="'.esc_attr($id).'">';
            echo '<button class="button" name="'.self::ACTION.'" value="'.esc_attr($kind).'">'.esc_html($label).'</button></form>';
        }
        if ($grant===null) {
            echo '<p>The connection test requires a current, separately issued server approval.</p>';
            echo '<details><summary>Approval details</summary><p>Administrator ID '.esc_html((string)$actor)
                .' · Profile revision <code>'.esc_html($revision).'</code></p></details>';
        }
        echo '<p>After disconnecting, revoke the token in your Printify account. Disconnect only removes the local file.</p></section>';
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'CREDENTIAL_HELD','CREDENTIAL_SAVED_HELD'=>'Token stored privately. Connection held.',
            'CREDENTIAL_MISSING_OR_INVALID'=>'No valid token is stored for this shop profile.',
            'CREDENTIAL_REMOVED'=>'Local token removed. Revoke it in Printify.',
            self::SAVE_FAILED=>'Token was not saved. Check the token format and private storage permissions.',
            self::UNCERTAIN=>'Token persistence is uncertain. Tests and replacement are blocked. Disconnect, remove the server approval, and revoke this token in Printify before setting up again.',
            'CREDENTIAL_REMOVE_FAILED'=>'Token cleanup could not be confirmed. Revoke it in Printify and check private storage.',
            'PILOT_SHOP_CONFIRMED'=>'Printify returned the assigned shop. The connection is held again.',
            'PILOT_UNAUTHORIZED'=>'Printify returned HTTP 401. Verify the token was issued for the intended merchant account and is still valid. The connection is held.',
            'PILOT_FORBIDDEN'=>'Printify returned HTTP 403. Verify shops.read and merchant account access before requesting another approval. The connection is held.',
            'PILOT_TRANSPORT_ERROR'=>'WordPress could not complete the HTTPS request. Ask the hosting operator to check outbound HTTPS and TLS without capturing secrets. The connection is held.',
            'PILOT_PROVIDER_ERROR'=>'Printify returned a server error. Check provider availability before requesting another approval. The connection is held.',
            'PILOT_UNEXPECTED_RESPONSE'=>'Printify returned an unexpected HTTP response. Share only the diagnostic receipt. The connection is held.',
            'PILOT_MALFORMED_RESPONSE'=>'Printify returned an unexpected response format. Share only the diagnostic receipt. The connection is held.',
            'PILOT_NOT_ADMITTED'=>'The local approval could not be consumed safely. Check private storage before requesting another approval. The connection is held.',
            'PILOT_BUDGET_OR_STORAGE_UNAVAILABLE'=>'The local request budget or observation storage prevented dispatch. The connection is held.',
            'PILOT_RATE_LIMITED'=>'Printify rate-limited the test. The connection is held.',
            'PILOT_SHOP_NOT_CONFIRMED'=>'Printify did not confirm the assigned shop. Check the account and shop. The connection is held.',
            'PILOT_ALREADY_USED'=>'This approval has already been used. The connection is held.',
            'PILOT_EXPIRED_OR_UNAPPROVED'=>'The approval is missing, expired or for a different scope. The connection is held.',
            'OPERATOR_OR_SCOPE_CHANGED'=>'Your session or shop profile changed. Reload the page.',
            'PRIVATE_STORAGE_UNAVAILABLE','PRIVATE_STORAGE_OR_PILOT_UNAVAILABLE'=>'Private connection storage is unavailable. Check the configured directory and permissions.',
            default=>'The test could not complete. The connection is held. Review the configuration before requesting a new approval.',
        };
    }
}
