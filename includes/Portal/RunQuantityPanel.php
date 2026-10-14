<?php
declare(strict_types=1);
namespace DigiForge\Portal;

final class RunQuantityPanel
{
    public static function render(string $shop):void
    {
        $snapshot=(new RunQuantityReadModel())->snapshot($shop==='all'?null:$shop);
        echo '<section class="df-panel"><h2>Per-run item quantities</h2>';
        if($snapshot['query_state']!=='AVAILABLE'){echo '<p>Run quantity evidence unavailable. Counts cannot be verified.</p></section>';return;}
        echo '<p>Canonical-shop usage/accounting quantities, including reservations; not certified completed outputs or provider charges. Legacy or unknown shop aliases require reconciliation and are not reassigned. Read only; external execution authority: NO</p><p>Run identity UNKNOWN — Unattributed quantity: '.esc_html((string)$snapshot['unattributed_quantity']).'</p>';
        if(!$snapshot['items'])echo '<p>No attributed run quantities</p>';
        else{echo '<div class="df-table-wrap" role="region" tabindex="0" aria-label="Recorded per-run usage quantities"><table class="df-table"><thead><tr><th>Shop</th><th>Workflow</th><th>Stage</th><th>Run fingerprint</th><th>Started UTC</th><th>Recorded usage quantity</th></tr></thead><tbody>';foreach($snapshot['items']as $item){echo '<tr>';foreach(['shop_key','workflow','stage','run_fingerprint','started_at','quantity']as $key)echo '<td>'.esc_html((string)$item[$key]).'</td>';echo '</tr>';}echo '</tbody></table></div>';}
        if($snapshot['truncated'])echo '<p>Run quantity window truncated; additional stored runs exist.</p>';
        echo '</section>';
    }
}
