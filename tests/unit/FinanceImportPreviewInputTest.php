<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use DigiForge\Portal\FinanceImportPreviewInput;

final class FinanceImportPreviewInputTest extends TestCase
{
    private function input():array{return ['action'=>'digiforge_finance_allocation_preview','_wpnonce'=>'synthetic-nonce','shop_key'=>'digital','environment'=>'test','business_id'=>'1','store_id'=>'2','source_hash'=>str_repeat('a',64),'lines_json'=>'[{"target":{},"amount":"0.0000","base_amount":"0.0000"}]','original_packet_json'=>''];}
    private function loadInput():void{$path=__DIR__.'/../../includes/Portal/FinanceImportPreviewInput.php';self::assertFileExists($path);require_once $path;}
    public function testExactBrowserFieldsNormalizeOnlyTransportTypes():void
    {
        $this->loadInput();$request=FinanceImportPreviewInput::normalize($this->input(),'digital');
        self::assertSame(['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'environment'=>'test'],$request['scope']);
        self::assertSame('0.0000',$request['lines'][0]['amount']);self::assertNull($request['original_packet']);
    }
    public function testMalformedScopeOrPayloadCannotReachReadModel():void
    {
        $this->loadInput();
        foreach([['shop_key'=>'jewelry'],['environment'=>'Test'],['business_id'=>'01'],['store_id'=>'1e2'],['source_hash'=>'bad'],['lines_json'=>'{}'],['lines_json'=>'['],['lines_json'=>str_repeat(' ',65537)],['original_packet_json'=>'[]'],['unexpected'=>'secret'],['_wpnonce'=>[]]] as $change){
            try{FinanceImportPreviewInput::normalize(array_replace($this->input(),$change),'digital');self::fail('Malformed explicit preview request must reject.');}
            catch(InvalidArgumentException){self::assertTrue(true);}
        }
    }
}
