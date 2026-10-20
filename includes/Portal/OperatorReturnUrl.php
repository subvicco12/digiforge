<?php
declare(strict_types=1);
namespace DigiForge\Portal;
/** Return only to this site's portal page, retaining no submitted evidence in URLs. */
final class OperatorReturnUrl
{
    public static function current():string{$id=get_queried_object_id();$url=$id>0?get_permalink($id):home_url('/');return is_string($url)?$url:home_url('/');}
    public static function resolve(mixed $value):string
    {
        $fallback=home_url('/');if(!is_string($value)||strlen($value)>2048)return $fallback;$url=wp_validate_redirect($value,$fallback);$home=wp_parse_url($fallback);$target=wp_parse_url($url);
        if(!is_array($target)||!is_array($home)||($target['host']??null)!==($home['host']??null)||($target['scheme']??null)!==($home['scheme']??null)||($target['port']??null)!==($home['port']??null)||isset($target['user'])||isset($target['pass']))return $fallback;
        return remove_query_arg(['df_message','df_error','_wpnonce'],$url);
    }
}
