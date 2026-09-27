<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;
/** Pure bounded search over an authenticated Etsy seller-taxonomy response. */
final class EtsySellerTaxonomyDiscovery
{
    public static function search(array $response,array $terms,int $limit=20): array|WP_Error
    {
        $terms=array_values(array_unique(array_filter(array_map(static fn($v)=>strtolower(trim((string)$v)),$terms),static fn($v)=>$v!=='')));
        if($terms===[]) return new WP_Error('digiforge_etsy_taxonomy_discovery_terms','At least one taxonomy search term is required.',['status'=>400]);
        $limit=max(1,min(50,$limit));
        $results=is_array($response['results']??null)?$response['results']:[];
        $matches=[]; self::walk($results,[],$terms,$matches,$limit);
        return ['terms'=>$terms,'count'=>count($matches),'candidates'=>$matches];
    }
    private static function walk(array $nodes,array $path,array $terms,array &$matches,int $limit): void
    {
        foreach($nodes as $node){
            if(count($matches)>=$limit) return;
            if(!is_array($node)) continue;
            $id=(int)($node['id']??0); $name=trim((string)($node['name']??''));
            $next=$name===''?$path:array_merge($path,[$name]);
            $haystack=strtolower(implode(' > ',$next));
            $score=0; foreach($terms as $term){ if(str_contains($haystack,$term)) $score++; }
            if($id>0&&$name!==''&&$score>0) $matches[]=['taxonomy_id'=>$id,'name'=>$name,'path'=>$next,'term_matches'=>$score];
            $children=is_array($node['children']??null)?$node['children']:[];
            self::walk($children,$next,$terms,$matches,$limit);
        }
    }
}