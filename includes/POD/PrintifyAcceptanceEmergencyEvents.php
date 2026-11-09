<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Opt-in deny-only observer. Registration is not acceptance execution permission. */
final class PrintifyAcceptanceEmergencyEvents {
 public function register():void {add_action('digiforge_emergency_stop_all_requested',[self::class,'observe'],10,0);}
 public static function observe():void {
  try{$result=PrintifyAcceptanceSafetyGeneration::terminate();$error=is_wp_error($result)?$result->get_error_data():[];
   \DigiForge\Security\Logger::write('printify_acceptance_emergency_observed',['revocation_state'=>is_wp_error($result)?($error['revocation_state']??'HOST_FAIL_STOP_REQUIRED'):'DRAIN_ACKNOWLEDGED','durable_revocation_recorded'=>is_wp_error($result)?($error['durable_revocation_recorded']??null):true,'dispatch_drained'=>!is_wp_error($result),'provider_requests'=>0],'printify_acceptance','emergency');
  }catch(\Throwable){/* Never prevent ordinary STOP ALL propagation. No acceptance permission is returned. */}
 }
}
