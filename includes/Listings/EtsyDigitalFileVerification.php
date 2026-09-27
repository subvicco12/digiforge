<?php
declare(strict_types=1);
namespace DigiForge\Listings;
use WP_Error;

/** Pure comparison for a known successful Etsy digital-file identity. */
final class EtsyDigitalFileVerification {
 /** @return array<string,mixed>|WP_Error */
 public static function compare(array $provider,string $listingId,string $fileId,string $name,int $rank,int $size):array|WP_Error {
  if(!ctype_digit($listingId)||!ctype_digit($fileId)||(int)$listingId<1||(int)$fileId<1||$name===''||$rank<1||$size<1)return new WP_Error('digiforge_etsy_file_verify_scope','Persisted listing/file identity and expected metadata are required.',['status'=>409]);
  $files=is_array($provider['results']??null)?$provider['results']:[];
  $matches=[];
  foreach($files as $file){
   if(!is_array($file))continue;
   if(hash_equals($listingId,trim((string)($file['listing_id']??'')))&&hash_equals($fileId,trim((string)($file['listing_file_id']??'')))&&hash_equals($name,(string)($file['filename']??''))&&$rank===(int)($file['rank']??0)&&$size===(int)($file['size_bytes']??0))$matches[]=$file;
  }
  return ['state'=>count($matches)===1?'VERIFIED':'REVIEW_REQUIRED','listing_id'=>$listingId,'listing_file_id'=>$fileId,'exact_match_count'=>count($matches),'mutation_performed'=>false,'publish_permitted'=>false,'external_execution_performed'=>false];
 }
}
