<?php

declare(strict_types=1);

use DigiForge\POD\Shop2EtsyDraftHandoff;
use PHPUnit\Framework\TestCase;

if (!class_exists('WP_Error')) {
    class WP_Error { public function __construct(...$args) {} }
}
if (!class_exists(\DigiForge\Listings\EtsyDraftListingOperations::class)) {
    require_once dirname(__DIR__, 2) . '/includes/Listings/EtsyDraftListingOperations.php';
}
if (is_file(dirname(__DIR__, 2) . '/includes/POD/Shop2EtsyDraftHandoff.php')) {
    require_once dirname(__DIR__, 2) . '/includes/POD/Shop2EtsyDraftHandoff.php';
}

final class Shop2EtsyDraftHandoffTest extends TestCase
{
    private function declarations(): array
    {
        return ['quantity'=>10, 'who_made'=>'someone_else', 'when_made'=>'made_to_order',
            'taxonomy_id'=>1062, 'shipping_profile_id'=>23, 'readiness_state_id'=>24];
    }

    private function export(string $fixture = 'FL-03'): array
    {
        $package = ['schema'=>'approved-design-listing-package-v1', 'state'=>'REVIEW_REQUIRED',
            'shop_key'=>'personalized_pod', 'fixture_id'=>$fixture,
            'listing'=>['title'=>$fixture === 'FL-03' ? 'Personalized pet mug' : 'Personalized family poster',
                'description'=>'Made for your family. Please review your personalization.',
                'tags'=>['custom gift','pet portrait','family gift','personalized','birthday gift','home decor','unique present','animal lover','custom artwork','keepsake gift','made for you','special occasion','thoughtful gift']],
            'economics'=>['currency'=>'USD', 'assumptions'=>['sale_price'=>24.95]],
            'controls'=>['stop_all'=>true,'external_lock'=>true,'automation'=>false,'release_hold'=>true,'order_approval'=>'MANUAL'],
            'external_execution_authorized'=>false,'external_execution_performed'=>false,
            'blockers'=>['SUPPLIER_CERTIFICATION_REQUIRED','LIVE_HANDOFF_SEPARATE_AUTHORITY_REQUIRED']];
        $package['sha256'] = hash('sha256', \DigiForge\Listings\Validator::canonicalJson($package));
        $hash = str_repeat('a',64);
        return ['schema'=>'printify-native-held-review-export-v1','shop_key'=>'personalized_pod',
            'package_hash'=>$hash,'package'=>$package,
            'approval'=>['state'=>'HUMAN_APPROVED','decision'=>'APPROVE','evidence_hash'=>$hash,'approved_by'=>7,
                'publishing_enabled'=>false,'order_execution_enabled'=>false],
            'remaining_gates'=>$package['blockers'],'commercial_approved'=>false,'supplier_certified'=>false,
            'release_hold'=>true,'external_execution_authorized'=>false,'external_execution_performed'=>false];
    }

    public function testApprovedMugAndPosterPrepareExactDraftContentWithEveryHold(): void
    {
        self::assertTrue(class_exists(Shop2EtsyDraftHandoff::class), 'Draft handoff helper must exist.');
        foreach (['FL-03','FL-04'] as $fixture) {
            $export = $this->export($fixture);
            $result = Shop2EtsyDraftHandoff::prepare($export, 12345, $this->declarations());
            self::assertIsArray($result);
            self::assertSame($export['package_hash'], $result['package_hash']);
            self::assertSame($export['remaining_gates'], $result['remaining_gates']);
            self::assertTrue($result['release_hold']);
            foreach (['publish_permitted','network_request_permitted','external_execution_authorized','external_execution_performed'] as $flag) self::assertFalse($result[$flag]);
            $operation = $result['draft_operation'];
            self::assertSame('CREATE_DRAFT', $operation['operation']);
            self::assertSame('/application/shops/12345/listings', $operation['endpoint']);
            $payload = $operation['payload'];
            foreach (['title','description','tags'] as $field) self::assertSame($export['package']['listing'][$field], $payload[$field]);
            self::assertSame('24.95', $payload['price']);
            self::assertSame('physical', $payload['type']);
            self::assertFalse($payload['is_supply']);
            foreach ($this->declarations() as $key=>$value) self::assertSame($value, $payload[$key]);
            self::assertCount(12, $payload);
        }
    }

    public function testForeignUnapprovedOrReleasedExportsAreDenied(): void
    {
        $changes = [ ['shop_key','other'], ['schema','other'], ['release_hold',false],
            ['external_execution_authorized',true], ['external_execution_performed',true], ['package_hash','bad'] ];
        foreach ($changes as [$key,$value]) {
            $export=$this->export(); $export[$key]=$value;
            self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($export,123,$this->declarations()));
        }
        foreach (['state'=>'HUMAN_REJECTED','decision'=>'REJECT','evidence_hash'=>str_repeat('b',64),
            'approved_by'=>0,'publishing_enabled'=>true,'order_execution_enabled'=>true] as $key=>$value) {
            $export=$this->export(); $export['approval'][$key]=$value;
            self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($export,123,$this->declarations()));
        }
    }

    public function testRequiredMetadataAndPayloadInjectionAreDenied(): void
    {
        foreach (array_keys($this->declarations()) as $key) {
            $declarations=$this->declarations(); unset($declarations[$key]);
            self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($this->export(),123,$declarations));
        }
        foreach (['state'=>'active','publish'=>true,'url'=>'https://example.com','processing_profile_id'=>1,
            'native_personalization'=>[], 'price'=>0,'_digiforge'=>[],'access_token'=>'secret'] as $key=>$value) {
            $declarations=$this->declarations(); $declarations[$key]=$value;
            self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($this->export(),123,$declarations));
        }
        foreach (['quantity'=>0,'taxonomy_id'=>'12','shipping_profile_id'=>-1,'readiness_state_id'=>0,
            'who_made'=>'unknown','when_made'=>'yesterday'] as $key=>$value) {
            $declarations=$this->declarations(); $declarations[$key]=$value;
            self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($this->export(),123,$declarations));
        }
        self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($this->export(),0,$this->declarations()));
    }

    public function testCorruptContentAndMoneyAreDeniedEvenWithMatchingSnapshotSeal(): void
    {
        foreach ([0,-1,NAN,INF,'24.95',24.955] as $price) {
            $export=$this->export(); $export['package']['economics']['assumptions']['sale_price']=$price;
            if (is_finite((float) $price)) {
                unset($export['package']['sha256']);
                $export['package']['sha256']=hash('sha256',\DigiForge\Listings\Validator::canonicalJson($export['package']));
            }
            self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($export,123,$this->declarations()));
        }
        foreach (['title'=>'','description'=>[], 'tags'=>['one tag']] as $key=>$value) {
            $export=$this->export(); $export['package']['listing'][$key]=$value;
            unset($export['package']['sha256']);
            $export['package']['sha256']=hash('sha256',\DigiForge\Listings\Validator::canonicalJson($export['package']));
            self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($export,123,$this->declarations()));
        }
        $export=$this->export(); $export['package']['listing']['title']='Unapproved changed title';
        self::assertInstanceOf(WP_Error::class, Shop2EtsyDraftHandoff::prepare($export,123,$this->declarations()));
    }
}
