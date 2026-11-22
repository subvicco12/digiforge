<?php
declare(strict_types=1);
namespace DigiForge\Finance;

/** Existing declared-profit-v2-exact-1 arithmetic over explicit signed amounts only. */
final class ProvisionalProfitMetrics
{
    /** No source authentication, matching, allocation, FX conversion or period authority. */
    public static function fromAmounts(array $amounts): array
    {
        $metrics=array_fill_keys(['gross_revenue','refunds','fees','cogs','shipping','ads','ai','tax_collected','tax_expense','other_income','other_expense','adjustment'],'0.0000');
        $category=['REVENUE'=>'gross_revenue','REFUND'=>'refunds','MARKETPLACE_FEE'=>'fees','PAYMENT_FEE'=>'fees','COGS'=>'cogs','SHIPPING'=>'shipping','ADS'=>'ads',
            'AI'=>'ai','TAX_COLLECTED'=>'tax_collected','TAX_EXPENSE'=>'tax_expense','OTHER_INCOME'=>'other_income','OTHER_EXPENSE'=>'other_expense','ADJUSTMENT'=>'adjustment'];
        if (!array_is_list($amounts)) throw new \InvalidArgumentException('A list of typed canonical amounts is required.');
        foreach($amounts as$d){
            if (!is_array($d)) throw new \InvalidArgumentException('Typed canonical category amounts required.');
            $keys=array_keys($d);sort($keys);
            if ($keys!==['base_amount','category'] || !is_string($d['category']) || !isset($category[$d['category']])
                || !is_string($d['base_amount']) || ExactDecimal::money($d['base_amount'])!==$d['base_amount']) {
                throw new \InvalidArgumentException('Known category and canonical four-place amount required.');
            }
            $bucket=$category[$d['category']];$metrics[$bucket]=ExactDecimal::add($metrics[$bucket],$d['base_amount']);
            // Preserve v2 buckets: AI is an OTHER_EXPENSE subset; adjustments are an OTHER_INCOME subset.
            if($d['category']==='AI')$metrics['other_expense']=ExactDecimal::add($metrics['other_expense'],$d['base_amount']);
            if($d['category']==='ADJUSTMENT')$metrics['other_income']=ExactDecimal::add($metrics['other_income'],$d['base_amount']);
        }
        $metrics['gross_profit']=ExactDecimal::add(ExactDecimal::add($metrics['gross_revenue'],ExactDecimal::negate($metrics['refunds'])),ExactDecimal::negate($metrics['cogs']));
        $metrics['contribution_profit']=$metrics['gross_profit'];foreach(['fees','shipping','ads']as$bucket)$metrics['contribution_profit']=ExactDecimal::add($metrics['contribution_profit'],ExactDecimal::negate($metrics[$bucket]));
        $metrics['net_profit']=ExactDecimal::add($metrics['contribution_profit'],$metrics['other_income']);foreach(['other_expense','tax_expense']as$bucket)$metrics['net_profit']=ExactDecimal::add($metrics['net_profit'],ExactDecimal::negate($metrics[$bucket]));
        $metrics['margin_percent']=ExactDecimal::percentage($metrics['net_profit'],$metrics['gross_revenue']);
        return $metrics;
    }
}
