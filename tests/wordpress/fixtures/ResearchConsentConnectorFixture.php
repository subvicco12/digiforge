<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/tests/wordpress/PortalResearchRunAcceptanceTest.php';
/** Reuses existing native research-consent fixtures and retains zero provider execution. */
final class ResearchConsentConnectorFixture
{
 private PortalResearchRunAcceptanceTest $case;
 public function __construct(){$this->case=new PortalResearchRunAcceptanceTest();}
 public function call(string $method,mixed ...$arguments):mixed {return (new ReflectionMethod($this->case,$method))->invokeArgs($this->case,$arguments);}
 public function boot():void {$this->call('setUp');$this->call('releasedPolicy','personalized_pod');}
 public function restore():void {$this->call('tearDown');}
 public function prepare():array {$selection=$this->call('selected','personalized_pod');$id=$this->connector();$prepared=$this->call('invoke',DigiForge\Portal\ResearchRunWorkflow::PREPARE_ACTION,['shop_key'=>'personalized_pod','phase'=>'deep_research','selected_candidate_id'=>(string)$selection['candidate']['id']],false);$html=$this->call('render','personalized_pod',['df_research_run'=>$prepared['df_research_run']]);$body=$this->call('form',$html,DigiForge\Portal\ResearchRunWorkflow::EXECUTE_ACTION);return ['connector'=>$id,'operation'=>$prepared['df_research_run'],'body'=>$body];}
 public function connector():int {global $wpdb;$wpdb->update(DigiForge\Database\Tables::integrations(),['enabled'=>0],['provider'=>'ai']);$now=gmdate('Y-m-d H:i:s');$wpdb->insert(DigiForge\Database\Tables::integrations(),['provider'=>'ai','environment'=>'production','connection_key'=>'synthetic-consent-generation-'.wp_generate_uuid4(),'display_name'=>'SYNTHETIC METADATA ONLY','status'=>'CONFIGURED','enabled'=>1,'config'=>'{}','created_at'=>$now,'updated_at'=>$now]);$id=(int)$wpdb->insert_id;$wpdb->insert(DigiForge\Database\Tables::integration_secrets(),['integration_id'=>$id,'secret_name'=>'api_key','ciphertext'=>'SYNTHETIC_UNUSABLE_NEVER_DECRYPT','fingerprint'=>'1234567890abcdef','created_at'=>$now,'updated_at'=>$now]);return $id;}
 public function evidence(array $input):string|WP_Error {$plan=null;$arguments=[$input,&$plan];return (new ReflectionMethod(DigiForge\Portal\ResearchRunWorkflow::class,'evidence'))->invokeArgs(new DigiForge\Portal\ResearchRunWorkflow(),$arguments);}
 public function assertNotConsumed(array $prepared):void {PHPUnit\Framework\Assert::assertFalse(get_option('digiforge_manual_research_consumed_'.$prepared['operation'],false));}
}
