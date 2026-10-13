<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\Database\Tables;
use DigiForge\Orders\Validator;
use WP_Error;

/** Explicit local review acknowledgement. It never issues an execution permit. */
final class ReviewConfirmation {
 public static function definition(string $action):?array {
  return match($action){
   'digiforge_u3_product_review'=>['product_versions','product_version_id','Product version review','manage_digiforge_products','product',['APPROVED','REJECTED']],
   'digiforge_portal_review_research'=>['research_candidates','candidate_id','Research candidate','manage_digiforge_research','research',['APPROVED','REJECTED']],
   'digiforge_portal_develop_candidate'=>['research_candidates','candidate_id','Product development candidate','manage_digiforge_products','research',[]],
   'digiforge_portal_listing_review'=>['listing_readiness_reviews','review_id','Listing readiness review','manage_digiforge_listings','listing',['APPROVED','REJECTED']],
   'digiforge_portal_pod_render_review'=>['pod_render_evidence','render_evidence_id','POD visual render review','manage_digiforge_pod','render',[]],
   'digiforge_portal_pod_package_review'=>['pod_authorization_packages','package_id','POD authorization package review','manage_digiforge_pod','package',[]],
   'digiforge_portal_master500_acceptance'=>['catalog_versions','catalog_version_id','Master 500 migration review','manage_digiforge_pod','catalog',['ACCEPT','REJECT']],
   'digiforge_portal_personalization_review'=>['personalization_submissions','submission_id','Order personalization review','manage_digiforge_orders','personalization',['APPROVED','REJECTED']],
   'digiforge_portal_ownership_review'=>['pod_business_mappings','mapping_id','POD ownership mapping review','manage_digiforge_pod','ownership',[]],
   'digiforge_portal_replacement_request'=>['orders','order_id','Replacement support request','manage_digiforge_orders','order',[]],
   'digiforge_portal_replacement_decision'=>['audit_log','case_id','Replacement support case review','manage_digiforge_orders','case',['APPROVED_LOCAL_REVIEW','REJECTED_LOCAL_REVIEW']],
   'digiforge_portal_draft_qa_review'=>['etsy_operations','create_operation_id','Marketplace draft QA review','manage_digiforge_listings','draft_qa',[]],
   default=>null,
  };
 }
 public static function parameters(string $action,array $body):array {
  $definition=self::definition($action);$params=[];
  if($definition!==null&&$definition[5]!==[])$params['decision']=is_string($body['decision']??null)?strtoupper($body['decision']):'';
  if($action==='digiforge_portal_develop_candidate')$params['destination_shop']=is_string($body['shop']??null)?$body['shop']:'';
  if($action==='digiforge_portal_draft_qa_review')$params=['checks'=>$body['checks']??null,'objective_evidence_hash'=>$body['objective_evidence_hash']??null,'intent_id'=>self::positiveId($body['intent_id']??null),'draft_package_id'=>self::positiveId($body['draft_package_id']??null)];
  return $params;
 }
 public static function inspect(string $action,int $id,?string $selectedShop=null,array $params=[],array $proof=[]):array|WP_Error {
  $definition=self::definition($action);if($definition===null||$id<1)return self::error('target','An exact supported review target is required.');
  if(get_current_user_id()<1||!current_user_can($definition[3]))return self::error('access','Authenticated review capability is required.');
  if($definition[5]!==[]&&!in_array($params['decision']??'', $definition[5],true))return self::error('decision','Choose the exact local review decision.');
  global $wpdb;$table=$definition[0]==='etsy_operations'?$wpdb->prefix.'digiforge_etsy_operations':Tables::{$definition[0]}();$authorizedShop=self::authorizedTargetShop($definition,$table,$id);if($authorizedShop instanceof WP_Error)return $authorizedShop;$row=self::row($table,$id);if($row instanceof WP_Error)return $row;
  $evidence=['target'=>$row];$order=null;$shop='';$environment=(string)($row['environment']??'');$summary=[];
  try{
   switch($definition[4]){
    case 'product':
     $product=self::requiredRow(Tables::products(),(int)$row['product_id']);$family=self::requiredRow(Tables::product_families(),(int)$product['product_family_id']);$evidence['product']=$product;$evidence['family']=$family;$evidence['opportunity']=self::requiredRow(Tables::opportunities(),(int)$family['opportunity_id']);$shop=$authorizedShop;$environment='local product review';
     $plans=self::rows('SELECT * FROM '.Tables::production_plans().' WHERE product_version_id='.$id.' ORDER BY id DESC LIMIT 1');if($plans===[])return self::error('unavailable','Current production review plan is unavailable.');$plan=$plans[0];$bundles=self::rows('SELECT * FROM '.Tables::release_bundles().' WHERE production_plan_id='.(int)$plan['id'].' ORDER BY id DESC LIMIT 1');if($bundles===[])return self::error('unavailable','Current product review bundle is unavailable.');$bundle=$bundles[0];if($plan['state']!=='REVIEW_REQUIRED'||$bundle['state']!=='REVIEW_REQUIRED')return self::error('stale','Product plan and release bundle are no longer awaiting review.');$evidence['plan']=$plan;$evidence['bundle']=$bundle;
     $evidence['plan_qa']=self::rows("SELECT * FROM ".Tables::production_qa()." WHERE target_type='plan' AND target_id=".(int)$plan['id'].' ORDER BY id LIMIT 201');$links=self::rows('SELECT * FROM '.Tables::production_plan_assets().' WHERE production_plan_id='.(int)$plan['id'].' AND is_required=1 ORDER BY sequence_no,asset_spec_id LIMIT 201');$evidence['required_assets']=[];$bound=count($links)+count($evidence['plan_qa']);
     foreach($links as $link){$spec=self::requiredRow(Tables::asset_specs(),(int)$link['asset_spec_id']);$revisions=self::rows('SELECT * FROM '.Tables::asset_revisions().' WHERE asset_spec_id='.(int)$spec['id'].' ORDER BY id DESC LIMIT 1');$revision=$revisions[0]??null;$qa=$revision===null?[]:self::rows("SELECT * FROM ".Tables::production_qa()." WHERE target_type='revision' AND target_id=".(int)$revision['id'].' ORDER BY id LIMIT 201');$bound+=count($qa)+count($revisions)+1;if($bound>200)return self::error('unavailable','Current product review dependencies exceed the bounded confirmation view.');$evidence['required_assets'][]=['link'=>$link,'spec'=>$spec,'latest_revision'=>$revision,'revision_qa'=>$qa];}
     $summary=['Product version #'.$id.' / '.$row['version_label'],(string)$product['name'],'Latest production plan #'.$plan['id'].' / '.$plan['state'],'Latest release bundle #'.$bundle['id'].' / '.$bundle['state'],count($links).' required asset bindings',count($evidence['plan_qa']).' recorded plan QA rows','Recorded bundle checksum '.(string)$bundle['checksum_sha256']];foreach($evidence['required_assets']as $asset)$summary[]='Asset #'.$asset['spec']['id'].' latest revision '.($asset['latest_revision']['id']??'MISSING').' / recorded checksum '.($asset['latest_revision']['checksum_sha256']??'MISSING');break;
    case 'draft_qa':
     $context=(new \DigiForge\Listings\DraftQaReviewRepository())->inspect($id);if($context instanceof WP_Error)return $context;
     $checks=$params['checks']??null;if(!is_array($checks)||count($checks)!==7||array_diff(array_keys($checks),['title','description','seo','media','pricing','policy','personalization'])!==[])return self::error('decision','Exactly seven explicit human QA assertions are required.');foreach($checks as $status)if(!is_string($status)||!in_array($status,['PASS','WAIVED','FAIL'],true))return self::error('decision','Choose PASS, WAIVED or FAIL for every human QA assertion.');
     if(($params['intent_id']??0)!==$context['intent_id']||($params['draft_package_id']??0)!==$context['draft_package_id']||!is_string($params['objective_evidence_hash']??null)||!hash_equals($context['objective_evidence_hash'],$params['objective_evidence_hash']))return self::error('stale','Draft objective evidence or scope changed.');
     $shop=$context['shop_key'];$environment=$context['environment'];$evidence['objective_context']=$context;$summary=['Listing #'.$context['listing_id'].' · '.$context['listing_title'],'Intent #'.$context['intent_id'].' / package #'.$context['draft_package_id'],'Confirmed draft #'.$id.' / Etsy listing '.$context['external_listing_id'],'Attachment operation #'.$context['attachment_operation_id'],'Objective evidence SHA-256 '.$context['objective_evidence_hash']];foreach($checks as $check=>$status)$summary[]='Human '.ucfirst($check).' assertion: '.$status;break;
    case 'research':
     $signals=json_decode((string)($row['score_inputs']??''),true);$shop=is_array($signals)?(string)($signals['shop_key']??''):'';
     $evidence['facts']=self::rows('SELECT ce.evidence_id,e.*,o.id AS observation_identity,o.content_hash,o.provenance AS observation_provenance,s.id AS source_identity,s.config AS source_config FROM '.Tables::research_candidate_evidence().' ce LEFT JOIN '.Tables::research_evidence().' e ON e.id=ce.evidence_id LEFT JOIN '.Tables::research_observations().' o ON o.id=e.observation_id LEFT JOIN '.Tables::research_sources().' s ON s.id=o.source_id WHERE ce.candidate_id='.$id.' ORDER BY ce.evidence_id LIMIT 201');
     foreach($evidence['facts']as $fact)if(empty($fact['id'])||empty($fact['observation_identity'])||empty($fact['source_identity']))return self::error('unavailable','Linked research source evidence is missing; review is blocked.');
     $summary=['Candidate #'.$id,(string)($row['title']??''),'Status '.(string)($row['review_status']??''),'Score '.(string)($row['score']??''),count($evidence['facts']).' linked source facts'];
     if($action==='digiforge_portal_develop_candidate'){$destination=$params['destination_shop']??'';if(!in_array($destination,['digital','goods'],true)||($destination==='goods'?'personalized_pod':'digital')!==$shop)return self::error('scope','Development destination must match the reviewed source shop.');$summary[]='Destination '.$destination;}
     break;
    case 'listing':
     $listing=self::requiredRow(Tables::listings(),(int)$row['listing_id']);$shop=(string)$listing['shop_reference'];$environment=(string)$listing['environment'];$evidence['listing']=$listing;$evidence['current_readiness']=(new \DigiForge\Listings\Repository())->readiness((int)$listing['id']);if($evidence['current_readiness'] instanceof WP_Error)return $evidence['current_readiness'];$summary=['Listing #'.$listing['id'],(string)$listing['title'],'Product version #'.$listing['product_version_id'],'Review #'.$id,'Decision '.(string)$row['decision']];break;
    case 'personalization':
     $line=self::requiredRow(Tables::order_line_items(),(int)$row['order_line_item_id']);$order=self::requiredRow(Tables::orders(),(int)$line['order_id']);$evidence['line']=$line;$evidence['schema']=self::requiredRow(Tables::personalization_schemas(),(int)$row['personalization_schema_id']);$summary=['Submission #'.$id,'Order #'.$order['id'].' / line #'.$line['id'],'Schema #'.$row['personalization_schema_id'],'Payload SHA-256 '.(string)$row['payload_hash'],'Review '.(string)$row['review_status']];break;
    case 'render':case 'package':
     $order=self::requiredRow(Tables::orders(),(int)$row['order_id']);$evidence['mapping']=self::requiredRow(Tables::pod_mappings(),(int)$row['provider_mapping_id']);$render=$definition[4]==='render'?$row:self::requiredRow(Tables::pod_render_evidence(),(int)$row['render_evidence_id']);$evidence['render']=$render;$evidence['certification']=(new \DigiForge\POD\RenderArtifactVerifier())->assertCertified($render);if($evidence['certification'] instanceof WP_Error)return $evidence['certification'];if($definition[4]==='package'){$evidence['ownership']=self::requiredRow(Tables::pod_business_mappings(),(int)$row['ownership_mapping_id']);$evidence['current_readiness']=(new \DigiForge\Orders\Repository())->readiness((int)$order['id']);if($evidence['current_readiness'] instanceof WP_Error)return $evidence['current_readiness'];}$summary=['Order #'.$order['id'],'Provider mapping #'.$row['provider_mapping_id'],'Render #'.$render['id'],'Template '.(string)$render['template_key'].' / '.(string)$render['template_version'],'Review '.(string)($row['review_status']??$row['state']??'')];break;
    case 'ownership':
     $mapping=self::requiredRow(Tables::pod_mappings(),(int)$row['provider_mapping_id']);if((int)$mapping['product_version_id']!==(int)$row['product_version_id'])return self::error('scope','Ownership and provider product identities disagree.');$shop=self::productShop(Tables::pod_mappings(),(int)$mapping['id']);$environment=(string)$mapping['environment'];$evidence['mapping']=$mapping;foreach(['business_id'=>'businesses','store_id'=>'stores','product_program_id'=>'product_programs']as $field=>$table)$evidence[$field]=self::requiredRow(Tables::{$table}(),(int)$row[$field]);$summary=['Ownership mapping #'.$id,'Business #'.$row['business_id'].' / store #'.$row['store_id'].' / program #'.$row['product_program_id'],'Product version #'.$row['product_version_id'],'Provider mapping #'.$row['provider_mapping_id'],'State '.(string)$row['state']];break;
    case 'catalog':
     if(($row['catalog_key']??'')!==\DigiForge\POD\MasterCatalogV2Reference::CATALOG_KEY)return self::error('target','Exact governed migration catalog required.');$shop='personalized_pod';$environment='local reference';$evidence['parent']=self::requiredRow(Tables::catalog_versions(),(int)$row['parent_version_id']);$evidence['acceptance']=(new \DigiForge\POD\MasterCatalogV2AcceptanceRepository())->current($id);if($evidence['acceptance'] instanceof WP_Error)return $evidence['acceptance'];$summary=['Catalog version #'.$id,'Parent version #'.$row['parent_version_id'],(string)$row['catalog_key'],(string)$row['row_count'].' stored source rows','Source SHA-256 '.(string)$row['source_sha256']];break;
    case 'order':$order=$row;$summary=['Order #'.$id,'State '.(string)$row['state']];break;
    case 'case':
     $case=json_decode((string)($row['context']??''),true);if(($row['event_type']??'')!==\DigiForge\Orders\ReplacementReviewRepository::REQUESTED||($row['object_type']??'')!=='order'||!is_array($case)||($case['schema']??'')!=='replacement-review-v1'||(int)($case['order_id']??0)!==(int)$row['object_id']||(int)$row['actor_id']<1)return self::error('target','Exact persisted support request required.');$order=self::requiredRow(Tables::orders(),(int)$case['order_id']);$evidence['case']=$case;$evidence['decisions']=self::rows('SELECT id,actor_id,context FROM '.Tables::audit_log().' WHERE event_type=\''.\DigiForge\Orders\ReplacementReviewRepository::DECIDED.'\' AND object_type=\'order\' AND object_id=\''.(int)$order['id'].'\' ORDER BY id LIMIT 201');$summary=['Case #'.$id,'Order #'.$order['id'],'Frozen source SHA-256 '.(string)$case['source_hash']];break;
   }
   if($order!==null){$evidence['order']=$order;$evidence['lines']=self::rows('SELECT * FROM '.Tables::order_line_items().' WHERE order_id='.(int)$order['id'].' ORDER BY id LIMIT 201');$shop=self::orderShop($order);$environment=(string)$order['environment'];$summary[]=count($evidence['lines']).' stored order lines';}
  }catch(\RuntimeException $error){return self::error('unavailable',$error->getMessage());}
  if($shop!==$authorizedShop)return self::error('scope','Current target shop attribution changed.');
  if(!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true))return self::error('scope','Stored canonical shop attribution is unavailable.');
  if($selectedShop!==null&&$selectedShop!=='all'&&$selectedShop!==$shop)return self::error('scope','Review target does not belong to this selected shop.');
  $nonce=$proof['confirmation_nonce']??str_replace('-','',wp_generate_uuid4());$issued=$proof['issued_at']??time();
  if(!is_string($nonce)||preg_match('/^[a-f0-9]{32}$/D',$nonce)!==1||!is_int($issued)||$issued>time()||time()-$issued>900)return self::error('expired','Review confirmation is invalid or expired. Review current evidence again.');
  $hash=hash('sha256',Validator::canonicalJson($evidence));$payload=['action'=>$action,'target_id'=>$id,'shop'=>$shop,'actor_id'=>get_current_user_id(),'evidence_hash'=>$hash,'parameters'=>$params,'confirmation_nonce_sha256'=>hash('sha256',$nonce),'issued_at'=>$issued];
  $effect=match($action){'digiforge_portal_develop_candidate'=>'Prepares product development for the selected source shop. Activation, budgets and external execution remain separately governed.','digiforge_portal_replacement_request'=>'Creates a local support review request bound to the stored order and lines. No replacement production or refund is authorized.',default=>'Records the selected local review decision only. No publishing, provider production, fulfillment or money movement is authorized.'};
  return $payload+['token'=>hash_hmac('sha256',Validator::canonicalJson($payload),wp_salt('nonce')),'confirmation_nonce'=>$nonce,'target_label'=>$definition[2].' #'.$id,'environment'=>$environment,'summary'=>array_map(static fn(string $value):string=>mb_substr(wp_strip_all_tags($value),0,240),$summary),'effect'=>$effect];
 }
 public static function verify(string $action,int $id,array $body):true|WP_Error {
  $definition=self::definition($action);if($definition===null)return self::error('action','Supported review action required.');$raw=$body[$definition[1]]??null;if(!(is_int($raw)&&$raw>0)&&!(is_string($raw)&&preg_match('/^[1-9][0-9]*$/D',$raw)===1))return self::error('target','Exact positive review target identity required.');
  if(($body['df_confirm_review']??null)!=='1'||!is_string($body['df_confirmation_shop']??null))return self::error('required','Review the exact target and evidence, then explicitly confirm your local decision.');
  $params=self::parameters($action,$body);$key=$params['decision']??$params['destination_shop']??'REVIEW';$tokens=$body['df_confirmation_tokens']??null;$token=is_array($tokens)?($tokens[$key]??null):null;if(!is_string($token)||preg_match('/^[a-f0-9]{64}$/D',$token)!==1)return self::error('required','Exact current review confirmation is required.');
  $current=self::inspect($action,$id,$body['df_confirmation_shop'],$params,self::proof($body));if($current instanceof WP_Error)return $current;
  if(isset($body['shop_key'])&&(!is_string($body['shop_key'])||!in_array($body['shop_key'],['all',$current['shop']],true)))return self::error('scope','The selected action shop differs from the confirmed target.');
  if(!hash_equals($current['token'],$token))return self::error('stale','Target, evidence, decision, shop or reviewer changed. Review the current evidence and confirm again.');return true;
 }
 /** One-use local acknowledgement before a repository write, distinct from its decision/outcome. */
 public static function accept(string $action,int $id,array $body):array|WP_Error {
  $verified=self::verify($action,$id,$body);if($verified instanceof WP_Error)return $verified;global $wpdb;$lock='df-local-confirm-'.substr(hash('sha256',$action.'|'.$id),0,40);$wpdb->last_error='';$acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock));if(!empty($wpdb->last_error)||(string)$acquired!=='1')return self::error('busy','This local review confirmation is busy.');
  try{
   $verified=self::verify($action,$id,$body);if($verified instanceof WP_Error)return $verified;
   $current=self::inspect($action,$id,$body['df_confirmation_shop'],self::parameters($action,$body),self::proof($body));if($current instanceof WP_Error)return $current;
   $choice=$current['parameters']['decision']??$current['parameters']['destination_shop']??'REVIEW';if(!hash_equals($current['token'],$body['df_confirmation_tokens'][$choice]))return self::error('stale','Current source evidence changed while confirmation was being acknowledged.');
   $receipt=array_intersect_key($current,array_flip(['action','target_id','shop','actor_id','evidence_hash','parameters','confirmation_nonce_sha256','issued_at']));$receipt['confirmation_validated']=true;$receipt['decision_recorded']=false;$receipt['external_execution_authorized']=false;$receipt['external_execution_performed']=false;$receipt['acknowledgement_hash']=hash('sha256',Validator::canonicalJson($receipt));$table=Tables::audit_log();
   $wpdb->last_error='';$prior=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$table.' WHERE event_type=%s AND object_type=%s AND actor_id=%d AND JSON_VALID(context) AND JSON_UNQUOTE(JSON_EXTRACT(context,%s))=%s AND JSON_UNQUOTE(JSON_EXTRACT(context,%s))=%s AND JSON_UNQUOTE(JSON_EXTRACT(context,%s))=%s LIMIT 1','portal_review_confirmation_accepted','local_review_confirmation',$receipt['actor_id'],'$.action',$action,'$.target_id',(string)$id,'$.confirmation_nonce_sha256',$receipt['confirmation_nonce_sha256']));if(!empty($wpdb->last_error))return self::error('audit_unavailable','Local confirmation audit evidence is unavailable.');if($prior!==null)return self::error('replay','This confirmation has already been accepted. Review current evidence and confirm again.');
   if(!\DigiForge\Security\Logger::write('portal_review_confirmation_accepted',$receipt,'local_review_confirmation',$receipt['acknowledgement_hash']))return self::error('audit_unavailable','Confirmation could not be durably acknowledged. No review decision was performed.');
   $wpdb->last_error='';$stored=$wpdb->get_var($wpdb->prepare('SELECT context FROM '.$table.' WHERE event_type=%s AND object_type=%s AND object_id=%s ORDER BY id DESC LIMIT 1','portal_review_confirmation_accepted','local_review_confirmation',$receipt['acknowledgement_hash']));if(!empty($wpdb->last_error)||!is_string($stored)||json_decode($stored,true)!==$receipt)return self::error('audit_unavailable','Confirmation acknowledgement could not be verified. No review decision was performed.');
   return $receipt;
  }finally{$wpdb->last_error='';$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if(!empty($wpdb->last_error)||(string)$released!=='1')return self::error('uncertain','Confirmation lock release is uncertain. No review decision was performed.');}
 }
 public static function authorizeTarget(string $action,int $id):string|WP_Error {
  $definition=self::definition($action);if($definition===null||$id<1)return self::error('access','Supported review target required.');global $wpdb;
  $table=$definition[0]==='etsy_operations'?$wpdb->prefix.'digiforge_etsy_operations':Tables::{$definition[0]}();return self::authorizedTargetShop($definition,$table,$id);
 }
 private static function authorizedTargetShop(array $definition,string $table,int $id):string|WP_Error {
  $allowed=\DigiForge\Security\ShopAccess::allowedShops($definition[3]);if($allowed instanceof WP_Error)return $allowed;global $wpdb;$matches=[];
  foreach($allowed as $shop){
   $scope=match($definition[4]){
    'product'=>str_replace(Tables::product_versions().'.','t.',ProductEvidenceScope::forTable(Tables::product_versions(),$shop)??'1=0'),
    'research'=>$wpdb->prepare("JSON_VALID(t.score_inputs) AND JSON_UNQUOTE(JSON_EXTRACT(t.score_inputs,'$.shop_key'))=%s",$shop),
    'listing'=>"EXISTS (SELECT 1 FROM ".Tables::listings()." l WHERE l.id=t.listing_id AND ".$wpdb->prepare('BINARY l.shop_reference=BINARY %s',$shop).")",
    'draft_qa'=>"EXISTS (SELECT 1 FROM ".Tables::etsy_draft_packages()." p INNER JOIN ".Tables::listings()." l ON l.id=p.listing_id WHERE p.id=t.draft_package_id AND ".$wpdb->prepare('BINARY l.shop_reference=BINARY %s',$shop).")",
    'personalization'=>"EXISTS (SELECT 1 FROM ".Tables::order_line_items()." li INNER JOIN ".Tables::orders()." o ON o.id=li.order_id WHERE li.id=t.order_line_item_id AND ".\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'o').")",
    'render','package'=>"EXISTS (SELECT 1 FROM ".Tables::orders()." o WHERE o.id=t.order_id AND ".\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'o').")",
    'order'=>\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'t'),
    'case'=>"t.object_type='order' AND EXISTS (SELECT 1 FROM ".Tables::orders()." o WHERE BINARY CAST(o.id AS CHAR)=BINARY t.object_id AND ".\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'o').")",
    'ownership'=>"EXISTS (SELECT 1 FROM ".Tables::pod_mappings()." WHERE id=t.provider_mapping_id AND ".ProductEvidenceScope::forTable(Tables::pod_mappings(),$shop).")",
    'catalog'=>$shop==='personalized_pod'?'1=1':'1=0',default=>'1=0',
   };
   $wpdb->last_error='';$found=$wpdb->get_var($wpdb->prepare('SELECT t.id FROM '.$table.' t WHERE t.id=%d AND ('.$scope.') LIMIT 1',$id));if(!empty($wpdb->last_error))return self::error('access','Shop authorization evidence is unavailable.');if($found!==null)$matches[]=$shop;
  }
  return count($matches)===1?$matches[0]:self::error('access','No explicit shop assignment permits this target.');
 }
 private static function proof(array $body):array {return ['confirmation_nonce'=>$body['df_confirmation_nonce']??null,'issued_at'=>self::positiveId($body['df_confirmation_issued_at']??null)];}
 private static function row(string $table,int $id):array|WP_Error {global $wpdb;$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$table.' WHERE id=%d LIMIT 1',$id),ARRAY_A);return !empty($wpdb->last_error)||!is_array($row)?self::error('unavailable','Current review source evidence is unavailable.'):$row;}
 private static function positiveId(mixed $raw):int {return (is_int($raw)&&$raw>0)||(is_string($raw)&&preg_match('/^[1-9][0-9]*$/D',$raw)===1)?(int)$raw:0;}
 private static function requiredRow(string $table,int $id):array {$row=self::row($table,$id);if($row instanceof WP_Error)throw new \RuntimeException($row->get_error_message());return $row;}
 private static function rows(string $sql):array {global $wpdb;$wpdb->last_error='';$rows=$wpdb->get_results($sql,ARRAY_A);if(!is_array($rows)||!empty($wpdb->last_error)||count($rows)>200)throw new \RuntimeException('Bounded current review dependencies are unavailable.');return $rows;}
 private static function productShop(string $table,int $id):string {global $wpdb;$owners=[];foreach(['digital','personalized_pod','standard_pod','jewelry']as $shop){$predicate=ProductEvidenceScope::forTable($table,$shop);$wpdb->last_error='';$found=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$table.' WHERE id=%d AND '.$predicate,$id));if(!empty($wpdb->last_error))throw new \RuntimeException('Stored product shop evidence is unavailable.');if($found!==null)$owners[]=$shop;}return count($owners)===1?$owners[0]:'';}
 private static function orderShop(array $order):string {if(in_array($order['shop_reference']??'', ['digital','personalized_pod','standard_pod','jewelry'],true))return $order['shop_reference'];global $wpdb;$owners=[];foreach(['digital','personalized_pod','standard_pod','jewelry']as $shop){$wpdb->last_error='';$found=$wpdb->get_var($wpdb->prepare('SELECT o.id FROM '.Tables::orders().' o WHERE o.id=%d AND '.\DigiForge\Listings\EtsyStoredShopScope::orderPredicate($shop,'o'),(int)$order['id']));if(!empty($wpdb->last_error))throw new \RuntimeException('Stored order shop evidence is unavailable.');if($found!==null)$owners[]=$shop;}return count($owners)===1?$owners[0]:'';}
 private static function error(string $code,string $message):WP_Error {return new WP_Error('portal_confirmation_'.$code,$message,['status'=>409,'external_execution_authorized'=>false]);}
}
