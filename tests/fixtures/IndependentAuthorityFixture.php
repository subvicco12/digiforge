<?php
declare(strict_types=1);
use DigiForge\IndependentAcceptance\{AtomicState,ProviderPort,EvidenceSigner,Authority,Protocol};

/** Synthetic transactional service; never connects to a database or provider. */
final class IndependentFixtureStore implements AtomicState {
 public array $s=[];public bool $available=true,$loseCommitAck=false;public mixed $before=null;private int $calls=0;
 public function transaction(callable $work):mixed {
  if(!$this->available)throw new RuntimeException('Offline');$this->calls++;if($this->before!==null)($this->before)($this->calls);
  $copy=$this->s;$r=$work($copy);if(!$this->available)throw new RuntimeException('Offline');$this->s=$copy;
  if($this->loseCommitAck){$this->loseCommitAck=false;throw new RuntimeException('Lost acknowledgement');}return $r;
 }
}
final class IndependentFixtureProvider implements ProviderPort {
 public int $calls=0;public bool $credentialAvailable=true,$failure=false;public mixed $during=null;
 public array $response=['status'=>200,'body'=>'[{"id":28930593,"buyer":"buyer-private"}]'];
 public function credentialAvailable(string $binding):bool {return $this->credentialAvailable;}
 public function get(string $endpoint,int $timeout,int $maximumBytes):array {$this->calls++;if($this->during!==null)($this->during)();if($this->failure)throw new RuntimeException('sensitive-exception');return $this->response;}
}
final class IndependentFixtureSigner implements EvidenceSigner {
 public bool $available=true;
 public function __construct(private string $key){}
 public function sign(string $bytes):string {if(!$this->available)throw new RuntimeException('Offline');return base64_encode(sodium_crypto_sign_detached($bytes,$this->key));}
}
final class IndependentAuthorityFixture {
 public Authority $a;public IndependentFixtureStore $store;public IndependentFixtureProvider $provider;public IndependentFixtureSigner $signer;public array $config;private string $readKey,$stopKey;
 public function __construct(){
  $read=sodium_crypto_sign_keypair();$stop=sodium_crypto_sign_keypair();$receipt=sodium_crypto_sign_keypair();$this->readKey=sodium_crypto_sign_secretkey($read);$this->stopKey=sodium_crypto_sign_secretkey($stop);
  $this->config=['authority'=>'synthetic-external-service','epoch'=>str_repeat('e',64),'account'=>'synthetic-account','credential_binding'=>str_repeat('a',64),'read_key_id'=>'read-v1','read_public_key'=>sodium_crypto_sign_publickey($read),'stop_key_id'=>'stop-v1','stop_public_key'=>sodium_crypto_sign_publickey($stop),'receipt_public_key'=>sodium_crypto_sign_publickey($receipt),'exclusive_credential_custody'=>true,'independent_recovery_epoch'=>true,'qualified'=>true];
  $this->store=new IndependentFixtureStore();$this->provider=new IndependentFixtureProvider();$this->signer=new IndependentFixtureSigner(sodium_crypto_sign_secretkey($receipt));
  $request=['operation'=>'SHOPS_READ','method'=>'GET','endpoint'=>'/v1/shops.json','body'=>[]];$proof=str_repeat('f',64);$review=str_repeat('d',64);
  $this->store->s=['epoch'=>$this->config['epoch'],'generation'=>1,'stopped'=>false,'owner_proof'=>$proof,'review'=>$review,'claims'=>[],'nonces'=>[],'stop_nonces'=>[],'audit'=>[],'receipts'=>[],'times'=>[],'pause_until'=>0,'catalog_grants'=>[],'sessions'=>[str_repeat('a',32)=>['revoked'=>false,'expires_at'=>1300,'owner_proof'=>$proof,'review'=>$review,'slots'=>array_fill(0,10,hash('sha256',Protocol::canonical($request)))]]];$this->a=$this->authority($this->config);
 }
 public function authority(array $config):Authority {return new Authority($config,$this->store,$this->provider,$this->signer,static fn()=>1000);}
 private function envelope(array $m,bool $stop=false):array {$id=$this->config[$stop?'stop_key_id':'read_key_id'];return ['key_id'=>$id,'manifest'=>$m,'signature'=>base64_encode(sodium_crypto_sign_detached(Protocol::canonical(['key_id'=>$id,'manifest'=>$m]),$stop?$this->stopKey:$this->readKey))];}
 public function request(array $overrides=[]):array {return $this->envelope(array_replace(['version'=>1,'protocol'=>'printify-independent-get-v1','authority'=>$this->config['authority'],'epoch'=>$this->config['epoch'],'generation'=>1,'session'=>str_repeat('a',32),'nonce'=>str_repeat('a',32),'slot'=>0,'actor'=>1,'owner_proof'=>str_repeat('f',64),'review'=>str_repeat('d',64),'shop_key'=>'personalized_pod','internal_shop_id'=>2,'integration_id'=>3,'account'=>$this->config['account'],'provider_shop_id'=>'28930593','credential_binding'=>$this->config['credential_binding'],'issued_at'=>1000,'expires_at'=>1300,'owner_verified_at'=>1000,'request'=>['operation'=>'SHOPS_READ','method'=>'GET','endpoint'=>'/v1/shops.json','body'=>[]],'catalog_grant'=>null,'contract_sha256'=>Protocol::CONTRACT_SHA256],$overrides));}
 public function stop(array $overrides=[]):array {return $this->envelope(array_replace(['version'=>1,'protocol'=>'printify-independent-stop-v1','authority'=>$this->config['authority'],'epoch'=>$this->config['epoch'],'actor'=>1,'nonce'=>str_repeat('b',32),'owner_proof'=>str_repeat('f',64),'review'=>str_repeat('d',64),'issued_at'=>1000,'expires_at'=>1060],$overrides),true);}
 public function legacyCaller(array $request,array $restoredHost):array {return $this->a->dispatch($request);}
}
