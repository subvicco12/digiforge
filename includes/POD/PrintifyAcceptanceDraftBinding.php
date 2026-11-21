<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Inactive amendment identity record. Never accepted by existing runtime ownership/credential admission. */
final class PrintifyAcceptanceDraftBinding {
 public static function proposal():array {
  return ['schema'=>'printify-shop2-acceptance-draft-v1','mode'=>'ACCEPTANCE_ONLY_DRAFT','adoption_status'=>'NOT_ADOPTED','normal_binding'=>PrintifyAcceptanceBinding::proposal(),'runtime_execution_authorized'=>false];
 }
 public static function validate(array $p,array $e,int $now):array {
  $issues=[];$expected=array_keys(self::proposal());
  $valid=count($p)===count($expected)&&!array_diff(array_keys($p),$expected)&&($p['schema']??null)==='printify-shop2-acceptance-draft-v1'&&($p['mode']??null)==='ACCEPTANCE_ONLY_DRAFT'&&($p['adoption_status']??null)==='NOT_ADOPTED'&&($p['runtime_execution_authorized']??null)===false&&is_array($p['normal_binding']??null);
  $normal=null;
  if(!$valid)$issues[]='INVALID_INACTIVE_DRAFT';
  else {
   $normal=PrintifyAcceptanceBinding::validate($p['normal_binding'],$e,$now);
   $disabled=($p['normal_binding']['integration_enabled']??null)===false&&($e['integration_enabled']??null)===false;
   // Normal denial is retained below. Only a *known disabled* state is expected in this inactive proposal.
   $issues=$disabled?array_values(array_diff($normal['blockers'],['INTEGRATION_DISABLED_OR_UNKNOWN'])):$normal['blockers'];
   if(!$disabled)$issues[]='DRAFT_REQUIRES_DISABLED_INTEGRATION';
  }
  return ['schema'=>'printify-acceptance-draft-validation-v1','status'=>'POLICY_AMENDMENT_REQUIRED','identity_validation'=>$issues?'PROVIDER_IDENTITY_UNVERIFIED':'OFFLINE_VERIFIED','identity_blockers'=>array_values(array_unique($issues)),'normal_runtime_validation'=>$normal,'ordinary_runtime_denial_preserved'=>true,'adoption_status'=>'NOT_ADOPTED','runtime_execution_authorized'=>false,'binding_write_authorized'=>false,'integration_enablement_authorized'=>false,'evidence_is_caller_supplied'=>true];
 }
}
