<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/fixtures/ShopAccessFixture.php';
/** Synthetic local review ONLY. Never authentic provider identity or activation authority. */
final class ScopedProviderConnectionFixture
{
 public static function approve(int $integration,int $owner,?int $expires=null):array {
  $repo=new DigiForge\Integrations\Repository();$i=$repo->find($integration);if(!is_array($i))throw new RuntimeException('Synthetic integration missing');
  $config=$i['config'];$config['account_reference']='synthetic-account-'.$integration;$config['shop_id']=(string)(800000000+$integration);
  if(is_wp_error($repo->update($integration,['config'=>$config])))throw new RuntimeException('Synthetic config failed');
  $caps=['manage_digiforge','manage_digiforge_connections','manage_digiforge_settings','manage_digiforge_pod'];
  ShopAccessFixture::authorizeActor($owner,['personalized_pod'],$caps);
  $reviewer=wp_insert_user(['user_login'=>'connection_reviewer_'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>'administrator']);if(is_wp_error($reviewer))throw new RuntimeException('Synthetic reviewer failed');
  ShopAccessFixture::authorizeActor((int)$reviewer,['personalized_pod'],$caps);wp_set_current_user($owner);
  $service=new DigiForge\POD\ScopedProviderConnection();$revision=$service->status('personalized_pod',$integration)['review_revision']??0;$input=['integration_id'=>$integration,'capability'=>'printify.catalog.read','source_sha256'=>hash('sha256','synthetic-connection-'.$integration),'evidence_reference'=>'local://synthetic/provider-connection','observed_at'=>gmdate('c',time()-10)];
  $proposal=$service->propose('personalized_pod',$input,time()-1,$expires??time()+3600,'connection-propose-'.wp_generate_uuid4(),$revision);
  if(is_wp_error($proposal))throw new RuntimeException('Synthetic proposal: '.$proposal->get_error_code());
  wp_set_current_user((int)$reviewer);$approved=$service->decide('personalized_pod',$integration,'printify.catalog.read',$proposal['revision'],$proposal['proposal_hash'],'APPROVED','connection-approve-'.wp_generate_uuid4());
  wp_set_current_user($owner);if(is_wp_error($approved))throw new RuntimeException('Synthetic approval: '.$approved->get_error_code());
  return ['proposal'=>$proposal,'approved'=>$approved,'input'=>$input,'reviewer'=>(int)$reviewer];
 }
}
