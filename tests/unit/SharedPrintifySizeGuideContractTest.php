<?php
declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\PrintifyCatalogReadContract as Contract;
use DigiForge\POD\PrintifyCatalogGetSafetyExceptionContract as ExceptionContract;
require_once __DIR__.'/../../includes/POD/PrintifyCatalogReadContract.php';
require_once __DIR__.'/../../includes/POD/PrintifyCatalogGetSafetyExceptionContract.php';
final class SharedPrintifySizeGuideContractTest extends TestCase
{
    private function request(): array {return ['kind'=>'size_guide','blueprint_id'=>5];}
    private function descriptor(): array {return ['capability'=>'printify.catalog.read','integration_id'=>1,'account_id'=>2,'business_id'=>'digicraftifygoods','store_id'=>'shop2','product_program'=>'PERSONALIZED_POD','expires_at'=>'2026-11-03T14:30:00Z','max_requests'=>10,'requests'=>[$this->request()]];}
    private function guide(): array {return ['sizes'=>['S','M'],'types'=>[['name'=>'Width','units'=>'in','ranges'=>[['from'=>'18','to'=>'20'],['from'=>null,'to'=>'']]]]];}
    public function testSizeGuideIsAnExactGetScopeUnderTheExistingGrantLimit(): void
    {
        self::assertSame('/v1/catalog/blueprints/5/size_guide.json',Contract::endpoint($this->request()));
        $descriptor=Contract::normalize($this->descriptor(),strtotime('2026-11-03T14:00:00Z'));self::assertSame(10,$descriptor['max_requests']);self::assertSame($this->request(),$descriptor['requests'][0]);
    }
    public function testSizeGuidePreservesOrderUnitsAndMissingBounds(): void
    {
        self::assertSame($this->guide(),Contract::select($this->guide(),$this->request()));
        $guide=$this->guide();$guide['types'][0]['units']=null;self::assertNull(Contract::select($guide,$this->request())['types'][0]['units']);
        self::assertSame(['sizes'=>[],'types'=>[]],Contract::select(['sizes'=>[],'types'=>[]],$this->request()));
    }
    public function testInvalidScopesNeverExpandTheAllowlist(): void
    {
        foreach([['kind'=>'size_guide','blueprint_id'=>0],['kind'=>'size_guide','blueprint_id'=>'5'],['kind'=>'size_guide','blueprint_id'=>5,'url'=>'https://evil.test'],['kind'=>'size_guide','blueprint_id'=>5,'method'=>'POST'],['kind'=>'order','blueprint_id'=>5]] as $request){$denied=false;try{Contract::endpoint($request);}catch(\InvalidArgumentException){$denied=true;}self::assertTrue($denied);}
    }
    public function testMalformedAndOversizedGuidesAreDenied(): void
    {
        $cases=[];$g=$this->guide();$g['types'][0]['ranges']=[];$cases[]=$g;$g=$this->guide();$g['sizes']=['S','S'];$cases[]=$g;$g=$this->guide();$g['types'][0]['ranges'][0]['from']='not-a-measurement';$cases[]=$g;
        $g=$this->guide();$g['types'][0]['ranges'][0]=['from'=>'20','to'=>'10'];$cases[]=$g;$g=$this->guide();$g['types'][0]['ranges'][0]['from']=-1;$cases[]=$g;$g=$this->guide();$g['types'][0]['name']='<script>'; $cases[]=$g;
        $g=$this->guide();$g['next_page_url']='https://evil.test';$cases[]=$g;$g=$this->guide();$g['types'][0]['ranges'][0]['extra']='x';$cases[]=$g;$g=['sizes'=>array_map('strval',range(1,101)),'types'=>[]];$cases[]=$g;
        foreach($cases as $case){$denied=false;try{Contract::select($case,$this->request());}catch(\InvalidArgumentException){$denied=true;}self::assertTrue($denied);}
    }
    public function testElevenRequestCeilingAndDuplicateScopesStillDeny(): void
    {
        foreach(['ceiling','list','duplicate','expiry'] as $case){$g=$this->descriptor();if($case==='ceiling')$g['max_requests']=11;if($case==='list')$g['requests']=array_map(static fn($id)=>['kind'=>'size_guide','blueprint_id'=>$id],range(1,11));if($case==='duplicate')$g['requests'][]=$this->request();if($case==='expiry')$g['expires_at']='2026-11-03T16:00:00Z';$denied=false;try{Contract::normalize($g,strtotime('2026-11-03T14:00:00Z'));}catch(\InvalidArgumentException){$denied=true;}self::assertTrue($denied);}
    }
    public function testSafetyExceptionRemainsGrantBoundAndCannotOutliveGrant(): void
    {
        $approval=['capability'=>'printify.catalog.read','expires_at'=>'2026-11-03T14:15:00Z','acknowledge_catalog_get_only'=>true,'acknowledge_no_mutations'=>true,'acknowledge_locks_remain_on'=>true];
        self::assertSame($approval,ExceptionContract::normalize($approval,$this->descriptor(),strtotime('2026-11-03T14:00:00Z')));
        $approval['expires_at']='2026-11-03T14:45:00Z';$this->expectException(\InvalidArgumentException::class);ExceptionContract::normalize($approval,$this->descriptor(),strtotime('2026-11-03T14:00:00Z'));
    }
}
