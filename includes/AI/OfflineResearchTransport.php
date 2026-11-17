<?php
declare(strict_types=1);
namespace DigiForge\AI;
use WP_Error;
/** Final server-injected simulation. No callback, credential access or HTTP operation is available. */
final class OfflineResearchTransport {
 private int $startCount=0;private int $pollCount=0;
 public function __construct(private array|WP_Error $result){}
 public function start(array $contract):array|WP_Error {$this->startCount++;if($this->result instanceof WP_Error)return $this->result;return ['response_id'=>'resp_synthetic_'.substr(hash('sha256',$contract['request_sha256'].'|'.$this->startCount),0,40),'status'=>'queued','provider_response_sha256'=>hash('sha256','synthetic queued '.$contract['request_sha256'])];}
 public function poll(string $id,array $contract):array|WP_Error {$this->pollCount++;if($this->result instanceof WP_Error)return $this->result;return $this->result+['response_id'=>$id,'provider_response_sha256'=>hash('sha256',\DigiForge\Orders\Validator::canonicalJson($this->result))];}
 public function starts():int{return $this->startCount;}
 public function polls():int{return $this->pollCount;}
}
