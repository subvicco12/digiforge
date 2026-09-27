<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;
/** Pure verifier for a read-only Etsy seller-taxonomy response. */
final class EtsySellerTaxonomyVerifier
{
    public static function verify(array $response,int $taxonomyId): array|WP_Error
    {
        if($taxonomyId<1) return self::error('id','A positive Etsy seller taxonomy id is required.');
        $results=is_array($response['results']??null)?$response['results']:[];
        $found=self::find($results,$taxonomyId,[]);
        if($found===null) return self::error('not_found','The requested taxonomy id was not present in the verified Etsy seller taxonomy tree.');
        return ['verified'=>true,'taxonomy_id'=>$taxonomyId,'name'=>(string)($found['name']??''),'path'=>$found['_path']];
    }
    private static function find(array $nodes,int $id,array $path): ?array
    {
        foreach($nodes as $node){
            if(!is_array($node)) continue;
            $name=trim((string)($node['name']??'')); $next=$name===''?$path:array_merge($path,[$name]);
            if((int)($node['id']??0)===$id){$node['_path']=$next;return $node;}
            $children=is_array($node['children']??null)?$node['children']:[];
            $hit=self::find($children,$id,$next); if($hit!==null)return $hit;
        }
        return null;
    }
    private static function error(string $code,string $message): WP_Error { return new WP_Error('digiforge_etsy_taxonomy_'.$code,$message,['status'=>409]); }
}
