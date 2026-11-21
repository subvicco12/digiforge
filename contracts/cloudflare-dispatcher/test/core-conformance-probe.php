<?php
declare(strict_types=1);
// Fixed local test probe. Frozen actual core classes, no WordPress stubs or network.
try {
 $base=__DIR__.'/core-reference/';$bytes=file_get_contents($base.'manifest.json');
 if(!is_string($bytes)||hash('sha256',$bytes)!=='e05a26b72967b26eb4106bc7c1a861ce1193261d5c01bc2d7f575b4071d67e4c')throw new RuntimeException();
 $m=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
 if($m['source_sha']!=='78e7f973455a3c89319be06f0907caf2103658a7'||count($m['files'])!==3)throw new RuntimeException();
 foreach(['NativePortContract.php','NativeHeldJournalState.php','native-core-port.v1.json']as $name){$b=file_get_contents($base.$name);if(!is_string($b)||hash('sha256',$b)!==$m['files'][$name]['sha256']||sha1('blob '.strlen($b)."\0".$b)!==$m['files'][$name]['git_blob'])throw new RuntimeException();}
 require $base.'NativePortContract.php';require $base.'NativeHeldJournalState.php';
 require __DIR__.'/../NativeObservationConformance.php';
 $raw=stream_get_contents(STDIN,65537);if(strlen($raw)>65536)throw new RuntimeException();$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 $contract=DigiForge\Connector\Printify\NativePortContract::class;$state=DigiForge\Connector\Printify\NativeHeldJournalState::class;
 $result=match($v['mode']??null){
  'compare'=>DigiForge\IndependentAcceptance\NativeObservationConformance::compare($v['manifest'],$v['inspection'],$v['binding'],$v['observation'],$v['observer_binding'],$v['now'],$v['generation']),
  'objects'=>(static function()use($v):array{$calls=0;$object=new class($calls) implements JsonSerializable{private $calls;public function __construct(int &$calls){$this->calls=&$calls;}public function jsonSerialize():mixed{++$this->calls;return 'SYNTHETIC-PRIVATE-SERIALIZER';}};if(($v['object_slot']??null)==='core')$v['inspection']['observation']=$object;elseif(($v['object_slot']??null)==='observer')$v['observation']['observation_hash']=$object;else throw new RuntimeException();$result=DigiForge\IndependentAcceptance\NativeObservationConformance::compare($v['manifest'],$v['inspection'],$v['binding'],$v['observation'],$v['observer_binding'],$v['now'],$v['generation']);$result['serializer_calls']=$calls;return $result;})(),
  'contract'=>['binding_valid'=>$contract::valid('binding',$v['binding']),'observation_is_mutation_response'=>$contract::valid('mutation_response',$v['observation']),'fabricated_shape_valid'=>$contract::valid('mutation_response',['contract_version'=>'1.0.0','action'=>'REGISTER','binding'=>$v['binding'],'recovery_epoch'=>$v['observation']['recovery_epoch'],'checkpoint_epoch'=>$v['observation']['checkpoint_epoch'],'journal_revision'=>1,'data'=>[],'journal_hash'=>str_repeat('f',64)]),'actions'=>$contract::specification()['$defs']['mutation_request']['properties']['action']['enum']],
  'held'=>(static function()use($state,$v):array{$held=$state::prepare($v['binding'],str_repeat('d',64),1);$fenced=$state::advance($held,'FENCE',1,2,str_repeat('e',64));$unknown=$state::advance($fenced,'UNKNOWN',2,3);$denied=[];foreach(['FENCE','CANCEL','RESET','SUCCESS','RECOVER','UNKNOWN']as $a){try{$state::advance($unknown,$a,3,99);$denied[$a]=false;}catch(InvalidArgumentException){$denied[$a]=true;}}return ['state'=>$unknown['state'],'dispatch_permitted'=>$unknown['dispatch_permitted'],'independent_checkpoint_verified'=>$unknown['independent_checkpoint_verified'],'retry_denied'=>$denied,'restored_held_can_fence'=>$state::advance($held,'FENCE',1,3,str_repeat('f',64))['state']==='FENCED'];})(),
  default=>throw new RuntimeException()
 };
 echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable){fwrite(STDERR,"CONFORMANCE_PROBE_DENIED\n");exit(1);}
