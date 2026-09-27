<?php
declare(strict_types=1);
namespace DigiForge\AI;

use DigiForge\Database\Tables;

/** Read-only lifecycle counts; these are denominators, not cost attribution claims. */
final class LifecycleDenominatorReadModel
{
    /** @return array<string,int|bool> */
    public function snapshot():array
    {
        global $wpdb;
        $count=static fn(string $sql):int=>(int)$wpdb->get_var($sql);
        return [
            'opportunities'=>$count('SELECT COUNT(*) FROM '.Tables::opportunities()),
            'developed_products'=>$count('SELECT COUNT(*) FROM '.Tables::products()),
            'approved_listings'=>$count("SELECT COUNT(*) FROM ".Tables::listings()." WHERE state='APPROVED'"),
            'received_orders'=>$count('SELECT COUNT(*) FROM '.Tables::orders()),
            'denominators_authoritative'=>true,
            'ai_cost_attribution_established'=>false,
            'external_execution_performed'=>false,
        ];
    }
}
