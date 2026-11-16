<?php
declare(strict_types=1);
namespace DigiForge\AI;
use WP_Error;
/** Server-injected, final offline transport. Never reads a credential or calls any HTTP function. */
final class OfflineImageTransport
{
 private int $count=0;private array $requests=[];
 public function __construct(private array|WP_Error $response){}
 public function send(array $wire,array $options):array|WP_Error {
  if(!in_array($wire['url']??null,['https://api.openai.com/v1/images/generations','https://api.openai.com/v1/images/edits'],true)||($options['redirection']??null)!==0||($options['sslverify']??null)!==true||isset($options['headers']['Authorization']))return new WP_Error('image_simulator_contract','Exact credential-free offline request required.');
  $this->count++;$this->requests[]=['url'=>$wire['url'],'body_sha256'=>hash('sha256',$wire['body']),'request_sha256'=>$wire['request_sha256'],'timeout'=>$options['timeout'],'content_type'=>$wire['content_type'],'limit_response_size'=>$options['limit_response_size']];return $this->response;
 }
 public function calls():int{return $this->count;}
 public function requests():array{return $this->requests;}
}
