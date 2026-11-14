<?php

declare(strict_types=1);
namespace DigiForge\Tests;
use PHPUnit\Framework\TestCase;
use DigiForge\POD\ProductIntelligence;
require_once __DIR__.'/../../includes/POD/ProductIntelligence.php';
final class ProductIntelligenceTest extends TestCase
{
    private function packet(): array
    {
        $rows=[['V2 ID'=>'DG2-001','Source DG ID'=>'DG-001','Physical Product'=>'PP-001','Price USD'=>null]];
        $bytes=json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return ['schema_version'=>1,'shop_key'=>'personalized_pod','business_key'=>'digicraftifygoods','catalog_key'=>'digicraftifygoods-master-500-v2','sources'=>[['id'=>'drive-id','name'=>'source.xlsx','sha256'=>str_repeat('a',64),'modified_at'=>'2026-11-02T17:01:30Z']], 'sections'=>['concepts'=>['source_id'=>'drive-id','sheet'=>'Master_500_v2','rows'=>$rows,'rows_sha256'=>hash('sha256',$bytes)]]];
    }
    public function testPreservesNullAndOriginalIdentity(): void
    {
        $p=ProductIntelligence::validate($this->packet());
        self::assertNull($p['sections']['concepts']['rows'][0]['Price USD']);
        self::assertSame('DG-001',$p['sections']['concepts']['rows'][0]['Source DG ID']);
        self::assertFalse($p['production_authority']);
        self::assertSame('UNREVIEWED',$p['approval_status']);
    }
    public function testPreservesSourceResearchPlaceholderWithoutInventingProductId(): void
    {
        $p=$this->packet();$p['sections']['concepts']['rows'][0]['Physical Product']='V2-PRODUCT-RESEARCH';$p['sections']['concepts']['rows'][0]['Source DG ID']=null;
        $p['sections']['concepts']['rows_sha256']=ProductIntelligence::rowsHash($p['sections']['concepts']['rows']);
        self::assertSame('V2-PRODUCT-RESEARCH',ProductIntelligence::validate($p)['sections']['concepts']['rows'][0]['Physical Product']);
    }
    public function testRejectsForeignShop(): void
    {
        $p=$this->packet();$p['shop_key']='digital';$this->expectException(\InvalidArgumentException::class);ProductIntelligence::validate($p);
    }
    public function testRejectsTamperedRows(): void
    {
        $p=$this->packet();$p['sections']['concepts']['rows'][0]['Price USD']=0;$this->expectException(\InvalidArgumentException::class);ProductIntelligence::validate($p);
    }
    public function testRejectsDuplicateConceptIds(): void
    {
        $p=$this->packet();$p['sections']['concepts']['rows'][]=$p['sections']['concepts']['rows'][0];$p['sections']['concepts']['rows_sha256']=ProductIntelligence::rowsHash($p['sections']['concepts']['rows']);$this->expectException(\InvalidArgumentException::class);ProductIntelligence::validate($p);
    }
    public function testExportNeutralizesFormulaAndPreservesUnknown(): void
    {
        $csv=ProductIntelligence::csv([['Concept'=>'=HYPERLINK("x")','Price USD'=>null],['Concept'=>"\t+cmd",'Price USD'=>0]]);
        self::assertStringContainsString("'=HYPERLINK",str_replace('""','"',$csv));
        self::assertStringContainsString("'\t+cmd",$csv);
        self::assertStringContainsString(',0',$csv);
    }
    public function testBatchNeverGrantsExecutionAndIsBounded(): void
    {
        $plan=ProductIntelligence::batch([5,6,5],2);
        self::assertSame([5,6],$plan['blueprint_ids']);self::assertFalse($plan['external_execution_authorized']);self::assertSame('PLAN_ONLY',$plan['state']);
        $this->expectException(\InvalidArgumentException::class);ProductIntelligence::batch(range(1,21),20);
    }
    public function testRejectsContradictoryMatchingLineage(): void
    {
        $p=$this->packet();$rows=[['V2 ID'=>'DG2-001','Source DG ID'=>'DG-999']];
        $p['sections']['matches']=['source_id'=>'drive-id','sheet'=>'matches','rows'=>$rows,'rows_sha256'=>ProductIntelligence::rowsHash($rows)];
        $this->expectException(\InvalidArgumentException::class);ProductIntelligence::validate($p);
    }
    public function testCsvIncludesExportProvenance(): void
    {
        self::assertStringContainsString('snapshot123',ProductIntelligence::csv([['Concept'=>'one']],['_snapshot_id'=>'snapshot123']));
    }
    public function testSearchAndPaginationAreBounded(): void
    {
        $p=ProductIntelligence::validate($this->packet());$result=ProductIntelligence::page($p,'concepts','DG2',1);
        self::assertSame(1,$result['total']);self::assertCount(1,$result['rows']);
        self::assertSame(0,ProductIntelligence::page($p,'concepts','absent',1)['total']);
    }
}
