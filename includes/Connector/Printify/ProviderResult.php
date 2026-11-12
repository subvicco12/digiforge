<?php
declare(strict_types=1);
namespace DigiForge\Connector\Printify;
/** Public projection only; never a raw provider response or credential container. */
final readonly class ProviderResult implements \JsonSerializable {
 public function __construct(public array $data,public array $receipt){}
 public function jsonSerialize():array{return ['interface_version'=>'1.0.0','data'=>$this->data,'receipt'=>$this->receipt,'read_only'=>true,'mutation_authorized'=>false];}
}
