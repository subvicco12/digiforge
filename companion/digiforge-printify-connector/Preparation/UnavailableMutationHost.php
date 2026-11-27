<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
/** No native Stage3/4 port is assumed. No database, vault or transport access. */
final class UnavailableMutationHost implements CoreMutationHost {
 private function error():\WP_Error{return new \WP_Error('printify_mutation_host_unavailable','Reviewed DigiForge mutation host integration is unavailable.',['status'=>409,'network_request_permitted'=>false]);}
 public function authorize(MutationIntent $i):array|\WP_Error{return $this->error();}
 public function register(MutationIntent $i):array|\WP_Error{return $this->error();}
 public function claimOnce(MutationIntent $i,array $a):array|\WP_Error{return $this->error();}
 public function responseEvidence(MutationIntent $i,string $r):array|\WP_Error{return $this->error();}
 public function outcome(MutationIntent $i,string $s,array $r):bool{return false;}
}
