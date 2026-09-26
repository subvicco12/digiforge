<?php
declare(strict_types=1);

namespace DigiForge\Operations;

/** Read-only runtime evidence that at least one governed external Etsy request was attempted. */
final class ExternalActionEvidence
{
    public static function performed(): bool
    {
        global $wpdb;
        $table=$wpdb->prefix.'digiforge_etsy_operations';
        $exists=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE state IN (%s,%s,%s,%s,%s) LIMIT 1",
            'SENT','UNKNOWN','RECONCILIATION','RECONCILED','CONFIRMED_SUCCESS'
        ));
        return (int)$exists>0;
    }
}
