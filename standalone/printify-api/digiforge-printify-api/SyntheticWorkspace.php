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
        return ['revision'=>$row['revision'], 'audit'=>array_values($row['audit']), 'events'=>array_values($row['events']),
            'proposals'=>array_values(self::proposals($row['audit'], $this->proposalScope())),
            'detail_receipts'=>self::detailReceipts($row['audit'], $this->proposalScope())];
    }

    /** Read the sealed fixture snapshot without writing workspace state or audit events. */
    public function readRecords(string $kind, ?string $id, int $page, int $limit): array
    {
        try {
            $snapshot = $this->load();
            $transport = new SyntheticPrintifyTransport($this->shop);
            $transport->restoreFixtureState($snapshot['transport']);
            return $this->projectRecords($transport, $snapshot['revision'], $kind, $id, $page, $limit);
        } catch (\Throwable) {
            return ['state'=>'FAILED_READ', 'reason'=>'WORKSPACE_UNAVAILABLE'];
        }
    }

    private function projectRecords(SyntheticPrintifyTransport $transport, int $revision, string $kind, ?string $id, int $page, int $limit): array
    {
        if (!in_array($kind, ['products', 'orders'], true) || $page < 1 || $page > 100
            || $limit < 1 || $limit > 10 || ($id !== null && ($page !== 1
                || preg_match($kind === 'products' ? '/^product-[1-9][0-9]{0,2}$/D' : '/^order-[1-9][0-9]{0,2}$/D', $id) !== 1))) {
            return ['state'=>'DENIED', 'reason'=>'INVALID_SELECTION'];
        }
        $operation = $kind === 'products' ? 'PRODUCTS_READ' : 'ORDERS_READ';
        $input = ['page'=>$page, 'limit'=>$limit];
        if ($id !== null && $kind === 'products') {
            $operation = 'PRODUCT_READ';
            $input = $transport->fixtureProductSelectors($id);
            if ($input === null) {
                return ['state'=>'FAILED_READ', 'reason'=>'RECORD_NOT_FOUND'];
            }
        } elseif ($id !== null) {
            $operation = 'ORDER_READ';
            $input = ['order_id'=>$id];
        }
        $result = (new SyntheticPrintifyClient($this->shop, $transport))->run($operation, $input);
        if ($result['state'] !== 'SYNTHETIC_OBSERVED') {
            return ['state'=>'FAILED_READ', 'reason'=>$result['reason'], 'status'=>$result['status'],
                'retry_after'=>$result['bounded_retry_after'] ?? null];
        }
        $data = $result['data'];
        if ($operation === 'ORDER_READ') {
            foreach ($data['shipments'] as &$shipment) {
                $shipment['tracking_reference'] = $transport->fixtureTrackingReference($id, $shipment['tracking_hash']);
            }
            unset($shipment);
        }
        return ['state'=>'OBSERVED', 'reason'=>null, 'data'=>$data, 'workspace_revision'=>$revision];
    }

    public const PREVIEW_OPERATIONS = [
        'IMAGE_UPLOAD','PRODUCT_CREATE','PRODUCT_UPDATE','PRODUCT_PUBLISH','PRODUCT_UNPUBLISH_NOTIFY',
        'ORDER_CREATE','ORDER_SHIPPING_QUOTE','ORDER_CANCEL','LINE_ITEM_CANCEL','FIXTURE_PRODUCTION_CONFIRM',
    ];

    private function synchronization(SyntheticPrintifyTransport $transport, int $revision, array $input): array
    {
        $profile = AccountProfile::current();
        $kind = $input['kind'] ?? null;
        $selection = $input['selection'] ?? null;
        if ($profile === null || $profile['shop'] !== $this->shop || $profile['account'] !== $this->account
            || count($input) !== 3 || ($input['integration'] ?? null) !== $profile['integration']
            || !is_string($kind) || !is_array($selection)
            || AccountProfile::plan($this->shop, $this->account, $profile['integration'], $kind)['state'] !== 'PREPARED_OFFLINE') {
            return ['state'=>'BLOCKED','reason'=>'SYNC_SCOPE_OR_TRANSPORT','status'=>0];
        }
        if ($kind === 'catalog') {
            require_once __DIR__.'/CatalogBrowser.php';
            $read = CatalogBrowser::read(1, $this->shop, $this->account, $selection, $transport);
            $reason = $read['error'];
        } else {
            if (array_diff_key($selection, ['id'=>true,'page'=>true,'limit'=>true]) !== []
                || (isset($selection['id']) && !is_string($selection['id']))
                || !is_int($selection['page'] ?? 1) || !is_int($selection['limit'] ?? 10)) {
                return ['state'=>'DENIED','reason'=>'INVALID_SELECTION','status'=>0];
            }
            $read = $this->projectRecords($transport, $revision, $kind, $selection['id'] ?? null,
                $selection['page'] ?? 1, $selection['limit'] ?? 10);
            $reason = $read['reason'];
        }
        $status = $read['status'] ?? ($reason === 'RATE_LIMITED' ? 429 : 0);
        $state = match (true) {
            $read['state'] === 'OBSERVED' => 'OBSERVED',
            $status === 429 => 'RATE_LIMITED',
            $reason === 'SCOPE_OR_TRANSPORT_UNAVAILABLE' => 'BLOCKED',
            in_array($reason, ['INVALID_SELECTION','UNSUPPORTED_SELECTION'], true) => 'DENIED',
            default => 'UNAVAILABLE',
        };
        $timestamp = time();
        $receipt = ['source'=>'SYNTHETIC_PRINTIFY','freshness'=>$state === 'OBSERVED' ? 'FIXTURE_ONLY' : 'UNAVAILABLE',
            'attempted_at'=>$timestamp,'observed_at'=>$state === 'OBSERVED' ? $timestamp : null,
            'provider_observed_at'=>null,'provider_fact_verified'=>false,'workspace_revision'=>$revision,
            'kind'=>$kind,'scope'=>['shop'=>$this->shop,'account'=>$this->account,'integration'=>$profile['integration']],
            'selection_sha256'=>hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR))];
        if ($state === 'OBSERVED') {
            if ($kind !== 'catalog' && isset($selection['id'])) {
                $receipt['resource_id'] = $read['data']['id'];
                $fields = $kind === 'orders'
                    ? ['status','sent_to_production_at','cost','shipping_cost']
                    : ['blueprint_id','print_provider_id','synthetic_version','publication_state','publication_pending'];
                $receipt['fields'] = array_intersect_key($read['data'], array_fill_keys($fields, true));
                foreach ($kind === 'orders' ? ['line_items','shipments'] : ['variants','print_areas','images'] as $field) {
                    $items = $read['data'][$field] ?? [];
                    $receipt['fields'][$field.'_count'] = count($items);
                    $receipt['fields'][$field.'_sha256'] = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
                }
            }
            $receipt['projection_sha256'] = hash('sha256', json_encode($read['data'], JSON_THROW_ON_ERROR));
        }
        return ['state'=>$state,'reason'=>$reason ?? 'NONE','status'=>$state === 'OBSERVED' ? 200 : $status,
            'retry_after'=>$read['retry_after'] ?? null,'data'=>$read['data'] ?? null,'sync_receipt'=>$receipt];
    }

    private function proposalScope(): ?array
    {
        $profile = AccountProfile::current();
        return ($profile['shop'] ?? null) === $this->shop && $profile['account'] === $this->account
            ? ['shop'=>$this->shop,'account'=>$this->account,'integration'=>$profile['integration']] : null;
    }

    private static function proposals(array $audit, ?array $scope): array
    {
        $latest = [];
        if ($scope === null) {
            return $latest;
        }
        foreach ($audit as $entry) {
            $proposal = $entry['proposal'] ?? null;
            if (($proposal['scope'] ?? null) === $scope) {
                $latest[$proposal['id']] = $proposal;
            }
        }
        return $latest;
    }

    private static function detailReceipts(array $audit, ?array $scope): array
    {
        $receipts = [];
        if ($scope === null) {
            return $receipts;
        }
        foreach ($audit as $id=>$entry) {
            $receipt = $entry['sync_receipt'] ?? null;
            if (($receipt['scope'] ?? null) === $scope && ($receipt['source'] ?? null) === 'SYNTHETIC_PRINTIFY'
                && isset($receipt['resource_id'], $receipt['projection_sha256'])) {
                $receipts[$id] = $receipt;
            }
        }
        return $receipts;
    }

    private static function difference(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (array_key_exists($key, $before) !== array_key_exists($key, $after)
                || ($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[$key] = ['before'=>$before[$key] ?? null,'after'=>$after[$key] ?? null];
            }
        }
        return $changes;
    }

    public const CORRELATED_CREATE_OPERATIONS = ['PRODUCT_CREATE','ORDER_CREATE'];

    /** Verify the one resource added by this local attempt using the existing typed detail projection. */
    private function creationEvidence(
        SyntheticPrintifyTransport $transport, array $before, array $result, string $operation, int $revision
    ): ?array {
        $index = $operation === 'PRODUCT_CREATE' ? 1 : 2;
        $id = $result['data']['id'] ?? null;
        $after = $transport->exportFixtureState();
        $created = array_diff_key($after[$index], $before[$index]);
        if ($result['state'] !== 'SYNTHETIC_OBSERVED' || !is_string($id) || array_keys($created) !== [$id]) {
            return null;
        }
        $read = $this->projectRecords($transport, $revision, $index === 1 ? 'products' : 'orders', $id, 1, 10);
        if ($read['state'] !== 'OBSERVED' || ($read['data']['id'] ?? null) !== $id) {
            return null;
        }
        return ['resource_id'=>$id,'projection_sha256'=>hash('sha256', json_encode($read['data'], JSON_THROW_ON_ERROR))];
    }

    private static function reviewProposal(array $audit, array $input, ?array $scope, int $revision): array
    {
        $id = $input['proposal_id'] ?? null;
        $decision = $input['decision'] ?? null;
        $current = is_string($id) ? (self::proposals($audit, $scope)[$id] ?? null) : null;
        if ($current === null || ($input['proposal_revision'] ?? null) !== $current['revision']
            || array_diff_key($input, ['proposal_id'=>true,'proposal_revision'=>true,'decision'=>true,'receipt_id'=>true]) !== []) {
            return self::fixtureResult(409, 'PROPOSAL_SCOPE_OR_STALE_REVISION');
        }
        $state = match ($decision) {
            'approve' => $current['state'] === 'PENDING_APPROVAL' ? 'APPROVED_HELD' : null,
            'reject' => in_array($current['state'], ['PENDING_APPROVAL','BLOCKED'], true) ? 'REJECTED' : null,
            'cancel' => in_array($current['state'], ['PENDING_APPROVAL','APPROVED_HELD','BLOCKED'], true) ? 'CANCELLED' : null,
            'refer_unknown' => $current['state'] === 'UNKNOWN' && !isset($current['referral']) ? 'UNKNOWN' : null,
            'reconcile_create' => $current['state'] === 'FIXTURE_CREATE_RECORDED' ? 'RECONCILED_FIXTURE_CREATION' : null,
            'reconcile' => $current['state'] === 'APPROVED_HELD' ? 'RECONCILED_FIXTURE_OBSERVATION' : null,
            default => null,
        };
        if ($state === null || ($decision !== 'reconcile' && isset($input['receipt_id']))) {
            return self::fixtureResult(409, 'PROPOSAL_TRANSITION_DENIED');
        }
        if ($decision === 'refer_unknown') {
            $current['referral'] = 'MANUAL_RECONCILIATION_REQUIRED';
        }
        if ($decision === 'reconcile_create') {
            $creation = $current['creation_receipt'] ?? null;
            if (!is_array($creation) || ($creation['scope'] ?? null) !== $scope
                || ($creation['proposal_id'] ?? null) !== $id || ($creation['approved_revision'] ?? null) !== $current['revision'] - 1
                || ($creation['resource_id'] ?? null) !== $current['resource_id'] || ($creation['source'] ?? null) !== 'SYNTHETIC_PRINTIFY'
                || ($creation['intent_sha256'] ?? null) !== $current['intent_sha256']
                || !isset($creation['projection_sha256'], $creation['correlation_id'])) {
                return self::fixtureResult(409, 'FIXTURE_CREATE_RECEIPT_MISMATCH');
            }
            $current['receipt_id'] = $creation['correlation_id'];
            $current['receipt_sha256'] = $creation['projection_sha256'];
        }
        if ($decision === 'reconcile') {
            $receiptId = $input['receipt_id'] ?? null;
            $receipts = self::detailReceipts($audit, $scope);
            $receipt = is_string($receiptId) ? ($receipts[$receiptId] ?? []) : [];
            if (($receipt['workspace_revision'] ?? -1) < $current['workspace_revision'] || $current['resource_id'] === null
                || ($receipt['resource_id'] ?? null) !== $current['resource_id']) {
                return self::fixtureResult(409, 'PROPOSAL_RECEIPT_MISMATCH');
            }
            $prior = null;
            foreach ($receipts as $previousId=>$previous) {
                if ($previous['resource_id'] === $current['resource_id']
                    && $previous['workspace_revision'] < $receipt['workspace_revision']
                    && ($prior === null || $previous['workspace_revision'] > $prior['workspace_revision'])) {
                    $prior = ['id'=>$previousId]+$previous;
                }
            }
            $current['receipt_id'] = $receiptId;
            $current['receipt_sha256'] = $receipt['projection_sha256'];
            $current['observation_comparison'] = $prior !== null && isset($prior['fields'], $receipt['fields'])
                ? ['state'=>'COMPARED','before_receipt_id'=>$prior['id'],'changes'=>self::difference($prior['fields'], $receipt['fields'])]
                : ['state'=>'NO_COMPARABLE_PRIOR_OBSERVATION'];
        }
        $current['state'] = $state;
        $current['revision']++;
        $current['workspace_revision'] = $revision + 1;
        return ['state'=>$state,'reason'=>'EXECUTION_HELD','status'=>200,'proposal'=>$current];
    }

    public function execute(int $expected, string $operationId, string $operation, array $input, bool $preview = false, ?string $readFault = null, array $edit = [], bool $correlatedCreate = false): array
    {
        $actor = \get_current_user_id();
        $flags = ['provider_execution_authorized'=>false,'provider_outcome_confirmed'=>false,'retry_permitted'=>false];
        if ($actor < 1 || !preg_match('/^[a-f0-9]{64}$/D', $operationId)
            || ($edit !== [] && !$preview && !$correlatedCreate)
            || ($correlatedCreate && ($preview || $edit === [] || !in_array($operation, self::CORRELATED_CREATE_OPERATIONS, true)))
            || ($preview && !in_array($operation, self::PREVIEW_OPERATIONS, true))
            || ($readFault !== null && !($operation === 'FIXTURE_READ_SYNC' && in_array($readFault, ['timeout','rate_limit'], true))
                && !(($preview || $correlatedCreate) && $readFault === 'lost_ack'))
            || !in_array($operation, [...SyntheticPrintifyClient::operations(), 'FIXTURE_READ_SYNC', 'FIXTURE_PROPOSAL_REVIEW', 'FIXTURE_PRODUCTION_CONFIRM',
                'FIXTURE_FULFILL', 'FIXTURE_WEBHOOK_EVENT','FIXTURE_PREVIEW_COMPLETE','FIXTURE_WEBHOOK_HTTP'], true)) {
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
            $events = $row['events'];
            $sync = $operation === 'FIXTURE_READ_SYNC';
            $profile = $sync ? AccountProfile::current() : null;
            $review = $operation === 'FIXTURE_PROPOSAL_REVIEW';
            $proposalScope = ($preview || $review || $correlatedCreate) ? $this->proposalScope() : null;
            $edited = null;
            if ($edit !== []) {
                $editId = $edit['id'] ?? null;
                $edited = is_string($editId) ? (self::proposals($row['audit'], $proposalScope)[$editId] ?? null) : null;
                if ($edited === null || ($edit['revision'] ?? null) !== $edited['revision']
                    || $edited['operation'] !== $operation
                    || !in_array($edited['state'], $correlatedCreate ? ['APPROVED_HELD'] : ['PENDING_APPROVAL','BLOCKED'], true)) {
                    return ['state'=>'DENIED','reason'=>'PROPOSAL_SCOPE_OR_STALE_REVISION']+$flags;
                }
            }
            if ($correlatedCreate && !hash_equals($edited['intent_sha256'],
                hash_hmac('sha256', json_encode($input, JSON_THROW_ON_ERROR), \wp_salt('auth')))) {
                return ['state'=>'DENIED','reason'=>'FIXTURE_CREATE_INTENT_MISMATCH']+$flags;
            }
            if ($readFault !== null) {
                $transport->faultNext($readFault);
            }
            $result = $sync ? $this->synchronization($transport, $expected, $input)
                : ($review ? self::reviewProposal($row['audit'], $input, $proposalScope, $expected)
                    : $this->simulate($transport, $client, $operation, $input, $events));
            if ($correlatedCreate) {
                $resource = $this->creationEvidence($transport, $row['transport'], $result, $operation, $expected);
                $approvedRevision = $edited['revision'];
                $edited['revision']++;
                $edited['workspace_revision'] = $expected + 1;
                if ($resource !== null) {
                    $edited['state'] = 'FIXTURE_CREATE_RECORDED';
                    $edited['resource_id'] = $resource['resource_id'];
                    $edited['creation_receipt'] = $resource + [
                        'scope'=>$proposalScope,'proposal_id'=>$edited['id'],'approved_revision'=>$approvedRevision,
                        'source'=>'SYNTHETIC_PRINTIFY','intent_sha256'=>$edited['intent_sha256'],
                        'correlation_id'=>$operationId,'outcome'=>'VERIFIED_FIXTURE_CREATION',
                    ];
                } else {
                    $edited['state'] = in_array($result['state'], ['SYNTHETIC_OBSERVED','UNKNOWN'], true) ? 'UNKNOWN' : 'BLOCKED';
                }
                $result = ['state'=>$edited['state'],'reason'=>$resource === null ? 'FIXTURE_CREATE_NOT_VERIFIED' : 'EXECUTION_HELD',
                    'status'=>$result['status'],'proposal'=>$edited,'fixture_effect_committed'=>$resource !== null];
            }
            if ($preview || $sync || $correlatedCreate) {
                $profileForReceipt = ($preview || $correlatedCreate) ? AccountProfile::current() : $profile;
                $result['transport_receipt'] = [
                    'mode'=>'SYNTHETIC_ONLY', 'scope'=>($preview || $correlatedCreate) ? $proposalScope : ($result['sync_receipt']['scope'] ?? null),
                    'requests'=>$transport->admittedRequests(), 'provider_execution_authorized'=>false,
                    'custody'=>($profileForReceipt['custody'] ?? '') === '' ? 'NO_REFERENCE' : 'REFERENCE_UNRESOLVED',
                    'state'=>$result['state'], 'status'=>$result['status'],
                ];
            }
            if (!$preview && !$sync && !$review && (!$correlatedCreate || $result['fixture_effect_committed'])) {
                $row['transport'] = $transport->exportFixtureState();
                $row['events'] = $events;
            }
            if ($preview) {
                $result['preview_only'] = true;
                $result['fixture_effect_committed'] = false;
                if ($proposalScope !== null) {
                    $resource = $input['product_id'] ?? $input['order_id'] ?? null;
                    $resource = is_string($resource) && preg_match('/^(?:product|order)-[1-9][0-9]{0,2}$/D', $resource) === 1 ? $resource : null;
                    $ready = $result['state'] === 'SYNTHETIC_OBSERVED';
                    $configuration = $ready ? array_intersect_key($input, array_fill_keys([
                        'blueprint_id','print_provider_id','variants','print_areas','expected_version',
                        'product_id','order_id','line_items','shipping_method','publish_fields','title','description','file_name',
                    ], true)) : [];
                    if ($ready && $operation === 'ORDER_SHIPPING_QUOTE') {
                        $configuration = array_intersect_key($input['order'], ['line_items'=>true,'shipping_method'=>true]);
                    }
                    $result['proposal'] = [
                        'id'=>$edited['id'] ?? $operationId, 'revision'=>($edited['revision'] ?? 0)+1,
                        'workspace_revision'=>$expected+1, 'scope'=>$proposalScope, 'operation'=>$operation,
                        'state'=>$ready ? 'PENDING_APPROVAL' : ($result['state'] === 'UNKNOWN' ? 'UNKNOWN' : 'BLOCKED'),
                        'intent_sha256'=>hash_hmac('sha256', json_encode($input, JSON_THROW_ON_ERROR), \wp_salt('auth')),
                        'resource_id'=>$resource, 'configuration'=>$configuration,
                    ];
                    if ($ready && $operation === 'ORDER_SHIPPING_QUOTE') {
                        $result['proposal']['quote_projection'] = $result['data'];
                    }
                }
            }
            $row['audit'][$operationId] = [
                'operation'=>$operation,'state'=>$result['state'],'reason'=>$result['reason'] ?? 'NONE',
                'status'=>$result['status'],'operation_id'=>$operationId,
                'actor_id'=>$actor,'occurred_at'=>gmdate('c'),
            ] + array_intersect_key($result, ['sync_receipt'=>true, 'preview_only'=>true, 'retry_after'=>true, 'proposal'=>true, 'transport_receipt'=>true]);
            $bytes = json_encode([$row['transport'],$row['events'],$row['audit']], JSON_THROW_ON_ERROR, 24);
            if (strlen($bytes) > 1048576 || \get_current_user_id() !== $actor
                || !Runtime::permitsWorkspaceAccount($this->shop, $this->account)
                || ($sync && AccountProfile::current() !== $profile)
                || (($preview || $review || $correlatedCreate) && $this->proposalScope() !== $proposalScope) || !$this->idle()) {
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
        if ($op==='FIXTURE_PREVIEW_COMPLETE') {
            $product=$input['product_id']??null;
            $task=$input['task_id']??null;
            $ok=count($input)===2 && is_string($product) && is_string($task)
                && $transport->completeFixturePreview($product,$task);
            return self::fixtureResult($ok?200:409,$ok?'NONE':'PREVIEW_STATE_OR_ID');
        }
        if ($op==='FIXTURE_WEBHOOK_HTTP') {
            $wire=$input['wire']??null;
            return count($input)===1 && is_string($wire)
                ? $this->acceptEvent($transport,$client,$wire,$events)
                : self::fixtureResult(422,'EVENT_HTTP_FRAMING');
        }
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
        $result=$client->run($op,$input);
        if ($op==='WEBHOOK_SIMULATE' && ($event=$transport->takeSimulatedEvent())!==null) {
            $delivery=$this->acceptEvent($transport,$client,$event,$events);
            if ($result['state']==='SYNTHETIC_OBSERVED' && $delivery['state']!=='SYNTHETIC_OBSERVED') {
                return $delivery;
            }
        }
        return $result;
    }

    private function acceptEvent(SyntheticPrintifyTransport $transport,SyntheticPrintifyClient $client,array|string $event,array &$events):array
    {
        $seen=[];
        foreach ($events as $id=>$entry) {
            if (isset($entry['event_digest'])) $seen[$id]=$entry['event_digest'];
        }
        try {
            $wire=is_string($event)?$event:SyntheticWebhookReceiver::wire(SyntheticWebhookReceiver::delivery($event));
            $received=SyntheticWebhookReceiver::receiveWire($this->shop,$wire,$seen);
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
        $topic=$received['type'];
        $observation=match ($topic) {
            'shop:disconnected'=>['state'=>$transport->fixtureDisconnected()?'SYNTHETIC_OBSERVED':'DENIED'],
            'product:deleted'=>['state'=>$transport->fixtureProductDeleted($resource)?'SYNTHETIC_OBSERVED':'DENIED'],
            'personalization-preview-task:processed'=>[
                'state'=>$transport->finishFixturePreview($resource,$received['preview_status'],$received['preview_mockups'])?'SYNTHETIC_OBSERVED':'DENIED'],
            'support-request:updated'=>[
                'state'=>$transport->observeFixtureSupport($received)?'SYNTHETIC_OBSERVED':'DENIED',
            ],
            default=>str_starts_with($topic,'product:')
                ? (($selection=$transport->fixtureProductSelectors($resource))===null?[]:$client->run('PRODUCT_READ',$selection))
                : $client->run('ORDER_READ',['order_id'=>$resource]),
        };
        if (($observation['state']??null)!=='SYNTHETIC_OBSERVED') {
            return self::fixtureResult(422,'EVENT_RESOURCE_UNAVAILABLE');
        }
        if (isset($received['canceled_line_id'])) {
            $lines=array_column($observation['data']['line_items'],null,'id');
            if (($lines[$received['canceled_line_id']]['status']??null)!=='canceled') {
                return self::fixtureResult(409,'EVENT_CANCELLATION_CONFLICT');
            }
        }
        if (isset($received['resource_status']) && $observation['data']['status']!==$received['resource_status']) {
            return self::fixtureResult(409,'EVENT_STATUS_CONFLICT');
        }
        if ($received['type']==='product:publish:started' && $received['publish_action']!=='delete'
            && !$transport->fixturePublicationMatches($resource,$received['publish_details'])) {
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
        ]+array_intersect_key($received,array_flip(['tracking_sha256','shipment_at','publish_action','canceled_line_id','preview_status','support_status']));
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
