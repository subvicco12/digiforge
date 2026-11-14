<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Immutable snapshot of DigiForge-owned, independently reviewed scope. */
final readonly class ShopBinding {
 public function __construct(public int $shopId,public string $shopKey,public int $integrationId,public string $providerShopId,public string $environment,public int $revision,public CredentialReference $credential,public string $bindingHash,public int $expiresAt,public array $ownedUploads=[]){
  if((['personalized_pod'=>2,'standard_pod'=>3][$shopKey]??0)!==$shopId||$shopId<1||$integrationId<1||$credential->integrationId!==$integrationId||!preg_match('/^[1-9][0-9]{0,18}$/D',$providerShopId)||!in_array($environment,['production','test','sandbox'],true)||$revision<1||!preg_match('/^[a-f0-9]{64}$/D',$bindingHash)||$expiresAt<1)throw new \InvalidArgumentException('Canonical reviewed shop binding required.');
  if(!array_is_list($ownedUploads)||count($ownedUploads)>10||count(array_unique($ownedUploads,SORT_REGULAR))!==count($ownedUploads))throw new \InvalidArgumentException('Exact owned image references required.');foreach($ownedUploads as $id)if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id))throw new \InvalidArgumentException('Image reference malformed.');
 }
 public function fingerprint():string{return hash('sha256',json_encode([$this->shopId,$this->shopKey,$this->integrationId,$this->providerShopId,$this->environment,$this->revision,$this->credential->integrationId,$this->bindingHash,$this->expiresAt,$this->ownedUploads],JSON_THROW_ON_ERROR));}
}
