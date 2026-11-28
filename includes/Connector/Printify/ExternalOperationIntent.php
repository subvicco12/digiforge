<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\POD\PrintifyConnectorReadContract;
/** Immutable GET intent. Construction prepares no authority and performs no HTTP. */
final readonly class ExternalOperationIntent {
 public int $actorId;public string $shopKey;public int $integrationId;public string $operation;public array $input;public string $intentId;public array $sourceReferences;
 public function __construct(int $actorId,string $shopKey,int $integrationId,string $operation,array $input,string $intentId,array $sourceReferences=[]){
  if($actorId<1||$integrationId<1||!in_array($shopKey,['personalized_pod','standard_pod'],true)||!preg_match('/^[a-f0-9]{32}$/D',$intentId))throw new \InvalidArgumentException('Exact actor, shop and bounded read intent required.');
  if(!array_is_list($sourceReferences)||count($sourceReferences)>10||($sourceReferences&&!in_array($operation,['UPLOAD_READ','UPLOADS_READ'],true)))throw new \InvalidArgumentException('Owned source references malformed.');
  $detachedReferences=[];foreach($sourceReferences as $ref){if(!is_string($ref)||!preg_match('/^[a-f0-9]{64}$/D',$ref))throw new \InvalidArgumentException('Owned receipt hash required.');$detachedReferences[]=$ref;}
  if(count(array_unique($detachedReferences,SORT_STRING))!==count($detachedReferences))throw new \InvalidArgumentException('Owned source references malformed.');
  // Input grammar only; the authoritative provider identity is resolved by the host later.
  // The current fixed grammar permits only flat strings/integers. Validate it
  // before JSON encoding, so rejected objects cannot execute serialization hooks.
  PrintifyConnectorReadContract::prepare($operation,'1',$input);
  $detached=[];foreach($input as $key=>$value)$detached[$key]=$value;
  if(strlen(json_encode($detached,JSON_THROW_ON_ERROR))>4096)throw new \InvalidArgumentException('Bounded read intent required.');
  $this->actorId=$actorId;$this->shopKey=$shopKey;$this->integrationId=$integrationId;$this->operation=$operation;$this->input=$detached;$this->intentId=$intentId;$this->sourceReferences=$detachedReferences;
 }
}
