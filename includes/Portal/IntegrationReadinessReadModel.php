<?php
declare(strict_types=1);

namespace DigiForge\Portal;

use DigiForge\Database\Tables;

/** Stored connector and test evidence only. No credentials, network calls or authority. */
final class IntegrationReadinessReadModel
{
    public function snapshot(): array
    {
        global $wpdb;
        $rows=$wpdb->get_results('SELECT id,provider,environment,connection_key,display_name,status,enabled,updated_at,config FROM '.Tables::integrations().' ORDER BY id ASC',ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            return ['query_state'=>'UNAVAILABLE','counts'=>null,'items'=>[],
                'read_only'=>true,'connectivity_test_performed'=>false,
                'credentials_exposed'=>false,'external_execution_authorized'=>false];
        }
        $counts=['total'=>count($rows),'enabled'=>0,'configured'=>0,'stored_successful_tests'=>0,'attention'=>0];
        foreach ($rows as &$row) {
            $config=json_decode((string)($row['config']??''),true);
            unset($row['config'],$row['connection_key']);
            $test=is_array($config)&&isset($config['_connection_test'])&&is_array($config['_connection_test'])
                ? $config['_connection_test'] : [];
            $status=strtoupper(trim((string)($row['status']??'')));
            $enabled=!empty($row['enabled']);
            $configured=$status==='CONFIGURED';
            $tested=$configured && ($test['ok']??null)===true
                && is_string($test['checked_at']??null)
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$test['checked_at'])===1;
            if ($enabled) $counts['enabled']++;
            if ($configured) $counts['configured']++;
            if ($enabled && $tested) $counts['stored_successful_tests']++;
            if ($enabled && !$tested) $counts['attention']++;
            $row['evidence_state']=!$enabled?'DISABLED':($tested?'TEST_SUCCESS_RECORDED':($configured?'CONFIGURED_UNVERIFIED':'REVIEW_REQUIRED'));
            $row['attention_reason']=!$enabled?'Connector disabled':($tested?'Stored test succeeded; live connectivity unverified':($configured?'No valid successful stored connection test':'Stored status: '.($status?:'UNKNOWN')));
            $row['last_stored_test_at']=$tested?$test['checked_at']:null;
            $row['read_only']=true;
            $row['connectivity_test_performed']=false;
            $row['credentials_exposed']=false;
            $row['external_execution_authorized']=false;
        }
        unset($row);
        return ['query_state'=>'AVAILABLE','counts'=>$counts,'items'=>$rows,'read_only'=>true,
            'connectivity_test_performed'=>false,'credentials_exposed'=>false,'external_execution_authorized'=>false];
    }
}
