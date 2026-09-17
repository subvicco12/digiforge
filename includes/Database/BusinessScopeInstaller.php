<?php

declare(strict_types=1);

namespace DigiForge\Database;

/** Installs and reconciles v14 ownership tables without activating commerce. */
final class BusinessScopeInstaller
{
    public static function migrateIfNeeded(): bool
    {
        global $wpdb;
        $currentVersion=(int)get_option('digiforge_db_schema_version',0);
        if ($currentVersion<13) return false;
        $tables=[Tables::businesses(),Tables::stores(),Tables::product_programs(),Tables::pod_business_mappings()];
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset=$wpdb->get_charset_collate();
        foreach (BusinessScopeSchema::statements($charset) as $statement) {
            $wpdb->last_error=''; dbDelta($statement);
            if ($wpdb->last_error!=='') return self::fail('BUSINESS_SCOPE_SCHEMA_UPDATE_FAILED');
        }
        foreach ($tables as $table) {
            $present=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
            if ($present!==$table) return self::fail('BUSINESS_SCOPE_SCHEMA_VERIFY_FAILED');
        }
        $requiredColumns=[
            Tables::businesses()=>['id','business_key','status'],
            Tables::stores()=>['id','business_id','store_key','status'],
            Tables::product_programs()=>['id','business_id','store_id','program_key','status'],
            Tables::pod_business_mappings()=>['id','business_id','store_id','product_program_id','product_version_id','provider_mapping_id','idempotency_key'],
        ];
        foreach ($requiredColumns as $table=>$columns) {
            $actual=$wpdb->get_col('SHOW COLUMNS FROM '.$table,0);
            foreach ($columns as $column) if (!in_array($column,$actual,true)) return self::fail('BUSINESS_SCOPE_SCHEMA_COLUMN_VERIFY_FAILED');
        }
        $requiredUnique=[
            Tables::businesses()=>['business_key'=>['business_key']],
            Tables::stores()=>['business_store'=>['business_id','store_key']],
            Tables::product_programs()=>['store_program'=>['business_id','store_id','program_key']],
            Tables::pod_business_mappings()=>[
                'product_provider_owner'=>['product_version_id','provider_mapping_id'],
                'idempotency_key'=>['idempotency_key'],
            ],
        ];
        foreach ($requiredUnique as $table=>$required) {
            $unique=self::uniqueIndexes($table);
            foreach ($required as $name=>$columns) {
                if (($unique[$name]??null)!==$columns) return self::fail('BUSINESS_SCOPE_SCHEMA_INDEX_VERIFY_FAILED');
            }
        }
        update_option('digiforge_db_schema_version',14,false);
        delete_option('digiforge_last_migration_failure');
        return true;
    }

    private static function uniqueIndexes(string $table): array
    {
        global $wpdb;
        $indexes=$wpdb->get_results('SHOW INDEX FROM '.$table,ARRAY_A);
        $unique=[];
        foreach ((array)$indexes as $index) {
            if ((int)($index['Non_unique']??1)!==0) continue;
            $name=(string)($index['Key_name']??''); $seq=(int)($index['Seq_in_index']??0);
            if ($name!=='' && $seq>0) $unique[$name][$seq]=(string)($index['Column_name']??'');
        }
        foreach ($unique as &$columns) { ksort($columns); $columns=array_values($columns); }
        unset($columns);
        return $unique;
    }

    private static function fail(string $code): bool
    {
        update_option('digiforge_last_migration_failure',['error_code'=>$code,'occurred_at'=>current_time('mysql',true)],false);
        return false;
    }
}
