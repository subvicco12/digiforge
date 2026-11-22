<?php
declare(strict_types=1);
namespace DigiForge\Portal;
use DigiForge\Listings\PublishReviewRepository;
use DigiForge\Security\Logger;
use WP_Error;
/** Local separate human decision; no publish, credentials, activation or external dispatch. */
final class PublishReviewWorkflow {
 public const ACTION='digiforge_portal_publish_review';
 public function submit():never {
  if(!is_string($_POST['_wpnonce']??null)||!wp_verify_nonce(wp_unslash($_POST['_wpnonce']),self::ACTION)){if(!Logger::write('etsy_publish_review_denied',['reason'=>'nonce'],'portal_action',self::ACTION))wp_die('Publish review denial audit unavailable.','Evidence unavailable',['response'=>503]);wp_die('Publish review nonce invalid.','Access denied',['response'=>403]);}
  $input=$this->input(wp_unslash($_POST));$repo=new PublishReviewRepository();$hash=$_POST['scope_hash']??null;$confirmation=$_POST['publish_review_confirmation']??null;
  $result=$input instanceof WP_Error?$input:(!is_string($hash)||!is_string($confirmation)?new WP_Error('publish_review_confirmation','Explicit separate review confirmation required.'): $repo->record($input,$hash,$confirmation));
  $args=['df_view'=>'listings','df_shop'=>is_array($input)?$input['shop_key']:'all','df_error'=>is_wp_error($result)?'1':'0','df_message'=>is_wp_error($result)?$result->get_error_message():'Separate local publish review recorded; publishing remains locked.'];
  if(is_wp_error($result)){if(!Logger::write('etsy_publish_review_denied',['reason'=>$result->get_error_code()],'portal_action',self::ACTION))wp_die('Publish review denial audit unavailable.','Evidence unavailable',['response'=>503]);}else $args['df_publish_review']=$result['payload']['review_id'];
  $url=get_option('digiforge_portal_url',home_url('/digiforge/'));if(!is_string($url)||$url==='')$url=home_url('/digiforge/');wp_safe_redirect(add_query_arg($args,$url));exit;
 }
 public function render(?string $shop,string $url):void {
  echo '<section class="df-panel"><h2>Separate publish review (local only)</h2><p>Gate 3, draft QA and prepared payloads never grant publication. This is a separate expiring decision for one exact seller, listing, package and request. It cannot enable Etsy or perform an external action.</p>';
  if($shop===null||!is_user_logged_in()||!current_user_can('manage_digiforge_connections')||!current_user_can('manage_digiforge_listings')){echo '<p>Select one assigned shop with listing and connection review permissions.</p></section>';return;}
  foreach(['manage_digiforge_connections','manage_digiforge_listings']as $cap)if(is_wp_error(\DigiForge\Security\ShopAccess::authorize($shop,$cap))){echo '<p>Explicit shop review permissions required.</p></section>';return;}
  echo '<form method="get" action="'.esc_url($url).'"><input type="hidden" name="df_view" value="listings"><input type="hidden" name="df_shop" value="'.esc_attr($shop).'">';
  foreach(['integration_id'=>'Integration ID','shop_id'=>'Verified numeric Etsy shop ID','intent_id'=>'Approved intent ID','draft_package_id'=>'Approved package ID']as $key=>$label){$field='df_publish_'.$key;$value=is_string($_GET[$field]??null)?wp_unslash($_GET[$field]):'';echo '<label>'.esc_html($label).'<input name="'.esc_attr($field).'" inputmode="numeric" pattern="[1-9][0-9]*" required value="'.esc_attr($value).'"></label>';}
  echo '<button class="df-button" type="submit">Inspect exact publish-review evidence</button></form>';
  $fields=['shop_key'=>$shop];foreach(['integration_id','shop_id','intent_id','draft_package_id']as $key)$fields[$key]=$_GET['df_publish_'.$key]??null;
  if(array_filter($fields,static fn($v)=>$v===null)===[]){$input=$this->input($fields);$context=is_wp_error($input)?$input:(new PublishReviewRepository())->inspect($input);if(is_wp_error($context)){echo '<div class="df-notice df-notice-error">'.esc_html($context->get_error_message()).'</div>';}else{
   echo '<p>Listing #'.esc_html((string)$context['listing_id']).' · Current scope SHA-256 <code>'.esc_html($context['scope_hash']).'</code> · Request SHA-256 <code>'.esc_html($context['request_fingerprint']).'</code></p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.self::ACTION.'">';wp_nonce_field(self::ACTION);foreach($input as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';echo '<input type="hidden" name="scope_hash" value="'.esc_attr($context['scope_hash']).'"><label><input type="checkbox" name="publish_review_confirmation" value="CONFIRM_SEPARATE_PUBLISH_REVIEW" required> I approve this exact separate local publish decision; it expires after 5 minutes and grants no activation or execution permission.</label><button class="df-button" type="submit">Record separate local review</button></form>';
  }}
  $id=$_GET['df_publish_review']??null;if(is_string($id)){$review=(new PublishReviewRepository())->status($id);if(!is_wp_error($review)&&$review['input']['shop_key']===$shop)echo '<p>Review <code>'.esc_html($id).'</code> · '.esc_html($review['state']).' · Expires '.esc_html(gmdate('c',$review['expires_at'])).' · External execution authority: NO</p>';else echo '<p>Review evidence unavailable for this actor/shop.</p>';}
  echo '<p>Missing seller identity, approval, current draft QA or release evidence blocks this review. No live provider certification is inferred.</p></section>';
 }
 private function input(array $body):array|WP_Error {
  if(!is_string($body['shop_key']??null))return new WP_Error('publish_review_input','Canonical shop required.');$input=['shop_key'=>$body['shop_key']];foreach(['integration_id','shop_id','intent_id','draft_package_id']as $key){$v=$body[$key]??null;if(is_int($v)&&$v>0){$input[$key]=$v;continue;}if(!is_string($v)||!preg_match('/^[1-9][0-9]*$/D',$v)||strlen($v)>strlen((string)PHP_INT_MAX)||(string)(int)$v!==$v)return new WP_Error('publish_review_input','Canonical positive identifiers required.');$input[$key]=(int)$v;}return $input;
 }
}
