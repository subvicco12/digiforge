<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** Reference-only import. Raw provider bodies and self-hashed caller claims are never trusted imports. */
final class ReceiptImporter {
 public function __construct(private VersionedMutationHost $host){}
 public function import(MutationIntent $i,string $json):array|\WP_Error {
  try{
   if(strlen($json)>2048)throw new \InvalidArgumentException();$t=json_decode($json,true,8,JSON_THROW_ON_ERROR);
   $expected=['schema'=>'DF_TRUSTED_RECEIPT_IMPORT_1','operation_id'=>$i->operationId,'scope_fingerprint'=>$i->scope->fingerprint(),'payload_fingerprint'=>$i->payloadFingerprint];
   if(!is_array($t)||array_diff(array_keys($t),array_merge(array_keys($expected),['receipt_hash'])))throw new \InvalidArgumentException();foreach($expected as $k=>$v)if(($t[$k]??null)!==$v)throw new \InvalidArgumentException();
   if(!is_string($t['receipt_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$t['receipt_hash']))throw new \InvalidArgumentException();
   return $this->host->importReceipt($i,$t['receipt_hash']);
  }catch(\Throwable){return new \WP_Error('printify_receipt_import_denied','Exact trusted core receipt reference required.',['retry_permitted'=>false,'network_request_permitted'=>false]);}
 }
}
