<?php
declare(strict_types=1);
require_once __DIR__.'/EtsyWebhookPersistenceAcceptanceTest.php';

use DigiForge\Database\Tables;
use DigiForge\Orders\Repository;
use DigiForge\Orders\Validator;

/** p133/p168: retain buyer payload and require review; no inferred schema or production authority. */
final class EtsyPersonalizationIntakeAcceptanceTest extends WP_UnitTestCase
{
    private EtsyWebhookPersistenceAcceptanceTest $fixture;
    private int $listing;
    private int $httpCalls=0;
    private Closure $http;
    protected function setUp(): void
    {
        parent::setUp();DigiForge\Core\Activator::activate();DigiForge\Core\Settings::protectProduction();global $wpdb;self::assertNotFalse($wpdb->query('START TRANSACTION'));wp_set_current_user(0);
        $this->fixture=new EtsyWebhookPersistenceAcceptanceTest();$this->listing=(new ReflectionMethod($this->fixture,'historicalDigitalListing'))->invoke($this->fixture);
        $this->http=function(){++$this->httpCalls;return new WP_Error('offline_personalization_intake','External HTTP forbidden.');};add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);self::assertSame(0,$this->httpCalls);self::assertTrue(DigiForge\Core\Settings::safety_locked());parent::tearDown();
    }
    private function intake(array $item,array $top=[]): array
    {
        $payload=$top+['event_type'=>'order.paid','order_id'=>(string)wp_rand(810000,819999),'shop_id'=>'712002','line_items'=>[['listing_id'=>'712003','quantity'=>1,'currency'=>'USD']+$item]];
        $signed=(new ReflectionMethod($this->fixture,'signed'))->invoke($this->fixture,$payload);$result=(new ReflectionMethod($this->fixture,'accept'))->invoke($this->fixture,$signed);self::assertNotWPError($result);
        global $wpdb;$line=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE order_id=%d',$result['order_id']),ARRAY_A);self::assertIsArray($line);self::assertSame($this->listing,(int)$line['listing_id']);self::assertSame(1,$result['line_item_count']);
        $ledger=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::webhook_evidence().' WHERE event_id=%s',$signed[0]),ARRAY_A);self::assertSame(hash('sha256',$signed[1]),$ledger['body_sha256']);self::assertSame('PROCESSED',$ledger['processing_status']);self::assertFalse($result['fulfillment_authorized']);self::assertFalse($result['external_execution_performed']);
        $order=(new Repository())->findByExternalReference($payload['order_id'],$payload['shop_id']);self::assertIsArray($order);return compact('result','line','ledger','order','signed');
    }
    public function testLineOnlyTypedBuyerPayloadIsRetainedExactlyAndRequiresHumanPersonalizationReview(): void
    {
        $payload=['recipient_name'=>'Ada','printed_year'=>2026,'gift'=>true];$f=$this->intake(['personalization'=>$payload]);
        self::assertSame(1,(int)$f['order']['personalization_required']);self::assertSame(Validator::canonicalJson($payload),$f['line']['personalization_payload']);self::assertSame(hash('sha256',Validator::canonicalJson($payload)),hash('sha256',$f['line']['personalization_payload']));
        $decoded=json_decode($f['line']['personalization_payload'],true);foreach($payload as $key=>$value)self::assertSame($value,$decoded[$key]);$readiness=(new Repository())->readiness((int)$f['order']['id']);self::assertNotWPError($readiness);self::assertFalse($readiness['checks']['personalization_reviewed']);self::assertFalse($readiness['ready']);
        $replay=(new ReflectionMethod($this->fixture,'accept'))->invoke($this->fixture,$f['signed']);self::assertNotWPError($replay);self::assertFalse($replay['process_permitted']);
        global $wpdb;self::assertSame($f['line'],$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Tables::order_line_items().' WHERE id=%d',$f['line']['id']),ARRAY_A));
    }
    /** @dataProvider malformedPersonalization */
    public function testMalformedLinePersonalizationForcesReviewWithoutInferringTypedSubmission(mixed $payload): void
    {
        $f=$this->intake(['personalization'=>$payload]);self::assertSame('ETSY_ORDER_REVIEW_REQUIRED',$f['result']['state']);self::assertSame('REVIEW_REQUIRED',$f['order']['state']);self::assertSame(1,(int)$f['order']['personalization_required']);self::assertSame('[]',$f['line']['personalization_payload']);
        $readiness=(new Repository())->readiness((int)$f['order']['id']);self::assertNotWPError($readiness);self::assertFalse($readiness['checks']['personalization_reviewed']);self::assertFalse($readiness['ready']);
        global $wpdb;self::assertSame(0,(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Tables::personalization_submissions().' WHERE order_line_item_id=%d',$f['line']['id'])));
    }
    public function malformedPersonalization(): array{return ['text'=>['buyer text'],'null'=>[null],'false'=>[false],'number'=>[42]];}
    public function testAbsentAndExplicitEmptyLinePayloadKeepOrdinaryDigitalIntakeUnpersonalized(): void
    {
        foreach([[],['personalization'=>[]]]as $item){$f=$this->intake($item);self::assertSame(0,(int)$f['order']['personalization_required']);self::assertSame('VALIDATED',$f['order']['state']);self::assertSame('[]',$f['line']['personalization_payload']);}
    }
    public function testTopLevelRequiredFlagStillRequiresReviewWhenLinePayloadIsEmpty(): void
    {
        $f=$this->intake(['personalization'=>[]],['personalization_required'=>true]);self::assertSame(1,(int)$f['order']['personalization_required']);$readiness=(new Repository())->readiness((int)$f['order']['id']);self::assertNotWPError($readiness);self::assertFalse($readiness['checks']['personalization_reviewed']);
    }
}
