<?php
declare(strict_types=1);
namespace DigiForge\Portal;

final class CommercialKpiView
{
    public static function render(array $data):void
    {
        echo '<section class="df-panel"><h2>Reviewed commercial AI cost</h2><p>Distinct verified paid orders define sales. Costs require retained reviewed provider statements and exact stored lineage. Missing evidence is unavailable, not zero. Revenue and profit calculations remain finance-deferred. External execution authority: NO.</p>';
        if(($data['query_state']??'UNAVAILABLE')!=='AVAILABLE')echo '<p role="status" class="df-notice">Select one shop with available accounting evidence. No cross-shop or cross-currency total is certified.</p>';
        echo '<p>Shop: '.esc_html((string)($data['shop_key']??'unknown')).' · currency: '.esc_html((string)($data['currency']??'unknown')).' · UTC period: '.esc_html((string)($data['period']['start_utc']??'unknown')).' to '.esc_html((string)($data['period']['end_exclusive_utc']??'unknown')).' (end exclusive)</p><div class="df-table-wrap" role="region" tabindex="0" aria-label="Reviewed commercial KPI evidence"><table class="df-table"><thead><tr><th>Metric</th><th>Evidence state</th><th>Reviewed cost</th><th>Distinct outcomes</th><th>Cost per outcome</th></tr></thead><tbody>';
        foreach((array)($data['metrics']??[])as $name=>$metric)echo '<tr><td>'.esc_html(str_replace('_',' ',(string)$name)).'</td><td>'.esc_html((string)$metric['state']).'</td><td>'.esc_html($metric['cost']??'UNAVAILABLE').'</td><td>'.esc_html(isset($metric['denominator'])?(string)$metric['denominator']:'UNAVAILABLE').'</td><td>'.esc_html($metric['cost_per_outcome']??'UNAVAILABLE').'</td></tr>';
        echo '</tbody></table></div><p class="df-muted">'.esc_html((string)($data['cohort_basis']??'No attributable cohort verified.')).'</p></section>';
    }
}
