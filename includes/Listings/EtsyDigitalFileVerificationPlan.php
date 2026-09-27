<?php
declare(strict_types=1);
namespace DigiForge\Listings;

/** Builds a GET-only verification plan for an already confirmed Etsy file upload. */
final class EtsyDigitalFileVerificationPlan {
 /** @return array<string,mixed> */
 public static function build(int $integrationId,string $shopId,string $listingId,string $fileId,string $name,int $rank,int $size):array {
  $valid=$integrationId>0&&ctype_digit($shopId)&&(int)$shopId>0&&ctype_digit($listingId)&&(int)$listingId>0&&ctype_digit($fileId)&&(int)$fileId>0&&$name!==''&&$rank>0&&$size>0;
  return ['state'=>$valid?'ETSY_FILE_VERIFICATION_PLANNED':'REVIEW_REQUIRED','integration_id'=>$integrationId,'method'=>'GET','endpoint'=>$valid?'/application/shops/'.$shopId.'/listings/'.$listingId.'/files':'','listing_id'=>$listingId,'listing_file_id'=>$fileId,'expected'=>['name'=>$name,'rank'=>$rank,'file_size'=>$size],'mutation_permitted'=>false,'publish_permitted'=>false,'automatic_retry_permitted'=>false,'network_request_permitted'=>false,'external_execution_performed'=>false];
 }
}
