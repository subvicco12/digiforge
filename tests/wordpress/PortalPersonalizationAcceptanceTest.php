<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/ShopAccessFixture.php';
require_once __DIR__.'/PortalActionAcceptanceFixture.php';
require_once __DIR__.'/PortalSourceWorkflowAcceptanceTest.php';

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\Orders\Repository;
use DigiForge\Portal\Portal;

/** Actual normalized buyer answers and native local review, never artwork certification. */
final class PortalPersonalizationAcceptanceTest extends WP_UnitTestCase
{
    private int $httpCalls=0;
    private Closure $http;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();Settings::protectProduction();ShopAccessFixture::reset();
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        ShopAccessFixture::authorizeActor(get_current_user_id(),['personalized_pod'],['manage_digiforge','manage_digiforge_orders']);
        $this->http=function(){++$this->httpCalls;return new WP_Error('offline_personalization','Actual HTTP forbidden.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->httpCalls);
        self::assertTrue(Settings::safety_locked());self::assertTrue(Settings::get('stop_all',true));self::assertFalse(Settings::get('automation_armed',false));
        parent::tearDown();
    }
    private function fixture(string $shop='personalized_pod',string $answer='  Ada  '): array
    {
        // Reuse the existing source→canonical product fixture; no render/template certification is needed.
        $case=new PortalSourceWorkflowAcceptanceTest();$candidate=new ReflectionMethod($case,'candidate');$product=new ReflectionMethod($case,'product');
        $flow=$product->invoke($case,$candidate->invoke($case,$shop,'typed-portal-'.wp_generate_uuid4()));
        $orders=new Repository();$order=$orders->createOrder(['channel'=>'etsy','environment'=>'sandbox','shop_reference'=>$shop,'external_order_reference'=>'typed-'.wp_generate_uuid4(),'personalization_required'=>true]);self::assertNotWPError($order);
        $line=$orders->addLineItem(['order_id'=>$order['id'],'product_version_id'=>$flow['version']['id'],'quantity'=>1]);self::assertNotWPError($line);
        $questions=[['question_id'=>7,'question_type'=>'text_input','question_text'=>'Name','required'=>true,'max_allowed_characters'=>30]];
        $pod=new DigiForge\POD\Repository();$schema=$pod->createPersonalizationSchema(['product_version_id'=>$flow['version']['id'],'schema_key'=>'typed-'.wp_generate_uuid4(),'version_label'=>'local-v1','field_definitions'=>[['key'=>'name','type'=>'text','required'=>true,'max_length'=>30,'answer_key'=>'question_7']],'etsy_personalization_questions'=>$questions]);self::assertNotWPError($schema);
        foreach(['VALIDATED','REVIEW_REQUIRED','APPROVED'] as $state)self::assertNotWPError($pod->transition('personalization',(int)$schema['id'],$state));
        $arguments=['order_line_item_id'=>$line['id'],'personalization_schema_id'=>$schema['id']];
        $submission=$orders->createTypedPersonalization($arguments,$questions,['question_7'=>$answer]);self::assertNotWPError($submission);
        return compact('shop','order','line','schema','questions','arguments','submission');
    }
    private function render(string $shop='personalized_pod'): string
    {
        $prior=$_GET;try{$_GET=['df_view'=>'orders','df_shop'=>$shop];return (new Portal())->render();}finally{$_GET=$prior;}
    }
    private function document(string $html): DOMDocument
    {
        $dom=new DOMDocument();$prior=libxml_use_internal_errors(true);try{$dom->loadHTML($html,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}return $dom;
    }
    private function body(array $fixture,string $html): array
    {
        // Each target must have its own complete rendered form and confirmation.
        preg_match_all('/<form\b[^>]*>.*?<\/form>/s',$html,$matches);
        $exact=array_values(array_filter($matches[0],static fn(string $form):bool=>str_contains($form,'value="digiforge_portal_personalization_review"')&&str_contains($form,'name="submission_id" value="'.$fixture['submission']['id'].'"')));self::assertCount(1,$exact);
        $dom=$this->document($exact[0]);$xpath=new DOMXPath($dom);$forms=$xpath->query('//form');self::assertSame(1,$forms->length);
        foreach(['submission_id','_wpnonce','df_confirmation_nonce','df_confirmation_tokens[APPROVED]','df_confirmation_tokens[REJECTED]'] as $name)self::assertSame(1,$xpath->query('.//input[@name="'.$name.'"]',$forms->item(0))->length);
        $pairs=[];foreach($xpath->query('.//input[@type="hidden"]',$forms->item(0)) as $input)$pairs[]=urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
        parse_str(implode('&',$pairs),$body);self::assertSame((string)$fixture['submission']['id'],$body['submission_id']);return $body+['decision'=>'APPROVED','df_confirm_review'=>'1','shop_key'=>$fixture['shop']];
    }
    private function stored(int $id): array
    {
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::personalization_submissions().' WHERE id=%d',$id),ARRAY_A);self::assertIsArray($row);return $row;
    }
    private function receipts(int $id): int
    {
        global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".Tables::audit_log()." WHERE event_type='portal_review_confirmation_accepted' AND JSON_VALID(context) AND JSON_UNQUOTE(JSON_EXTRACT(context,'$.action'))='digiforge_portal_personalization_review' AND JSON_UNQUOTE(JSON_EXTRACT(context,'$.target_id'))=%s",(string)$id));
    }
    public function testNormalizedAnswersRenderOnlyInAssignedShopAndActualFormRecordsOneLocalReview(): void
    {
        $own=$this->fixture();$other=$this->fixture('personalized_pod','Grace');$foreign=$this->fixture('jewelry','Foreign typed name');$id=(int)$own['submission']['id'];
        self::assertSame('{"name":"Ada"}',$own['submission']['canonical_payload']);self::assertSame(hash('sha256','{"name":"Ada"}'),$own['submission']['payload_hash']);self::assertSame('UNREVIEWED',$own['submission']['review_status']);self::assertFalse($own['submission']['external_execution_performed']);
        $html=$this->render();$dom=$this->document($html);$xpath=new DOMXPath($dom);$rows=$xpath->query('//table[thead/tr/th[text()="Submission"]]//tbody/tr[td[1][text()="#'.$id.'"]]');self::assertSame(1,$rows->length);
        $cells=$rows->item(0)->getElementsByTagName('td');foreach([0=>'#'.$id,1=>'#'.$own['order']['id'].' / #'.$own['line']['id'],2=>'#'.$own['schema']['id']] as $index=>$value)self::assertSame((string)$value,$cells->item($index)->textContent);
        self::assertStringContainsString('Ada',$rows->item(0)->textContent);self::assertStringContainsString('name',strtolower($rows->item(0)->textContent));self::assertStringContainsString($own['submission']['payload_hash'],$rows->item(0)->textContent);self::assertStringContainsString('UNREVIEWED',$rows->item(0)->textContent);
        self::assertStringNotContainsString('Foreign typed name',$html);self::assertStringNotContainsString('name="submission_id" value="'.$foreign['submission']['id'].'"',$html);
        self::assertStringContainsString('Payload SHA-256 '.$own['submission']['payload_hash'],$html);
        $body=$this->body($own,$html);$otherBody=$this->body($other,$html);self::assertTrue(DigiForge\Portal\ReviewConfirmation::verify('digiforge_portal_personalization_review',$id,$body));self::assertTrue(DigiForge\Portal\ReviewConfirmation::verify('digiforge_portal_personalization_review',(int)$other['submission']['id'],$otherBody));self::assertNotSame($body['df_confirmation_tokens'],$otherBody['df_confirmation_tokens']);self::assertTrue(wp_verify_nonce($body['_wpnonce'],'digiforge_portal_personalization_review')!==false);$otherBefore=$this->stored((int)$other['submission']['id']);
        $before=$this->receipts($id);PortalActionAcceptanceFixture::invoke('PERSONALIZATION_REVIEW_ACTION',$body,false,false);
        $reviewed=$this->stored($id);self::assertSame('APPROVED',$reviewed['review_status']);self::assertSame(get_current_user_id(),(int)$reviewed['reviewed_by']);self::assertSame($own['submission']['canonical_payload'],$reviewed['canonical_payload']);self::assertSame($before+1,$this->receipts($id));
        PortalActionAcceptanceFixture::invoke('PERSONALIZATION_REVIEW_ACTION',$body,true,false);self::assertSame($reviewed,$this->stored($id));self::assertSame($before+1,$this->receipts($id));self::assertSame('UNREVIEWED',$this->stored((int)$foreign['submission']['id'])['review_status']);
        self::assertSame('UNREVIEWED',$otherBefore['review_status']);self::assertSame($otherBefore,$this->stored((int)$other['submission']['id']));self::assertSame(0,$this->receipts((int)$other['submission']['id']));
        global $wpdb;self::assertSame('RECEIVED',$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Tables::orders().' WHERE id=%d',$own['order']['id'])));
        $audit=json_decode($wpdb->get_var($wpdb->prepare("SELECT context FROM ".Tables::audit_log()." WHERE event_type='portal_personalization_human_reviewed' AND object_id=%s ORDER BY id DESC LIMIT 1",(string)$id)),true);self::assertFalse($audit['external_execution_authorized']);self::assertFalse($audit['external_execution_performed']);
    }
    public function testInvalidTypedInputAndForeignOrUncheckedConfirmationCannotApprove(): void
    {
        $own=$this->fixture();$foreign=$this->fixture('jewelry','Foreign pending');$repo=new Repository();global $wpdb;$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::personalization_submissions());
        foreach([[],['question_7'=>[]],['question_7'=>str_repeat('x',31)],['question_7'=>'Ada','undeclared'=>'value']] as $answers)self::assertWPError($repo->createTypedPersonalization($own['arguments'],$own['questions'],$answers));
        $changed=$own['questions'];$changed[0]['question_text']='Changed question';self::assertWPError($repo->createTypedPersonalization($own['arguments'],$changed,['question_7'=>'Ada']));self::assertSame($before,(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Tables::personalization_submissions()));
        $body=$this->body($own,$this->render());$unchecked=array_replace($body,['df_confirm_review'=>'0']);PortalActionAcceptanceFixture::invoke('PERSONALIZATION_REVIEW_ACTION',$unchecked,true,false);
        $swapped=array_replace($body,['submission_id'=>$foreign['submission']['id']]);PortalActionAcceptanceFixture::invoke('PERSONALIZATION_REVIEW_ACTION',$swapped,true,false);
        foreach([$own,$foreign] as $f){$id=(int)$f['submission']['id'];self::assertSame('UNREVIEWED',$this->stored($id)['review_status']);self::assertSame(0,$this->receipts($id));}
        self::assertWPError(DigiForge\Portal\ReviewConfirmation::inspect('digiforge_portal_personalization_review',(int)$foreign['submission']['id'],'jewelry',['decision'=>'APPROVED']));
    }
    public function testStaleSchemaConfirmationAndFreshIncompatibleSchemaCannotApprovePayload(): void
    {
        $own=$this->fixture();$id=(int)$own['submission']['id'];$body=$this->body($own,$this->render());global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::personalization_schemas(),['field_definitions'=>wp_json_encode([['key'=>'name','type'=>'text','required'=>true,'max_length'=>2,'answer_key'=>'question_7']])],['id'=>$own['schema']['id']]));
        $schema=json_decode($wpdb->get_var($wpdb->prepare('SELECT field_definitions FROM '.Tables::personalization_schemas().' WHERE id=%d',$own['schema']['id'])),true);self::assertSame(2,$schema[0]['max_length']);
        self::assertWPError(DigiForge\Portal\ReviewConfirmation::verify('digiforge_portal_personalization_review',$id,$body));
        PortalActionAcceptanceFixture::invoke('PERSONALIZATION_REVIEW_ACTION',$body,true,false);self::assertSame('UNREVIEWED',$this->stored($id)['review_status']);self::assertSame(0,$this->receipts($id));
        $fresh=$this->body($own,$this->render());self::assertNotSame($body['df_confirmation_tokens'],$fresh['df_confirmation_tokens']);PortalActionAcceptanceFixture::invoke('PERSONALIZATION_REVIEW_ACTION',$fresh,true,false);self::assertSame('UNREVIEWED',$this->stored($id)['review_status']);self::assertSame('{"name":"Ada"}',$this->stored($id)['canonical_payload']);
    }
    public function testPayloadPresentationEscapesTextHidesProtectedReferencesAndFailsClosed(): void
    {
        $own=$this->fixture('personalized_pod','Ada <script>x()</script>');$id=(int)$own['submission']['id'];$html=$this->render();
        self::assertStringContainsString('&lt;script&gt;x()&lt;/script&gt;',$html);self::assertStringNotContainsString('<script>x()</script>',$html);
        // A synthetic stored reference exercises presentation only, never image/rights certification.
        $reference='digiforge/product-factory/pod-render-artifacts/shop-personalized_pod/synthetic-private.png';
        $payload=DigiForge\Orders\Validator::canonicalJson(['name'=>'Ada <script>x()</script>','photo'=>$reference]);global $wpdb;
        self::assertSame(1,$wpdb->update(Tables::personalization_submissions(),['canonical_payload'=>$payload,'payload_hash'=>hash('sha256',$payload)],['id'=>$id]));
        $html=$this->render();self::assertStringContainsString('Protected image reference SHA-256 '.hash('sha256',$reference),$html);self::assertStringNotContainsString($reference,$html);self::assertStringNotContainsString('synthetic-private.png',$html);
        self::assertSame(1,$wpdb->update(Tables::personalization_submissions(),['payload_hash'=>str_repeat('0',64)],['id'=>$id]));$html=$this->render();
        self::assertStringContainsString('UNAVAILABLE: payload integrity requires review.',$html);self::assertStringNotContainsString('Protected image reference SHA-256 '.hash('sha256',$reference),$html);self::assertStringNotContainsString('&lt;script&gt;x()&lt;/script&gt;',$html);
        $table=Tables::personalization_submissions();$fault=static fn(string $sql):string=>str_contains($sql,'SELECT p.id,p.order_line_item_id,li.order_id')?str_replace($table,$table.'_synthetic_missing',$sql):$sql;
        $prior=$wpdb->suppress_errors(true);add_filter('query',$fault);
        try{$html=$this->render();}finally{remove_filter('query',$fault);$wpdb->suppress_errors($prior);}
        self::assertStringContainsString('One or more human-gate evidence sources are unavailable.',$html);self::assertStringNotContainsString('No pending personalization reviews',$html);self::assertStringNotContainsString('name="submission_id" value="'.$id.'"',$html);
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));$html=$this->render();self::assertStringContainsString('explicit assignment',strtolower($html));self::assertStringNotContainsString('synthetic-private.png',$html);self::assertStringNotContainsString('Normalized personalization',$html);
    }
}
