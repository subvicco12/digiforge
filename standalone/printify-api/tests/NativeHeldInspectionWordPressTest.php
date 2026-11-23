<?php
declare(strict_types=1);

use DigiForge\Connector\Printify\{
    CredentialReference, NativeAuthorityAdapter, NativeBridge, NativeBuildIdentity, NativeHeldJournal, NativeHeldJournalSchema,
    NativePanelAccessAdapter, ShopBinding
};
use DigiForgePrintifyApi\Runtime;

final class NativeHeldInspectionWordPressTest extends WP_UnitTestCase
{
    public function testExactHeldReadbackPreservesUnknownAndRequiresCurrentScope(): void
    {
        $root = dirname((new ReflectionClass(NativeBuildIdentity::class))->getFileName(), 4);
        require_once $root . '/tests/wordpress/fixtures/PrintifyApiApprovalFixture.php';
        require_once $root . '/tests/support/NativePrintifyContractFixture.php';
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        $fixture = new PrintifyApiApprovalFixture();
        $fixture->boot();
        global $wpdb;
        $replay = 'df_checkpoint_'.bin2hex(random_bytes(5));
        try {
            $fixture->setting('stop_all', true);
            $fixture->setting('automation_armed', false);
            $fixture->setting('activation_authorized', false);
            wp_get_current_user()->add_cap('digiforge_printify_review_shop_12');
            $v = PrintifyApiApprovalFixture::checked((new DigiForge\POD\ScopedProviderConnection())
                ->verified('personalized_pod', $fixture->integration, 'printify.connection.read'));
            $scope = new ShopBinding(2, 'personalized_pod', $fixture->integration, '12', $v['environment'],
                $v['review_revision'], new CredentialReference($fixture->integration), $v['binding_hash'], $v['expires_at']);
            $nativeScope = PrintifyApiApprovalFixture::checked(NativeAuthorityAdapter::scope($scope, 1));
            $account = $nativeScope['scope']['external_account_reference'];
            define('DIGIFORGE_PRINTIFY_OFFLINE_ACCOUNT', $account);
            $session = PrintifyApiApprovalFixture::checked(NativeAuthorityAdapter::session(1));
            $context = ['version'=>'1.0.0', 'view'=>'unknown', 'session_fingerprint'=>$session['fingerprint'],
                'csrf_token'=>wp_create_nonce(NativePanelAccessAdapter::nonceAction($scope, 'unknown'))];
            $binding = array_replace(NativePrintifyContractFixture::binding(), [
                'operation_id'=>hash('sha256', wp_generate_uuid4()), 'scope_fingerprint'=>$scope->fingerprint(),
                'integration_id'=>$scope->integrationId, 'credential_reference'=>$scope->integrationId,
                'provider_shop_id'=>'12', 'environment'=>'test', 'authorization_revision'=>$scope->revision,
            ]);
            foreach (NativeHeldJournalSchema::statements() as $sql) {
                self::assertNotFalse($wpdb->query(preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql)));
            }
            $journal = new NativeHeldJournal();
            $key = hash('sha256', 'fixture:'.$binding['operation_id']);
            $record = PrintifyApiApprovalFixture::checked($journal->register($binding, $key));
            self::assertSame($record, $journal->register($binding, $key));
            $read = static fn(array $b): array => Runtime::inspectNativeHeldOperation('12', $account, $scope, $b, $context);
            $staleScope = new ShopBinding($scope->shopId, $scope->shopKey, $scope->integrationId,
                $scope->providerShopId, $scope->environment, $scope->revision + 1, $scope->credential,
                $scope->bindingHash, $scope->expiresAt);
            $staleBinding = array_replace($binding, [
                'scope_fingerprint'=>$staleScope->fingerprint(), 'authorization_revision'=>$staleScope->revision,
            ]);
            $staleContext = array_replace($context, [
                'csrf_token'=>wp_create_nonce(NativePanelAccessAdapter::nonceAction($staleScope, 'unknown')),
            ]);
            self::assertSame('DENIED', Runtime::inspectNativeHeldOperation(
                '12', $account, $staleScope, $staleBinding, $staleContext
            )['state']);

            $held = $read($binding);
            [$heads, $events] = NativeHeldJournalSchema::tables();
            $journalKey = ['operation_id'=>$binding['operation_id'], 'shop_key'=>$binding['shop_key']];
            $where = $wpdb->prepare('operation_id=%s AND shop_key=%s', ...array_values($journalKey));
            $heldRow = $wpdb->get_row('SELECT * FROM '.$heads.' WHERE '.$where, ARRAY_A);
            self::assertSame('LOCAL_HELD_OBSERVED', $held['state'], json_encode($held));
            foreach (['payload_fingerprint'=>str_repeat('0',64), 'expected_product_version'=>2,
                'approval_reference'=>str_repeat('1',64)] as $field=>$value) {
                self::assertSame('BLOCKED', $read(array_replace($binding, [$field=>$value]))['state']);
            }
            foreach (['shop_id'=>3, 'shop_key'=>'standard_pod', 'provider_shop_id'=>'13',
                'integration_id'=>$scope->integrationId + 1, 'credential_reference'=>$scope->integrationId + 1,
                'environment'=>'sandbox', 'authorization_revision'=>$scope->revision + 1] as $field=>$value) {
                self::assertSame('DENIED', $read(array_replace($binding, [$field=>$value]))['state'], $field);
            }
            PrintifyApiApprovalFixture::checked($journal->transition($binding['operation_id'], 'personalized_pod', 1, 1, 'FENCE'));
            PrintifyApiApprovalFixture::checked($journal->transition($binding['operation_id'], 'personalized_pod', 1, 2, 'UNKNOWN'));
            $unknown = $read($binding);
            self::assertSame('UNKNOWN', $unknown['state']);
            self::assertFalse($unknown['retry_permitted']);
            self::assertFalse($unknown['provider_outcome_confirmed']);
            self::assertSame($unknown, $read($binding));
            $core = NativeBridge::connect('1.0.0');
            self::assertNotWPError($core);
            $request = NativePrintifyContractFixture::mutation('RECEIPT_READ');
            $request['binding'] = $binding;
            $request['context'] = array_replace($request['context'], array_intersect_key(
                $binding, array_flip(['actor_id', 'authorization_revision', 'approval_reference',
                    'product_version', 'expected_product_version'])
            ));
            $request['duplicate_key'] = $key;
            $request['data'] = ['receipt_reference'=>$unknown['event_hash']];
            $response = $core->exchange($request);
            self::assertWPError($response);
            self::assertSame('printify_native_held_outcome_unknown', $response->get_error_code());
            self::assertFalse($response->get_error_data()['retry_permitted']);
            self::assertFalse($response->get_error_data()['provider_receipt_verified']);
            foreach (['REGISTER', 'CLAIM'] as $action) {
                $attempt = array_replace($request, ['action'=>$action, 'data'=>[]]);
                $denial = $core->exchange($attempt);
                self::assertWPError($denial);
                self::assertSame('printify_native_journal_and_independent_checkpoint_required', $denial->get_error_code());
                self::assertFalse($denial->get_error_data()['network_request_attempted']);
            }
            $authorize = array_replace($request, ['action'=>'AUTHORIZE', 'data'=>[]]);
            $prepare = static fn(array $r): array => Runtime::prepareNativeMutation('12', $account, $r);
            $prepared = $prepare($authorize);
            self::assertSame('BLOCKED', $prepared['state'], json_encode($prepared));
            self::assertSame('CURRENT_NATIVE_OPERATIONAL_AUTHORITY_UNAVAILABLE', $prepared['reason']);
            self::assertFalse($prepared['provider_execution_authorized']);
            self::assertFalse($prepared['retry_permitted']);
            self::assertSame($prepared, $prepare($authorize));
            foreach (['actor_id'=>2, 'authorization_revision'=>$scope->revision + 1,
                'approval_reference'=>str_repeat('1', 64), 'expected_product_version'=>2] as $field=>$value) {
                $wrongContext = array_replace($authorize['context'], [$field=>$value]);
                self::assertSame('DENIED', $prepare(array_replace($authorize, ['context'=>$wrongContext]))['state'], $field);
            }
            self::assertSame('DENIED', Runtime::prepareNativeMutation('12', 'wrong-account', $authorize)['state']);
            self::assertSame('DENIED', Runtime::prepareNativeMutation('13', $account, $authorize)['state']);
            $wrongOperation = array_replace($binding, ['operation_id'=>str_repeat('0',64)]);
            self::assertSame('DENIED', $read($wrongOperation)['state']);
            $malformed = array_replace($authorize, ['evidence_references'=>['unreviewed']]);
            self::assertSame('DENIED', $prepare($malformed)['state']);

            [, $events] = NativeHeldJournalSchema::tables();
            $eventKey = ['operation_id'=>$binding['operation_id'], 'shop_key'=>$binding['shop_key'], 'revision'=>1];
            $originalPayload = $wpdb->get_var($wpdb->prepare(
                'SELECT payload FROM '.$events.' WHERE operation_id=%s AND shop_key=%s AND revision=%d',
                $eventKey['operation_id'], $eventKey['shop_key'], $eventKey['revision']
            ));
            self::assertIsString($originalPayload);
            self::assertSame(1, $wpdb->update($events, ['payload'=>'{}'], $eventKey));
            try {
                $corrupt = $read($binding);
                self::assertSame('DENIED', $corrupt['state']);
                self::assertFalse($corrupt['retry_permitted']);
                self::assertFalse($corrupt['provider_execution_authorized']);
            } finally {
                self::assertSame(1, $wpdb->update($events, ['payload'=>$originalPayload], $eventKey));
            }
            self::assertSame($unknown, $read($binding));

            $restoreProbe = static function () use ($wpdb, $heads, $events, $journalKey, $where, $heldRow, $read, $binding): array {
                $current = $wpdb->get_row('SELECT * FROM '.$heads.' WHERE '.$where, ARRAY_A);
                $suffix = $wpdb->get_results('SELECT * FROM '.$events.' WHERE '.$where.' AND revision>1', ARRAY_A);
                self::assertCount(2, $suffix);
                try {
                    self::assertSame(2, $wpdb->query('DELETE FROM '.$events.' WHERE '.$where.' AND revision>1'));
                    self::assertSame(1, $wpdb->update($heads, $heldRow, $journalKey));
                    return $read($binding);
                } finally {
                    foreach ($suffix as $event) {
                        self::assertSame(1, $wpdb->insert($events, $event));
                    }
                    self::assertSame(1, $wpdb->update($heads, $current, $journalKey));
                }
            };
            $verifyCheckpoint = $this->exerciseSyntheticCheckpoint($binding, $account, $context, $held, $unknown, $restoreProbe, $replay);
            self::assertSame($unknown, $read($binding));
            $token = wp_get_session_token();
            WP_Session_Tokens::get_instance(1)->destroy($token);
            self::assertSame(1, get_current_user_id());
            self::assertSame('DENIED', $prepare($authorize)['state']);
            self::assertSame('DENIED', $read($binding)['state']);
            self::assertSame('DENIED', $verifyCheckpoint($read($binding))['state']);
            wp_set_current_user(0);
            self::assertSame('DENIED', $read($binding)['state']);
        } finally {
            $wpdb->query('DROP TABLE IF EXISTS '.$replay);
            $fixture->restore();
        }
    }

    private function exerciseSyntheticCheckpoint(
        array $binding, string $account, array $context, array $held, array $unknown, Closure $restoreProbe, string $replay
    ): Closure {
        require_once __DIR__ . '/SyntheticCheckpointFixture.php';
        $identity = $binding + [
            'account_reference'=>$account,
            'session_fingerprint'=>$context['session_fingerprint'],
            'correlation_id'=>hash('sha256', 'synthetic-checkpoint:' . $binding['operation_id']),
        ];
        $bound = [
            'identity_digest'=>DigiForge\Connector\Printify\NativePortContract::hash($identity),
            'journal_head'=>$unknown['event_hash'],
            'journal_revision'=>$unknown['journal_revision'],
        ];
        $witness = ['fixture'=>'witness-v1', 'witness_revision'=>1] + $bound;
        $anchor = ['fixture'=>'rollback-anchor-v1'] + $bound;
        $witnessFile = tmpfile();
        $anchorFile = tmpfile();
        self::assertIsResource($witnessFile);
        self::assertIsResource($anchorFile);
        $write = static function ($file, ?array $record): void {
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($record, JSON_THROW_ON_ERROR));
            fflush($file);
        };
        $read = static function ($file): ?array {
            rewind($file);
            return json_decode(stream_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        };
        $write($anchorFile, $anchor);
        global $wpdb;
        self::assertNotFalse($wpdb->query("CREATE TABLE $replay (
            operation_id CHAR(64) PRIMARY KEY, identity_digest CHAR(64) NOT NULL,
            owner VARCHAR(16) NOT NULL, expires_at INT NOT NULL, outcome VARCHAR(16) NOT NULL DEFAULT 'RESERVED'
        ) ENGINE=InnoDB"));
        [$journal] = NativeHeldJournalSchema::tables();
        $query = [
            'socket'=>substr(DB_HOST, strpos(DB_HOST, ':') + 1), 'database'=>DB_NAME,
            'user'=>DB_USER, 'password'=>DB_PASSWORD, 'replay'=>$replay, 'journal'=>$journal,
            'shop'=>$binding['shop_key'], 'operation'=>$binding['operation_id'],
            'revision'=>$bound['journal_revision'], 'head'=>$bound['journal_head'], 'digest'=>$bound['identity_digest'],
        ];
        $worker = static fn(string $command, string $owner, int $tick): array =>
            self::startCheckpointWorker($query + compact('command', 'owner', 'tick'));
        $owner = null;
        $attempted = false;
        $reserve = static function (array $id, array $current) use ($worker, $query, &$owner, &$attempted): bool {
            if ($id['operation_id'] !== $query['operation'] || $current['identity_digest'] !== $query['digest']
                || $current['journal_head'] !== $query['head'] || $current['journal_revision'] !== $query['revision']) {
                return false;
            }
            if ($attempted) {
                return self::readCheckpointWorker($worker('reserve', 'restarted', 30)) === 'RESERVED';
            }
            $attempted = true;
            $a = $worker('park', 'worker-a', 10);
            $b = $worker('park', 'worker-b', 10);
            $first = self::readCheckpointWorker($a);
            $second = self::readCheckpointWorker($b);
            self::assertSame(['DENIED', 'RESERVED'], array_values(array_unique(
                array_intersect(['DENIED', 'RESERVED'], [$first, $second])
            )));
            $owner = $first === 'RESERVED' ? 'worker-a' : 'worker-b';
            return true;
        };
        $fixture = new SyntheticCheckpointFixture(
            static fn(): ?array => $read($witnessFile),
            static fn(): ?array => $read($anchorFile),
            $reserve
        );
        $inspect = static fn(array $observation): array => $fixture->inspect($identity, $observation);
        foreach ([null, array_replace($witness, ['witness_revision'=>0]),
            array_replace($witness, ['identity_digest'=>str_repeat('0', 64)])] as $badWitness) {
            $write($witnessFile, $badWitness);
            self::assertSame('DENIED', $inspect($unknown)['state']);
        }
        $write($witnessFile, $witness);
        foreach ([null, array_replace($anchor, ['fixture'=>'wrong-anchor']),
            array_replace($anchor, ['journal_revision'=>$held['journal_revision']])] as $badAnchor) {
            $write($anchorFile, $badAnchor);
            self::assertSame('DENIED', $inspect($unknown)['state']);
        }
        $write($anchorFile, $anchor);
        foreach (['provider_shop_id'=>'13', 'account_reference'=>'wrong-account', 'operation_id'=>str_repeat('0',64),
            'credential_reference'=>$binding['credential_reference']+1, 'payload_fingerprint'=>str_repeat('0',64),
            'expected_product_version'=>$binding['expected_product_version']+1,
            'authorization_revision'=>$binding['authorization_revision']+1] as $field=>$value) {
            self::assertSame('DENIED', $fixture->inspect(array_replace($identity, [$field=>$value]), $unknown)['state'], $field);
        }
        $stale = array_replace($unknown, [
            'event_hash'=>$held['event_hash'], 'journal_revision'=>$held['journal_revision'],
        ]);
        self::assertSame('DENIED', $inspect($stale)['state']);
        self::assertSame('DENIED', $inspect($restoreProbe())['state']);
        $staleWorker = self::startCheckpointWorker(array_replace($query, [
            'head'=>$held['event_hash'], 'revision'=>$held['journal_revision'],
            'command'=>'reserve', 'owner'=>'stale', 'tick'=>9,
        ]));
        self::assertSame('DENIED', self::readCheckpointWorker($staleWorker));
        $matched = $inspect($unknown);
        self::assertSame('SYNTHETIC_CHECKPOINT_MATCHED', $matched['state']);
        self::assertSame('UNKNOWN', $matched['outcome']);
        self::assertFalse($matched['provider_execution_authorized']);
        self::assertFalse($matched['retry_permitted']);
        self::assertNotNull($owner);
        self::assertSame('DENIED', self::readCheckpointWorker($worker('finalize', 'foreign', 11)));
        self::assertNotFalse($wpdb->query("ALTER TABLE $replay ADD COLUMN schema_revision INT NOT NULL DEFAULT 2"));
        self::assertSame('UNKNOWN', self::readCheckpointWorker($worker('finalize', $owner, 12)));
        self::assertSame('DENIED', self::readCheckpointWorker($worker('finalize', $owner, 13)));
        self::assertSame('DENIED', self::readCheckpointWorker($worker('finalize', $owner, 20)));
        self::assertSame('UNKNOWN', $wpdb->get_var("SELECT outcome FROM $replay"));
        self::assertNotFalse($wpdb->query("ALTER TABLE $replay DROP COLUMN schema_revision"));
        self::assertSame('DENIED', $inspect($unknown)['state']);
        self::assertSame('UNKNOWN', $wpdb->get_var("SELECT outcome FROM $replay"));
        return $inspect;
    }

    private static function startCheckpointWorker(array $query): array
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/durable-checkpoint-worker.php'],
            [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($query, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 8);
        return [$process, $pipes[1], $pipes[2], $query['command']];
    }

    private static function readCheckpointWorker(array $worker): string
    {
        [$process, $stdout, $stderr, $command] = $worker;
        try {
            $result = trim((string)fgets($stdout));
            self::assertContains($result, ['DENIED', 'RESERVED', 'UNKNOWN']);
            if ($command === 'park' && $result === 'RESERVED') {
                self::assertTrue(proc_terminate($process, 9));
            }
            return $result;
        } finally {
            fclose($stdout);
            fclose($stderr);
            proc_close($process);
        }
    }
}
