<?php
declare(strict_types=1);
namespace DigiForgePrintifyConnector\Preparation;
use DigiForge\Connector\Printify\ShopBinding;
/** Authorized observation only. No journal repair, lease release, retry or execution. */
final class RecoveryDiagnostics {
 public function __construct(private NativePortAdapter $port){}
 public function snapshot(ShopBinding $scope,int $actor):array|\WP_Error {
  $r=$this->port->recovery($scope,$actor,['action'=>'INSPECT']);if(is_wp_error($r))return $r;
  $state=$r['recovery_required']?'RECOVERY_REQUIRED':match($r['journal_state']){'MISSING'=>'RECOVERY_REQUIRED','UNKNOWN'=>'UNRESOLVED_UNKNOWN','CONFLICTING'=>'CONFLICT_REVIEW','FENCED'=>'IN_FLIGHT_UNCERTAIN','PREPARED'=>'PREPARED_NOT_AUTHORIZED',default=>'OBSERVATION_ONLY'};
  return ['schema'=>'DF_RECOVERY_DIAGNOSTICS_1','scope_fingerprint'=>$scope->fingerprint(),'state'=>$state,'journal_state'=>$r['journal_state'],'generation'=>$r['generation'],'pending_leases'=>$r['pending_leases'],'emergency_acknowledged'=>$r['emergency_acknowledged'],'core_receipt_hash'=>$r['receipt_hash'],'manual_review_required'=>true,'read_only'=>true,'retry_permitted'=>false,'external_execution_authorized'=>false];
 }
 public function render(ShopBinding $scope,int $actor):string {
  $r=$this->snapshot($scope,$actor);if(is_wp_error($r))return '<p>Authorized recovery evidence unavailable. No recovery action authorized.</p>';
  return '<section><h2>Recovery observations</h2><p>'.htmlspecialchars($r['state'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p><p>Pending leases: '.$r['pending_leases'].'. Manual core review required. No retry or execution authorized.</p></section>';
 }
}
