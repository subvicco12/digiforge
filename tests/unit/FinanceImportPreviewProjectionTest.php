<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use DigiForge\Portal\FinanceImportPreviewProjection;

final class FinanceImportPreviewProjectionTest extends TestCase
{
    private function packet():array
    {
        return ['schema'=>'digiforge-finance-allocation-proposal-v1','source_ledger_id'=>7,
            'source'=>['scope'=>['shop_key'=>'digital','business_id'=>1,'store_id'=>2],'environment'=>'test','currency'=>'USD','base_currency'=>'USD','declaration_hash'=>str_repeat('a',64),'source'=>['document_ref'=>'private-source-document'],'private'=>'never-echo-this'],
            'allocated_amount'=>'9999999999.9999','allocated_base_amount'=>'9999999999.9999','proposal_hash'=>str_repeat('b',64),'reversal_of_proposal_hash'=>'',
            'lines'=>[['target'=>['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'environment'=>'test','currency'=>'USD','order_id'=>8,'order_line_item_id'=>9,'product_id'=>10,'product_version_id'=>11,'listing_id'=>12,'provider_mapping_id'=>0,'variant_ref'=>''],'reference_hash'=>str_repeat('c',64),'amount'=>'9999999999.9999','base_amount'=>'9999999999.9999','private'=>'never-echo-this']],
            'state'=>'PROVISIONAL','review_status'=>'UNREVIEWED','reconciliation_state'=>'EXPLICIT_SUM_MATCH','authenticity'=>'AUTHENTICATED','external_execution_authorized'=>true];
    }

    private function scope():array{return ['shop_key'=>'digital','business_id'=>1,'store_id'=>2,'environment'=>'test'];}
    private function loadProjection():void
    {
        $path=__DIR__.'/../../includes/Portal/FinanceImportPreviewProjection.php';self::assertFileExists($path);require_once $path;
    }

    public function testFixedProjectionKeepsExactAmountsAndOmitsRawSourceAndAuthority():void
    {
        $this->loadProjection();$result=FinanceImportPreviewProjection::fromPacket($this->packet(),$this->scope(),str_repeat('a',64));
        self::assertSame('9999999999.9999',$result['allocated_amount']);self::assertSame('9999999999.9999',$result['lines'][0]['amount']);
        self::assertSame('UNKNOWN',$result['authenticity']);self::assertSame('UNKNOWN',$result['source_completeness']);self::assertFalse($result['external_execution_authorized']);
        self::assertArrayNotHasKey('source',$result);self::assertStringNotContainsString('never-echo-this',json_encode($result));self::assertStringNotContainsString('private-source-document',json_encode($result));
    }

    public function testForeignOrMalformedPacketCannotBecomeAPreview():void
    {
        $this->loadProjection();
        foreach(['shop','environment','hash','amount','line'] as $fault){
            $packet=$this->packet();
            if($fault==='shop')$packet['source']['scope']['shop_key']='jewelry';
            elseif($fault==='environment')$packet['source']['environment']='production';
            elseif($fault==='hash')$packet['source']['declaration_hash']=str_repeat('d',64);
            elseif($fault==='amount')$packet['allocated_amount']=1.5;
            else $packet['lines'][0]['target']['store_id']=99;
            try{FinanceImportPreviewProjection::fromPacket($packet,$this->scope(),str_repeat('a',64));self::fail('Invalid scope or typed projection must fail closed.');}
            catch(InvalidArgumentException){self::assertTrue(true);}
        }
    }
}
