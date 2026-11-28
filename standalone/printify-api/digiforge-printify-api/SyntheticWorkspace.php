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
        $row = $this->load(validateTransport: true);
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
        'ORDER_CREATE','ORDER_SHIPPING_QUOTE','ORDER_CANCEL','LINE_ITEM_CANCEL','FIXTURE_PRODUCTION_CONFIRM','MOCKUP_SELECTION_UPDATE',
    ];

    public const APPROVED_TRANSITIONS = [
        'PRODUCT_PUBLISH','PRODUCT_UNPUBLISH_NOTIFY','ORDER_CANCEL','LINE_ITEM_CANCEL','FIXTURE_PRODUCTION_CONFIRM','MOCKUP_SELECTION_UPDATE',
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

    private static function quoteIntent(SyntheticPrintifyTransport $transport,array $order,string $profileRevision): ?string
    {
        if (!is_array($order['line_items']??null) || !is_array($order['address_to']??null)
            || !in_array($order['shipping_method']??null,[1,2,3,4],true)) return null;
        $items=[];
        foreach ($order['line_items'] as $line) {
            $id=$line['product_id']??null;
            $version=is_string($id)?$transport->fixtureProductRevision($id):null;
            if ($version===null) return null;
            $items[]=['line'=>$line,'product_revision'=>$version];
        }
        $address=$order['address_to'];
        ksort($address);
        $intent=['items'=>$items,'address_sha256'=>hash_hmac('sha256',json_encode($address,JSON_THROW_ON_ERROR),\wp_salt('auth')),
            'shipping_method'=>$order['shipping_method'],'express'=>$order['is_printify_express']??false,
            'economy'=>$order['is_economy_shipping']??false,'profile_revision'=>$profileRevision];
        return hash_hmac('sha256',json_encode($intent,JSON_THROW_ON_ERROR),\wp_salt('auth'));
    }

    private static function quoteBinding(string $id,string $intent,array $quote,int $method,int $now): ?array
    {
        $label = [1=>'standard',2=>'priority',3=>'printify_express',4=>'economy'][$method] ?? null;
        $amount = $label !== null ? ($quote['rates_minor'][$label] ?? null) : null;
        if (!is_int($amount) || $amount < 0) return null;
        $binding = ['quote_id'=>$id,'intent_sha256'=>$intent,'quoted_at'=>$now,'method'=>$label,
            'amount_minor'=>$amount,'projection_sha256'=>ProtectedGet::digest($quote)];
        return $binding + ['binding_mac'=>self::quoteMac($binding)];
    }

    private static function quoteMac(array $binding): string
    {
        return hash_hmac('sha256', ProtectedGet::digest($binding), \wp_salt('auth'));
    }

    private static function quoteMatches(mixed $quote,?string $intent,int $now): bool
    {
        return is_array($quote) && $intent!==null && ($quote['intent_sha256']??null)===$intent
            && is_int($quote['quoted_at']??null) && $quote['quoted_at']<=$now && $now-$quote['quoted_at']<300
            && is_string($quote['binding_mac']??null)
            && hash_equals(self::quoteMac(array_diff_key($quote,['binding_mac'=>true])), $quote['binding_mac']);
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
            'reconcile' => $current['state'] === 'APPROVED_HELD' && $current['operation']!=='MOCKUP_SELECTION_UPDATE' ? 'RECONCILED_FIXTURE_OBSERVATION' : null,
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

    private static function canEditProposal(array $proposal, bool $correlatedCreate): bool
    {
        if ($correlatedCreate) {
            return $proposal['state'] === 'APPROVED_HELD';
        }
        return in_array($proposal['state'], ['PENDING_APPROVAL','BLOCKED'], true)
            || ($proposal['state'] === 'APPROVED_HELD'
                && in_array($proposal['operation'], ['ORDER_CREATE','PRODUCT_UPDATE',...self::APPROVED_TRANSITIONS], true));
    }

    private static function transitionObservation(
        SyntheticPrintifyTransport $transport, SyntheticPrintifyClient $client, string $operation, string $resource
    ): ?array {
        if ($operation==='MOCKUP_SELECTION_UPDATE') {
            $read=$client->run('MOCKUP_SELECTION_READ',['product_id'=>$resource]);
            if (($read['state']??null)!=='SYNTHETIC_OBSERVED') return null;
            return $read['data']+['product_revision'=>$transport->fixtureProductRevision($resource)];
        }
        if (in_array($operation, ['PRODUCT_PUBLISH','PRODUCT_UNPUBLISH_NOTIFY'], true)) {
            $selectors = $transport->fixtureProductSelectors($resource);
            $read = $selectors === null ? null : $client->run('PRODUCT_READ', $selectors);
        } else {
            $read = $client->run('ORDER_READ', ['order_id'=>$resource]);
        }
        return ($read['state'] ?? null) === 'SYNTHETIC_OBSERVED' ? $read['data'] : null;
    }

    private function applyApprovedTransition(
        array $current, SyntheticPrintifyTransport $transport, SyntheticPrintifyClient $client,
        array &$events, int $revision, string $correlation, ?string $fault
    ): array {
        $operation = $current['operation'];
        $configuration = $current['configuration'];
        $resource = $current['resource_id'];
        $before = self::transitionObservation($transport, $client, $operation, $resource);
        if ($before === null || !hash_equals($current['baseline_sha256'], hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)))) {
            return self::fixtureResult(409, 'FIXTURE_TRANSITION_BASELINE_CHANGED');
        }
        if ($fault !== null) {
            $transport->faultNext($fault);
        }
        $attempt = $this->simulate($transport, $client, $operation, $configuration, $events, $revision);
        if ($attempt['state'] !== 'SYNTHETIC_OBSERVED' && $attempt['state'] !== 'UNKNOWN') {
            return $attempt;
        }
        $after = $attempt['state'] === 'SYNTHETIC_OBSERVED'
            ? self::transitionObservation($transport, $client, $operation, $resource) : null;
        $recorded = $after !== null && match ($operation) {
            'MOCKUP_SELECTION_UPDATE' => hash_equals($current['mockup_expected_sha256']??'',ProtectedGet::digest($after)),
            'PRODUCT_PUBLISH' => $after['publication_state'] === 'PENDING',
            'PRODUCT_UNPUBLISH_NOTIFY' => $after['publication_state'] === 'UNPUBLISHED',
            'FIXTURE_PRODUCTION_CONFIRM' => $after['status'] === 'in-production',
            'ORDER_CANCEL' => $after['status'] === 'canceled',
            'LINE_ITEM_CANCEL' => (array_column($after['line_items'], 'status', 'id')[$configuration['line_item_id']] ?? null) === 'canceled',
        };
        $current['state'] = $recorded ? 'RECONCILED_FIXTURE_TRANSITION' : 'UNKNOWN';
        if ($recorded) {
            $current['receipt_id'] = $correlation;
            $current['receipt_sha256'] = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR));
            $current['transition_receipt'] = [
                'scope'=>$current['scope'],'resource_id'=>$resource,'approved_revision'=>$current['revision'],
                'intent_sha256'=>$current['intent_sha256'],'before_sha256'=>$current['baseline_sha256'],
                'after_sha256'=>$current['receipt_sha256'],'workspace_revision'=>$revision+1,'source'=>'SYNTHETIC_PRINTIFY',
            ];
        }
        ++$current['revision'];
        $current['workspace_revision'] = $revision+1;
        return ['state'=>$current['state'],'reason'=>$recorded ? 'EXECUTION_HELD' : 'FIXTURE_TRANSITION_NOT_VERIFIED',
            'status'=>$attempt['status'],'proposal'=>$current,'fixture_effect_committed'=>$recorded,
            'mockup_fixture_attempt'=>$operation==='MOCKUP_SELECTION_UPDATE'];
    }

    private static function reconcileMockup(array $audit,array $input,?array $scope,int $revision,
        SyntheticPrintifyTransport $transport,SyntheticPrintifyClient $client): array
    {
        $id=$input['proposal_id']??null;
        $proposal=is_string($id)?(self::proposals($audit,$scope)[$id]??null):null;
        if ($proposal===null || count($input)!==3 || $proposal['operation']!=='MOCKUP_SELECTION_UPDATE'
            || $proposal['state']!=='UNKNOWN' || ($input['proposal_revision']??null)!==$proposal['revision']
            || ($proposal['mockup_profile_revision']??null)!==AccountProfile::revisionToken()
            || !is_string($proposal['resource_id']??null)) return self::fixtureResult(409,'FIXTURE_MOCKUP_RECONCILIATION_DENIED');
        $after=self::transitionObservation($transport,$client,$proposal['operation'],$proposal['resource_id']);
        if ($after===null || !hash_equals($proposal['mockup_expected_sha256']??'',ProtectedGet::digest($after))) {
            return self::fixtureResult(409,'FIXTURE_MOCKUP_READBACK_MISMATCH');
        }
        $proposal['state']='RECONCILED_FIXTURE_TRANSITION';
        $proposal['receipt_sha256']=ProtectedGet::digest($after);
        $proposal['revision']++;
        $proposal['workspace_revision']=$revision+1;
        return ['state'=>$proposal['state'],'reason'=>'EXACT_FIXTURE_READBACK','status'=>200,'proposal'=>$proposal];
    }

    public function execute(int $expected, string $operationId, string $operation, array $input, bool $preview = false, ?string $readFault = null, array $edit = [], bool $correlatedCreate = false, ?\Closure $custodyExchange = null): array
    {
        $actor = \get_current_user_id();
        $transition = $operation === 'FIXTURE_PROPOSAL_REVIEW' && ($input['decision'] ?? null) === 'simulate_held';
        $flags = ['provider_execution_authorized'=>false,'provider_outcome_confirmed'=>false,'retry_permitted'=>false];
        if (($operation==='MOCKUP_SELECTION_UPDATE' && !$preview) || $actor < 1 || !preg_match('/^[a-f0-9]{64}$/D', $operationId)
            || (($operation === 'FIXTURE_CUSTODY_READ') !== ($custodyExchange !== null))
            || ($edit !== [] && !$preview && !$correlatedCreate)
            || ($correlatedCreate && ($preview || $edit === [] || !in_array($operation, self::CORRELATED_CREATE_OPERATIONS, true)))
            || ($preview && !in_array($operation, self::PREVIEW_OPERATIONS, true))
            || ($readFault !== null && !($operation === 'FIXTURE_READ_SYNC' && in_array($readFault, ['timeout','rate_limit'], true))
                && !(($preview || $correlatedCreate || $transition) && $readFault === 'lost_ack'))
            || !in_array($operation, [...SyntheticPrintifyClient::operations(), 'FIXTURE_READ_SYNC', 'FIXTURE_PROPOSAL_REVIEW', 'FIXTURE_CUSTODY_READ', 'FIXTURE_PRODUCTION_CONFIRM',
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
            $custody = $operation === 'FIXTURE_CUSTODY_READ';
            $review = $operation === 'FIXTURE_PROPOSAL_REVIEW';
            $profileSensitive = $sync || $custody || $preview || $review || $correlatedCreate
                || in_array($operation, ['FIXTURE_WEBHOOK_EVENT','FIXTURE_WEBHOOK_HTTP','WEBHOOK_SIMULATE'], true);
            $profileRevision = $profileSensitive ? AccountProfile::revisionToken() : null;
            $profile = ($sync || $custody) ? AccountProfile::current() : null;
            $proposalScope = ($preview || $review || $correlatedCreate || $custody) ? $this->proposalScope() : null;
            $edited = null;
            if ($edit !== []) {
                $editId = $edit['id'] ?? null;
                $edited = is_string($editId) ? (self::proposals($row['audit'], $proposalScope)[$editId] ?? null) : null;
                if ($edited === null || ($edit['revision'] ?? null) !== $edited['revision']
                    || $edited['operation'] !== $operation
                    || !self::canEditProposal($edited, $correlatedCreate)) {
                    return ['state'=>'DENIED','reason'=>'PROPOSAL_SCOPE_OR_STALE_REVISION']+$flags;
                }
            }
            if ($correlatedCreate && !hash_equals($edited['intent_sha256'],
                hash_hmac('sha256', json_encode($input, JSON_THROW_ON_ERROR), \wp_salt('auth')))) {
                return ['state'=>'DENIED','reason'=>'FIXTURE_CREATE_INTENT_MISMATCH']+$flags;
            }
            if ($correlatedCreate && $operation==='ORDER_CREATE'
                && !self::quoteMatches($edited['quote_binding']??null,
                    self::quoteIntent($transport,$input,(string)$profileRevision),time())) {
                return ['state'=>'DENIED','reason'=>'FIXTURE_QUOTE_MISSING_STALE_OR_CHANGED']+$flags;
            }
            $baseline = null;
            if ($preview && in_array($operation, self::APPROVED_TRANSITIONS, true)) {
                $resource = $input['product_id'] ?? $input['order_id'] ?? null;
                if (is_string($resource)) {
                    $baseline = self::transitionObservation($transport, $client, $operation, $resource);
                }
            }
            if ($transition) {
                $id = $input['proposal_id'] ?? null;
                $approved = is_string($id) ? (self::proposals($row['audit'], $proposalScope)[$id] ?? null) : null;
                if ($approved === null || count($input) !== 3 || ($input['proposal_revision'] ?? null) !== $approved['revision']
                    || (($approved['operation']??null)==='MOCKUP_SELECTION_UPDATE'
                        && ($approved['mockup_profile_revision']??null)!==$profileRevision)
                    || $approved['state'] !== 'APPROVED_HELD' || !in_array($approved['operation'], self::APPROVED_TRANSITIONS, true)
                    || !is_string($approved['baseline_sha256'] ?? null) || !is_string($approved['resource_id'])
                    || !hash_equals($approved['intent_sha256'], hash_hmac('sha256', json_encode($approved['configuration'],
                        JSON_THROW_ON_ERROR), \wp_salt('auth')))) {
                    return ['state'=>'DENIED','reason'=>'FIXTURE_TRANSITION_APPROVAL_MISMATCH']+$flags;
                }
            }
            if ($readFault !== null && !$transition) {
                $transport->faultNext($readFault);
            }
            $result = match (true) {
                $sync => $this->synchronization($transport, $expected, $input),
                $transition => $this->applyApprovedTransition($approved, $transport, $client, $events, $expected, $operationId, $readFault),
                $review && ($input['decision']??null)==='reconcile_mockup' =>
                    self::reconcileMockup($row['audit'],$input,$proposalScope,$expected,$transport,$client),
                $review => self::reviewProposal($row['audit'], $input, $proposalScope, $expected),
                $custody => $this->custodyObservation($proposalScope, $input, $custodyExchange),
                default => $this->simulate($transport, $client, $operation, $input, $events, $expected),
            };
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
                    if ($operation==='ORDER_CREATE') {
                        $quote = $edited['quote_binding'];
                        $edited['creation_receipt'] += [
                            'quote_id'=>$quote['quote_id'], 'quote_method'=>$quote['method'],
                            'quote_amount_minor'=>$quote['amount_minor'],
                            'quote_projection_sha256'=>$quote['projection_sha256'],
                        ];
                    }
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
            if ((!$preview && !$sync && !$review && !$custody && (!$correlatedCreate || $result['fixture_effect_committed']))
                || ($transition && (($result['fixture_effect_committed'] ?? false) || ($result['mockup_fixture_attempt']??false)))) {
                $row['transport'] = $transport->exportFixtureState();
                $row['events'] = $events;
            }
            if ($preview && $edited !== null && in_array($operation, ['ORDER_CREATE','PRODUCT_UPDATE',...self::APPROVED_TRANSITIONS], true)
                && $result['state'] !== 'SYNTHETIC_OBSERVED' && $result['state'] !== 'UNKNOWN') {
                return $result + $flags;
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
                        'product_id','order_id','line_items','line_item_id','shipping_method','publish_fields','title','description','file_name',
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
                    if ($ready && $baseline !== null) {
                        $result['proposal']['baseline_sha256'] = hash('sha256', json_encode($baseline, JSON_THROW_ON_ERROR));
                        if ($operation==='MOCKUP_SELECTION_UPDATE') {
                            $after=self::transitionObservation($transport,$client,$operation,$resource);
                            if ($after===null) return self::fixtureResult(409,'FIXTURE_MOCKUP_READBACK_MISMATCH')+$flags;
                            $result['proposal']['configuration']=$input;
                            $result['proposal']['mockup_profile_revision']=$profileRevision;
                            $result['proposal']['mockup_expected_sha256']=ProtectedGet::digest($after);
                        }
                    }
                    if ($ready && $operation === 'ORDER_SHIPPING_QUOTE') {
                        $result['proposal']['quote_projection'] = $result['data'];
                        $intent=self::quoteIntent($transport,$input['order'],(string)$profileRevision);
                        $binding = $intent === null ? null : self::quoteBinding(
                            $operationId,$intent,$result['data'],$input['order']['shipping_method'],time());
                        if ($binding !== null) $result['proposal']['quote_binding']=$binding;
                    }
                    if ($ready && $operation==='ORDER_CREATE') {
                        $intent=self::quoteIntent($transport,$input,(string)$profileRevision);
                        foreach (array_reverse(self::proposals($row['audit'],$proposalScope)) as $previous) {
                            if ($previous['operation']==='ORDER_SHIPPING_QUOTE' && $previous['state']==='PENDING_APPROVAL'
                                && self::quoteMatches($previous['quote_binding']??null,$intent,time())) {
                                $result['proposal']['quote_binding']=$previous['quote_binding'];
                                break;
                            }
                        }
                    }
                }
            }
            $row['audit'][$operationId] = [
                'operation'=>$operation,'state'=>$result['state'],'reason'=>$result['reason'] ?? 'NONE',
                'status'=>$result['status'],'operation_id'=>$operationId,
                'actor_id'=>$actor,'occurred_at'=>gmdate('c'),
            ] + array_intersect_key($result, ['sync_receipt'=>true, 'preview_only'=>true, 'retry_after'=>true, 'proposal'=>true, 'transport_receipt'=>true, 'custody_receipt'=>true, 'shipment_receipt'=>true]);
            $bytes = json_encode([$row['transport'],$row['events'],$row['audit']], JSON_THROW_ON_ERROR, 24);
            if (strlen($bytes) > 1048576 || \get_current_user_id() !== $actor
                || !Runtime::permitsWorkspaceAccount($this->shop, $this->account)
                || (($sync || $custody) && (AccountProfile::current() !== $profile || AccountProfile::revisionToken() !== $profileRevision))
                || (($preview || $review || $correlatedCreate) && $this->proposalScope() !== $proposalScope)
                || (isset($result['shipment_receipt']) && $result['shipment_receipt']['scope'] !==
                    ($this->proposalScope() ?? ['shop'=>$this->shop,'account'=>$this->account,'integration'=>null]))
                || !$this->idle()) {
                return ['state'=>'DENIED','reason'=>'WORKSPACE_LIMIT_OR_SCOPE_CHANGED']+$flags;
            }
            $profileBound = $profile !== null || $proposalScope !== null
                || isset($result['shipment_receipt']['scope']['integration']);
            $guard = '';
            if ($profileBound) {
                $connection = (int)$this->db->get_var('SELECT CONNECTION_ID()');
                if ($profileRevision === null || $connection < 1) {
                    return ['state'=>'DENIED','reason'=>'PROFILE_COMMIT_UNAVAILABLE']+$flags;
                }
                // A wpdb reconnect must never execute a write after losing the profile lock.
                $guard = $this->db->prepare(' AND CONNECTION_ID() = %d AND @@session.in_transaction = 1', $connection);
            }

            $next = $expected + 1;
            $key = $this->key();
            $seal = $this->seal($next, $bytes);
            if ($expected === 0) {
                $insert = $guard === '' ? 'VALUES (%s,%d,%s,%s)' : 'SELECT %s,%d,%s,%s WHERE 1=1'.$guard;
                $sql = $this->db->prepare('INSERT INTO '.self::TABLE.' (scope_key,revision,state_json,seal) '
                    .$insert.' ON DUPLICATE KEY UPDATE scope_key=scope_key', $key, $next, $bytes, $seal);
            } else {
                $sql = $this->db->prepare('UPDATE '.self::TABLE.' SET revision=%d,state_json=%s,seal=%s
                    WHERE scope_key=%s AND revision=%d AND seal=%s'.$guard, $next, $bytes, $seal, $key, $expected, $row['seal']);
            }
            $reason = $this->commitWorkspace($sql, $profileBound ? $profileRevision : null);
            if ($reason !== null) {
                return ['state'=>$reason === 'COMMIT_UNCONFIRMED' ? 'UNKNOWN' : 'DENIED','reason'=>$reason]+$flags;
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

    private function commitWorkspace(string $sql, ?string $profileRevision): ?string
    {
        if ($profileRevision === null) {
            return match ($this->db->query($sql)) {
                1 => null, 0 => 'WORKSPACE_CHANGED', default => 'COMMIT_UNCONFIRMED',
            };
        }
        $engine = $this->db->get_var($this->db->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $this->db->options
        ));
        if (strtoupper((string)$engine) !== 'INNODB' || !$this->idle()
            || $this->db->query('START TRANSACTION') === false) {
            return 'PROFILE_COMMIT_UNAVAILABLE';
        }
        $committed = false;
        try {
            if (!AccountProfile::lockRevision($this->db, $profileRevision)) {
                return 'PROFILE_STALE';
            }
            $changed = $this->db->query($sql);
            if ($changed !== 1) {
                return $changed === 0 ? 'WORKSPACE_CHANGED' : 'COMMIT_UNCONFIRMED';
            }
            $committed = $this->db->query('COMMIT') !== false;
            return $committed ? null : 'COMMIT_UNCONFIRMED';
        } finally {
            if (!$committed) {
                $this->db->query('ROLLBACK');
            }
        }
    }

    private function custodyObservation(?array $scope, array $input, \Closure $exchange): array
    {
        $wireScope = $input['scope'] ?? null;
        $operation = $input['operation'] ?? null;
        $selection = $input['selection'] ?? null;
        if ($scope === null || count($input) !== 4 || ($input['integration'] ?? null) !== $scope['integration']
            || !is_array($wireScope) || ($wireScope['shop'] ?? null) !== $scope['shop']
            || ($wireScope['account_reference'] ?? null) !== $scope['account']
            || !is_string($operation) || !is_array($selection)) {
            return self::fixtureResult(403, 'CUSTODY_AUDIT_SCOPE');
        }
        $read = Runtime::readFromConfiguredCustodyFixture($scope['integration'], $wireScope, $operation, $selection, $exchange);
        $status = match ($read['state']) {
            'AUTHENTICATED_CUSTODY_FIXTURE'=>200, 'RATE_LIMITED'=>429, default=>0,
        };
        $projection = $read['observation'] ?? null;
        $details = $projection === null ? [] : [
            'projection_sha256'=>hash('sha256',json_encode($projection,JSON_THROW_ON_ERROR)),
            'fixture_tracking'=>array_values(array_filter(array_column($projection['shipments'] ?? [],'tracking_reference'),'is_string')),
        ];
        return $read + ['status'=>$status,'custody_receipt'=>['scope'=>$scope,'status'=>$status] + $details
            + array_intersect_key($read, array_fill_keys(['state','reason','operation_id','request_sha256',
                'response_sha256','receipt_sha256','observed_at','expires_at','retry_after'], true))];
    }

    /** A field allowlist keeps request payloads, profile references and credentials out of the export. */
    public function redactedAuditExport(): ?array
    {
        $scope = $this->proposalScope();
        if ($scope === null) {
            return null;
        }
        try {
            $row = $this->load(validateTransport: true);
        } catch (\Throwable) {
            return null;
        }
        $entries = [];
        foreach ($row['audit'] as $entry) {
            $receipt = $entry['custody_receipt'] ?? $entry['shipment_receipt'] ?? $entry['sync_receipt'] ?? $entry['transport_receipt'] ?? $entry['proposal'] ?? [];
            if (($receipt['scope'] ?? null) !== $scope) {
                continue;
            }
            $safe = array_intersect_key($entry, array_fill_keys(['operation','state','reason','status','operation_id','occurred_at'], true));
            $safe += array_intersect_key($receipt, array_fill_keys(['projection_sha256','request_sha256','response_sha256',
                'receipt_sha256','event_digest','tracking_sha256','shipment_at','outcome','workspace_revision','freshness','provider_execution_authorized'], true));
            if (isset($entry['proposal'])) {
                $safe['proposal'] = array_intersect_key($entry['proposal'], array_fill_keys(
                    ['id','revision','state','receipt_sha256','referral'], true));
            }
            $entries[] = $safe;
        }
        return ['schema'=>'DF_PRINTIFY_REDACTED_AUDIT_1','evidence'=>'FIXTURE_ONLY',
            'revision'=>$row['revision'],'entries'=>$entries,'profile'=>AccountProfile::diagnostics()];
    }

    private function simulate(SyntheticPrintifyTransport $transport, SyntheticPrintifyClient $client, string $op, array $input, array &$events, int $revision): array
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
                ? $this->acceptEvent($transport,$client,$wire,$events,$revision)
                : self::fixtureResult(422,'EVENT_HTTP_FRAMING');
        }
        if ($op === 'FIXTURE_WEBHOOK_EVENT') {
            return $this->acceptEvent($transport, $client, $input, $events, $revision);
        }
        if ($op === 'FIXTURE_PRODUCTION_CONFIRM' || $op === 'FIXTURE_FULFILL') {
            $id = $input['order_id'] ?? null;
            $line = $input['line_item_id'] ?? null;
            $exactInput = count($input) === 1 || ($op === 'FIXTURE_FULFILL' && count($input) === 2
                && is_string($line) && preg_match('/^line-[1-9][0-9]{0,2}$/D', $line));
            $ok = $exactInput && is_string($id) && preg_match('/^order-[1-9][0-9]{0,2}$/D',$id)
                && ($op === 'FIXTURE_FULFILL' ? $transport->fulfill($id, $line)
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
            $delivery=$this->acceptEvent($transport,$client,$event,$events,$revision);
            if ($result['state']==='SYNTHETIC_OBSERVED') {
                return $delivery['state']!=='SYNTHETIC_OBSERVED' ? $delivery : $result + array_intersect_key($delivery, ['shipment_receipt'=>true]);
            }
        }
        return $result;
    }

    private function acceptEvent(SyntheticPrintifyTransport $transport,SyntheticPrintifyClient $client,array|string $event,array &$events,int $revision):array
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
        $receipt = [];
        if (isset($received['tracking_sha256'])) {
            $hash = $received['tracking_sha256'];
            $tracked = array_filter($observation['data']['shipments'] ?? [], static fn(array $shipment): bool =>
                $shipment['tracking_hash'] === $hash);
            $lines = $transport->fixtureShipmentLines($resource, $hash);
            if (count($tracked) !== 1 || $lines === null) {
                return self::fixtureResult(409, 'EVENT_SHIPMENT_CONFLICT');
            }
            $reported = $received['line_item_ids'] ?? $lines;
            if (count($reported) !== count($lines) || array_diff($lines, $reported) !== []) {
                return self::fixtureResult(409, 'EVENT_SHIPMENT_LINE_CONFLICT');
            }
            $delivered = $topic === 'order:shipment:delivered';
            foreach ($events as $prior) {
                if (($prior['resource_id'] ?? null) !== $resource || ($prior['tracking_sha256'] ?? null) !== $hash) {
                    continue;
                }
                $previousTime = $prior['shipment_at'];
                $currentTime = $received['shipment_at'];
                if ($prior['type'] === $topic) {
                    return self::fixtureResult(409, $previousTime === $currentTime
                        ? 'DUPLICATE_SHIPMENT_OBSERVATION' : ($delivered ? 'EVENT_DELIVERY_CONFLICT' : 'EVENT_SHIPMENT_CONFLICT'));
                }
                if ($delivered ? $currentTime < $previousTime : $currentTime > $previousTime) {
                    return self::fixtureResult(409, 'EVENT_SHIPMENT_TIME_CONFLICT');
                }
            }
            if ($delivered && !$transport->recordFixtureDelivery($resource, $hash, $received['shipment_at'])) {
                return self::fixtureResult(409, 'EVENT_DELIVERY_CONFLICT');
            }
            $receipt = ['shipment_receipt'=>[
                'scope'=>$this->proposalScope() ?? ['shop'=>$this->shop,'account'=>$this->account,'integration'=>null],
                'workspace_revision'=>$revision+1, 'order_id'=>$resource, 'event_id'=>$id, 'event_digest'=>$received['event_digest'],
                'tracking_sha256'=>$hash, 'line_item_ids'=>$lines, 'order_status'=>$observation['data']['status'],
                'shipment_at'=>$received['shipment_at'], 'outcome'=>$delivered?'DELIVERY_OBSERVED':'SHIPMENT_OBSERVED',
                'source'=>'SYNTHETIC_PRINTIFY', 'provider_execution_authorized'=>false,
            ]];
        }
        $events[$id]=[
            'type'=>$received['type'],'resource_id'=>$resource,'created_at'=>$received['created_at'],
            'event_digest'=>$received['event_digest'],
        ]+array_intersect_key($received,array_flip(['tracking_sha256','shipment_at','publish_action','canceled_line_id','preview_status','support_status']))+$receipt;
        return self::fixtureResult(200,'NONE')+$receipt;
    }

    private static function fixtureResult(int $status,string $reason):array
    {
        return ['state'=>$status===200?'SYNTHETIC_OBSERVED':'DENIED','status'=>$status,'reason'=>$reason];
    }

    private function load(bool $validateTransport = false):array
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
        if ($validateTransport) {
            (new SyntheticPrintifyTransport($this->shop))->restoreFixtureState($decoded[0]);
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
