<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Disposable fixture state. A compare-and-swap commits state and audit together. */
final class SyntheticWorkspace
{
    private const TABLE = 'df_printify_fixture_workspace';
    private const LIMIT = 100;

    public function __construct(private readonly object $db, private readonly string $shop, private readonly string $account)
    {
        if (!\defined('DIGIFORGE_PRINTIFY_DISPOSABLE_STORE') || DIGIFORGE_PRINTIFY_DISPOSABLE_STORE !== true
            || !\defined('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY') || DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY !== true
            || \wp_get_environment_type() !== 'local' || !Runtime::permitsWorkspaceAccount($shop, $account)
            || $db->get_var('SELECT DATABASE()') !== 'printify_fixture'
            || strtoupper((string)$db->get_var("SELECT ENGINE FROM information_schema.TABLES
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='df_printify_fixture_workspace'")) !== 'INNODB') {
            throw new \InvalidArgumentException('Exact local disposable fixture required');
        }
        $key = $db->get_results('SHOW INDEX FROM '.self::TABLE." WHERE Key_name='PRIMARY'", ARRAY_A);
        if (!is_array($key) || count($key) !== 1 || ($key[0]['Column_name'] ?? null) !== 'scope_key'
            || !array_key_exists('Sub_part', $key[0]) || $key[0]['Sub_part'] !== null) {
            throw new \InvalidArgumentException('Fixture identity constraint unavailable');
        }
        $ddl = $db->get_row('SHOW CREATE TABLE '.self::TABLE, ARRAY_N);
        if (!is_array($ddl) || !str_starts_with((string)($ddl[1] ?? ''), 'CREATE TABLE ')
            || !$this->idle()) {
            throw new \InvalidArgumentException('Fixture transaction or storage unavailable');
        }
    }

    public function inspect(): array
    {
        $row = $this->load();
        return ['revision'=>$row['revision'], 'audit'=>array_values($row['audit']), 'events'=>array_values($row['events'])];
    }

    public function execute(int $expected, string $operationId, string $operation, array $input): array
    {
        $actor = \get_current_user_id();
        $flags = ['provider_execution_authorized'=>false,'provider_outcome_confirmed'=>false,'retry_permitted'=>false];
        if ($actor < 1 || !preg_match('/^[a-f0-9]{64}$/D', $operationId)
            || !in_array($operation, [...SyntheticPrintifyClient::operations(), 'FIXTURE_PRODUCTION_CONFIRM',
                'FIXTURE_FULFILL', 'FIXTURE_WEBHOOK_EVENT'], true)) {
            return ['state'=>'DENIED','reason'=>'INVALID_OPERATION']+$flags;
        }
        try {
            $row = $this->load();
            if (isset($row['audit'][$operationId])) {
                return ['state'=>'DENIED','reason'=>'OPERATION_ALREADY_RECORDED']+$flags;
            }
            if ($row['revision'] !== $expected || $expected >= self::LIMIT) {
                return ['state'=>'DENIED','reason'=>'STALE_REVISION_OR_WORKSPACE_LIMIT']+$flags;
            }
            $transport = new SyntheticPrintifyTransport($this->shop);
            $transport->restoreFixtureState($row['transport']);
            $client = new SyntheticPrintifyClient($this->shop, $transport);
            $result = $this->simulate($transport, $client, $operation, $input, $row['events']);
            $row['transport'] = $transport->exportFixtureState();
            $row['audit'][$operationId] = [
                'operation'=>$operation,'state'=>$result['state'],'reason'=>$result['reason'] ?? 'NONE',
                'status'=>$result['status'],'operation_id'=>$operationId,
                'actor_id'=>$actor,'occurred_at'=>gmdate('c'),
            ];
            $bytes = json_encode([$row['transport'],$row['events'],$row['audit']], JSON_THROW_ON_ERROR, 24);
            if (strlen($bytes) > 1048576 || \get_current_user_id() !== $actor
                || !Runtime::permitsWorkspaceAccount($this->shop, $this->account) || !$this->idle()) {
                return ['state'=>'DENIED','reason'=>'WORKSPACE_LIMIT_OR_SCOPE_CHANGED']+$flags;
            }
            $next = $expected + 1;
            $key = $this->key();
            $seal = $this->seal($next, $bytes);
            if ($expected === 0) {
                $sql = $this->db->prepare('INSERT INTO '.self::TABLE.' (scope_key,revision,state_json,seal)
                    VALUES (%s,%d,%s,%s) ON DUPLICATE KEY UPDATE scope_key=scope_key', $key, $next, $bytes, $seal);
            } else {
                $sql = $this->db->prepare('UPDATE '.self::TABLE.' SET revision=%d,state_json=%s,seal=%s
                    WHERE scope_key=%s AND revision=%d AND seal=%s', $next, $bytes, $seal, $key, $expected, $row['seal']);
            }
            $changed = $this->db->query($sql);
            if ($changed !== 1) {
                return ['state'=>$changed === 0 ? 'DENIED' : 'UNKNOWN',
                    'reason'=>$changed === 0 ? 'WORKSPACE_CHANGED' : 'COMMIT_UNCONFIRMED']+$flags;
            }
            $confirmed = $this->load();
            if ($confirmed['revision'] !== $next || $confirmed['seal'] !== $seal) {
                return ['state'=>'UNKNOWN','reason'=>'COMMIT_UNCONFIRMED']+$flags;
            }
            return $result + $flags;
        } catch (\Throwable) {
            return ['state'=>'UNKNOWN','reason'=>'WORKSPACE_UNAVAILABLE']+$flags;
        }
    }

    private function simulate(SyntheticPrintifyTransport $transport, SyntheticPrintifyClient $client, string $op, array $input, array &$events): array
    {
        if ($op === 'FIXTURE_WEBHOOK_EVENT') {
            return $this->acceptEvent($transport, $client, $input, $events);
        }
        if ($op === 'FIXTURE_PRODUCTION_CONFIRM' || $op === 'FIXTURE_FULFILL') {
            $id = $input['order_id'] ?? null;
            $ok = count($input) === 1 && is_string($id) && preg_match('/^order-[1-9][0-9]{0,2}$/D',$id)
                && ($op === 'FIXTURE_FULFILL' ? $transport->fulfill($id)
                    : $transport->confirmProductionSimulation($id));
            if ($ok && $op === 'FIXTURE_PRODUCTION_CONFIRM') {
                // Confirmation is request-local and consumed by the synthetic transport.
                return $client->run('ORDER_SEND_TO_PRODUCTION', ['order_id'=>$id]);
            }
            return self::fixtureResult($ok ? 200 : 409, $ok ? 'NONE' : 'ORDER_STATE_OR_ID');
        }
        // The general UI must use the explicit synthetic production control.
        if ($op === 'ORDER_SEND_TO_PRODUCTION') {
            return self::fixtureResult(409, 'EXPLICIT_SYNTHETIC_CONFIRMATION_REQUIRED');
        }
        if (in_array($op,['PRODUCT_UPDATE','PRODUCT_DELETE','PRODUCT_PUBLISH','PRODUCT_PUBLISH_SUCCEEDED',
            'PRODUCT_PUBLISH_FAILED','PRODUCT_UNPUBLISH_NOTIFY'],true)
            && !is_int($input['expected_version'] ?? null)) {
            return self::fixtureResult(422, 'EXPECTED_PRODUCT_VERSION_REQUIRED');
        }
        return $client->run($op, $input);
    }

    private function acceptEvent(SyntheticPrintifyTransport $transport,SyntheticPrintifyClient $client,array $event,array &$events):array
    {
        $seen=[];
        foreach ($events as $id=>$entry) {
            if (isset($entry['event_digest'])) $seen[$id]=$entry['event_digest'];
        }
        try {
            $received=SyntheticWebhookReceiver::receive($this->shop,SyntheticWebhookReceiver::delivery($event),$seen);
        } catch (\InvalidArgumentException|\JsonException) {
            return self::fixtureResult(422,'EVENT_CONTRACT_OR_SCOPE');
        }
        if ($received['state']!=='SYNTHETIC_OBSERVED') {
            return $received;
        }
        $id=$received['event_id'];
        if (isset($events[$id])) {
            // Older workspaces consumed the event ID without recording an envelope digest.
            return self::fixtureResult(409,'DUPLICATE_EVENT');
        }
        $resource=$received['resource_id'];
        $product=str_starts_with($received['type'],'product:');
        $selection=$product?$transport->fixtureProductSelectors($resource):null;
        $observation=$product
            ? ($selection===null?[]:$client->run('PRODUCT_READ',$selection))
            : $client->run('ORDER_READ',['order_id'=>$resource]);
        if (($observation['state']??null)!=='SYNTHETIC_OBSERVED') {
            return self::fixtureResult(422,'EVENT_RESOURCE_UNAVAILABLE');
        }
        if (isset($received['resource_status']) && $observation['data']['status']!==$received['resource_status']) {
            return self::fixtureResult(409,'EVENT_STATUS_CONFLICT');
        }
        if ($received['type']==='product:publish:started'
            && ($observation['data']['publication_pending']??null)!==true) {
            return self::fixtureResult(409,'EVENT_PUBLICATION_CONFLICT');
        }
        if ($received['type']==='order:sent-to-production'
            && !is_string($observation['data']['sent_to_production_at']??null)) {
            return self::fixtureResult(409,'EVENT_PRODUCTION_CONFLICT');
        }
        if (isset($received['line_item_ids'])) {
            $lines=array_filter($observation['data']['line_items']??[],static fn(array $line):bool=>
                ($line['status']??null)!=='canceled');
            if (array_diff($received['line_item_ids'],array_column($lines,'id'))!==[]) {
                return self::fixtureResult(409,'EVENT_SHIPMENT_LINE_CONFLICT');
            }
        }
        if (isset($received['tracking_sha256'])) {
            $shipments=$observation['data']['shipments']??[];
            $matches=array_filter($shipments,static fn(array $shipment):bool=>
                ($shipment['tracking_hash']??null)===$received['tracking_sha256']);
            if (count($matches)!==1) {
                return self::fixtureResult(409,'EVENT_SHIPMENT_CONFLICT');
            }
            if ($received['type']==='order:shipment:delivered'
                && !$transport->recordFixtureDelivery($resource,$received['tracking_sha256'],$received['shipment_at'])) {
                return self::fixtureResult(409,'EVENT_DELIVERY_CONFLICT');
            }
        }
        $events[$id]=[
            'type'=>$received['type'],'resource_id'=>$resource,'created_at'=>$received['created_at'],
            'event_digest'=>$received['event_digest'],
        ]+array_intersect_key($received,array_flip(['tracking_sha256','shipment_at','publish_action']));
        return self::fixtureResult(200,'NONE');
    }

    private static function fixtureResult(int $status,string $reason):array
    {
        return ['state'=>$status===200?'SYNTHETIC_OBSERVED':'DENIED','status'=>$status,'reason'=>$reason];
    }

    private function load():array
    {
        if (!Runtime::permitsWorkspaceAccount($this->shop,$this->account) || !$this->idle()) {
            throw new \RuntimeException('Workspace scope or connection changed');
        }
        $row=$this->db->get_row($this->db->prepare('SELECT revision,state_json,seal FROM '.self::TABLE.' WHERE scope_key=%s',$this->key()), ARRAY_A);
        if ($this->db->last_error!=='') throw new \RuntimeException('Fixture read failed');
        if ($row===null) return ['revision'=>0,'transport'=>[[],[],[],[],[],1],'events'=>[],'audit'=>[],'seal'=>''];
        $revision=(int)$row['revision'];
        $bytes=$row['state_json'];
        if ($revision<1 || $revision>self::LIMIT || !is_string($bytes) || strlen($bytes)>1048576
            || !hash_equals($this->seal($revision,$bytes),$row['seal'])) {
            throw new \RuntimeException('Fixture state integrity failed');
        }
        $decoded=json_decode($bytes,true,24,JSON_THROW_ON_ERROR);
        if (!is_array($decoded)||!array_is_list($decoded)||count($decoded)!==3
            || !is_array($decoded[0])||!is_array($decoded[1])||!is_array($decoded[2])||count($decoded[2])!==$revision) {
            throw new \RuntimeException('Fixture state invalid');
        }
        return ['revision'=>$revision,'transport'=>$decoded[0],'events'=>$decoded[1],'audit'=>$decoded[2],'seal'=>$row['seal']];
    }

    private function idle():bool
    {
        $state=$this->db->get_row('SELECT @@session.autocommit AS active_autocommit,@@session.in_transaction AS active_transaction,@@innodb_flush_log_at_trx_commit AS durable',ARRAY_A);
        return $this->db->last_error==='' && is_array($state) && (string)$state['active_autocommit']==='1'
            && (string)$state['active_transaction']==='0' && (string)$state['durable']==='1';
    }

    private function key():string { return hash('sha256',$this->shop."\n".$this->account); }
    private function seal(int $revision,string $bytes):string
    {
        return hash_hmac('sha256',"DF_SYNTHETIC_WORKSPACE_1\n".$this->key()."\n".$revision."\n".$bytes,\wp_salt('auth'));
    }
}
