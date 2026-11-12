<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
use DigiForge\POD\PrintifyConnectorReadContract;
/** Immutable GET intent. Construction prepares no authority and performs no HTTP. */
final readonly class ExternalOperationIntent {
 public function __construct(public int $actorId,public string $shopKey,public int $integrationId,public string $operation,public array $input,public string $intentId,public array $sourceReferences=[]){
  if($actorId<1||$integrationId<1||!in_array($shopKey,['personalized_pod','standard_pod'],true)||!preg_match('/^[a-f0-9]{32}$/D',$intentId)||strlen(json_encode($input,JSON_THROW_ON_ERROR))>4096)throw new \InvalidArgumentException('Exact actor, shop and bounded read intent required.');
  if(!array_is_list($sourceReferences)||count($sourceReferences)>10||count(array_unique($sourceReferences,SORT_REGULAR))!==count($sourceReferences)||($sourceReferences&&!in_array($operation,['UPLOAD_READ','UPLOADS_READ'],true)))throw new \InvalidArgumentException('Owned source references malformed.');foreach($sourceReferences as $ref)if(!is_string($ref)||!preg_match('/^[a-f0-9]{64}$/D',$ref))throw new \InvalidArgumentException('Owned receipt hash required.');
  // Input grammar only; the authoritative provider identity is resolved by the host later.
  PrintifyConnectorReadContract::prepare($operation,'1',$input);
 }
}
