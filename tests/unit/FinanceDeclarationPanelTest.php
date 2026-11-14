<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use DigiForge\Portal\FinanceDeclarationPanel;

final class FinanceDeclarationPanelTest extends TestCase
{
    private function loadPanel():void
    {
        $path=__DIR__.'/../../includes/Portal/FinanceDeclarationPanel.php';
        self::assertFileExists($path);
        require_once $path;
    }

    public function testFiltersKeepExactScopeAndRejectMalformedInputs():void
    {
        $this->loadPanel();
        $query=['df_finance_environment'=>'sandbox','df_finance_start'=>'2026-02-01','df_finance_end'=>'2026-02-28','df_finance_currency'=>'EUR'];
        self::assertSame(['environment'=>'sandbox','period_start'=>'2026-02-01','period_end'=>'2026-02-28','base_currency'=>'EUR'],FinanceDeclarationPanel::filters($query));
        foreach(['df_finance_environment'=>'Sandbox','df_finance_start'=>'2026-02-30','df_finance_end'=>'2026-01-01','df_finance_currency'=>'usd'] as $key=>$value)self::assertNull(FinanceDeclarationPanel::filters(array_replace($query,[$key=>$value])));
        foreach(array_keys($query) as $key)self::assertNull(FinanceDeclarationPanel::filters(array_replace($query,[$key=>[]])));
        foreach(array_keys($query) as $key)self::assertNull(FinanceDeclarationPanel::filters(array_replace($query,[$key=>null])));
        self::assertNull(FinanceDeclarationPanel::filters(array_replace($query,['df_finance_start'=>"2026-02-01\0"])));
    }

    private function report():array
    {
        return ['schema'=>'finance-declaration-report-v1','query_state'=>'AVAILABLE','state'=>'PROVISIONAL',
            'scope'=>['shop_key'=>'digital','environment'=>'test','period_start'=>'2026-09-01','period_end'=>'2026-09-30','base_currency'=>'USD'],
            'totals'=>['gross_revenue'=>'9007199254740993.0001','net_profit'=>'0.0000','margin_percent'=>'0.0000'],
            'groups'=>['order'=>[['dimension_id'=>'<script>order</script>','entry_count'=>1,'metrics'=>['gross_revenue'=>'12.0000','net_profit'=>'2.0000','margin_percent'=>'16.6667']]]],
            'matching'=>['state'=>'DECLARATION_ONLY','issues'=>[['code'=>'MISSING','message'=>'<img src=x onerror=alert(1)>']],'rows'=>[['ledger_id'=>1,'declaration_hash'=>str_repeat('a',64),'statuses'=>['MISSING']]]],
            'gst_review'=>['state'=>'EVIDENCE_ONLY','rows'=>[]],'truncated'=>false,
            'external_execution_authorized'=>true,'tax_filing_authorized'=>true];
    }

    private function html(array $report):string
    {
        $filters=['environment'=>'test','period_start'=>'2026-09-01','period_end'=>'2026-09-30','base_currency'=>'USD'];
        return FinanceDeclarationPanel::html($report,$filters,'digital','finance','https://example.test/?page_id=77');
    }

    public function testProvisionalAmountsRemainExactAndEvidenceCannotCreateAuthority():void
    {
        $this->loadPanel();$html=$this->html($this->report());
        foreach(['9007199254740993.0001','0.0000','PROVISIONAL','Authenticity: UNKNOWN','Source completeness: UNKNOWN','External execution authority: NO','name="page_id" value="77"'] as $text)self::assertStringContainsString($text,$html);
        self::assertStringContainsString('&lt;script&gt;order&lt;/script&gt;',$html);
        self::assertStringNotContainsString('<script>',$html);
        self::assertStringNotContainsString('<img src=x',$html);
        self::assertStringNotContainsString('method="post"',$html);
        self::assertStringContainsString('<td>margin percent</td><td>0.0000%</td>',$html);
        self::assertStringContainsString('16.6667%',$html);
        $missing=$this->report();$missing['totals']['margin_percent']=null;
        self::assertStringContainsString('<td>margin percent</td><td>UNKNOWN</td>',$this->html($missing));
    }

    public function testUnavailableBlockedAndWrongScopeNeverDisplayTotalsAsZero():void
    {
        $this->loadPanel();
        foreach(['unavailable','blocked','shop','environment'] as $failure){
            $report=$this->report();
            if($failure==='unavailable')$report['query_state']='UNAVAILABLE';
            elseif($failure==='blocked')$report['state']='BLOCKED';
            else $report['scope'][$failure==='shop'?'shop_key':'environment']=$failure==='shop'?'jewelry':'production';
            $html=$this->html($report);
            self::assertStringNotContainsString('9007199254740993.0001',$html);
            self::assertStringNotContainsString('0.0000',$html);
            self::assertStringContainsString('UNKNOWN',$html);
        }
    }
}
