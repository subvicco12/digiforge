<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__.'/ProtectedGet.php';
require_once __DIR__.'/ProtectedGetWorkspace.php';
require_once __DIR__.'/DirectGet.php';
require_once __DIR__.'/DirectReadSecret.php';

/** Manual scoped GET preparation and refresh. */
final class ProtectedGetPanel
{
    private const INPUT_FIELDS = ['blueprint_id','provider_id','variant_id','method','page','product_id','variant_ids','sku','image_id','order_id'];
    private const ACTION = 'df_printify_protected_get_v1';
    private const FIELDS = [
        'SHOPS_READ'=>[], 'BLUEPRINTS_READ'=>[], 'BLUEPRINT_READ'=>['blueprint_id'], 'BLUEPRINT_SIZE_GUIDE_READ'=>['blueprint_id'],
        'BLUEPRINT_PROVIDERS_READ'=>['blueprint_id'], 'BLUEPRINT_PROVIDER_READ'=>['blueprint_id','provider_id'], 'PROVIDERS_READ'=>[], 'PROVIDER_READ'=>['provider_id'],
        'VARIANTS_READ'=>['blueprint_id','provider_id'], 'VARIANT_READ'=>['blueprint_id','provider_id','variant_id'],
        'CATALOG_SHIPPING_READ'=>['blueprint_id','provider_id'],
        'CATALOG_SHIPPING_METHOD_READ'=>['blueprint_id','provider_id','method'],
        'PRODUCTS_READ'=>['page'],'PRODUCT_READ'=>['product_id','blueprint_id','provider_id','variant_ids'],
        'PRODUCT_ARTWORK_READ'=>['product_id','blueprint_id','provider_id','variant_ids','image_id'],
        'PRODUCT_SKU_READ'=>['product_id','blueprint_id','provider_id','sku'],
        'ORDERS_READ'=>['page'],'ORDER_READ'=>['order_id'],
    ];

    public static function render(array $post): void
    {
        if (!\current_user_can('manage_options')) {
            return;
        }
        $direct = DirectGet::selected();
        $synthetic = !$direct || DirectGet::synthetic();
        $workspace = ProtectedGetWorkspace::snapshot($direct);
        echo '<section><h2>Protected Printify reads</h2>';
        echo $workspace === null
            ? '<p>Live direct reads are held. You can prepare a scoped request without sending it.</p>'
            : ($direct ? ($synthetic ? '<p>Local direct GET fixture. Live reads are held.</p>'
                : '<p>Manual direct Printify GET. Server-side holds apply.</p>')
                : '<p>Local gateway fixture only. Live provider reads are disabled.</p>');
        $profile = AccountProfile::current();
        $revision = AccountProfile::revisionToken();
        if ($profile === null || $profile['custody'] === '' || $revision === null) {
            echo '<p>An assigned account profile and custody reference are required.</p></section>';
            return;
        }
        echo '<p>Shop '.\esc_html($profile['shop']).' · account '.\esc_html($profile['account']).'</p>';
        if (isset($post[self::ACTION])) {
            $result = self::prepare($post);
            echo '<p role="status">'.\esc_html($result['reason']).'</p>';
            $advice = match ($result['reason']) {
                'OPERATOR_DENIED','SCOPE_OR_REVISION_UNAVAILABLE' => 'Reopen the panel and verify the operator session and assigned shop.',
                'WORKSPACE_UNAVAILABLE_OR_STALE','OBSERVATION_NOT_COMMITTED' => 'Reopen the panel to load the current observation before a new manual read.',
                'PROVIDER_SCOPE_OR_CREDENTIAL_DENIED' => 'Ask the approved custodian to verify the credential and its read scope.',
                'PROVIDER_RATE_LIMITED','LOCAL_BUDGET_OR_STORAGE_UNAVAILABLE' => 'Wait for the indicated provider delay or local quota window before a new manual read.',
                'DIRECT_READ_UNAVAILABLE' => 'Check the selected IDs and connection status. Use Shops Read for a scoped manual diagnostic.',
                'INVALID_SELECTION' => 'Check the fields required by the selected operation.',
                default => null,
            };
            if ($advice !== null) echo '<p>'.\esc_html($advice).'</p>';
            if (isset($result['path'])) {
                echo '<code>GET '.\esc_html($result['path']).'</code>';
            }
        }
        if ($direct) {
            $connection = DirectGet::status($profile);
            $help = match ($connection) {
                'DIRECT_CONFIGURATION_UNAVAILABLE' => 'Ask the custodian to check the credential file, expiry and approved profile binding.',
                'DIRECT_READ_HELD' => 'The configured safety holds currently prevent a read.',
                'SYNTHETIC_DIRECT_READY' => 'Synthetic scope ready.',
                default => 'Server configuration matches the profile. Provider connectivity is unverified.',
            };
            echo '<p>Connection: '.\esc_html($connection.' · '.$help)
                .' Limit: 10 reads per minute per account/shop. Use Shops Read for a manual diagnostic.</p>';
        }
        if ($workspace !== null) {
            $scope = self::scope($profile, $revision, \wp_get_session_token());
            $record = (self::currentSession($scope['actor'], \wp_get_session_token()) && (!$direct || (DirectGet::synthetic() ? DirectGet::gatesOpen()
                : DirectGet::status($scope) === 'DIRECT_PROFILE_CONFIGURED')))
                ? ProtectedGetWorkspace::visible($scope,$direct) : null;
            if ($record !== null) {
                $receipt = $record['receipt'];
                $freshness = (time() - $record['stored_at'] <= 60 ? 'Recent' : 'Stale').($synthetic ? ' fixture' : ' observation');
                echo '<p>'.\esc_html($freshness.' · '.gmdate('c', $record['stored_at'])
                    .' · '.$receipt['operation'].' · '.$receipt['state'].' · '.$receipt['reason']).'</p>';
                if (($receipt['source'] ?? null) === 'SYNTHETIC_PRINTIFY') {
                    echo '<p>'.\esc_html('Synthetic Printify evidence · observed '.gmdate('c', $receipt['observed_at'])).'</p>';
                }
                if (isset($receipt['retry_after_seconds'])) {
                    echo '<p>'.\esc_html('Provider requested a wait of '.$receipt['retry_after_seconds'].' seconds.').'</p>';
                }
                if (($receipt['source'] ?? null) === 'PRINTIFY_HTTPS_GET') {
                    echo '<p>'.\esc_html('Printify HTTPS GET · observed '.gmdate('c',$receipt['observed_at'])).'</p>';
                }
                if (($receipt['source'] ?? null) === 'SYNTHETIC_DIRECT_PRINTIFY') {
                    echo '<p>'.\esc_html('Synthetic direct Printify evidence · observed '.gmdate('c',$receipt['observed_at'])).'</p>';
                }
                $data = $receipt['data'] ?? null;
                $currentData = $receipt['state'] === 'OBSERVED' && $data !== null;
                if ($currentData && ($data['data'] ?? null) === []) {
                    echo '<p>No records were returned for this page.</p>';
                }
                if ($currentData && time()-$record['stored_at'] <= 60) {
                    self::renderContinuation($receipt['operation'],$data,$revision,$record['workspace_revision']);
                    self::renderNavigation($receipt['operation'],$data,$revision,$record['workspace_revision']);
                }
                if ($data === null && isset($record['last_success']['data'])
                    && time()-$record['last_success']['observed_at'] <= 3600) {
                    echo '<p>'.\esc_html(($synthetic ? 'Stale prior synthetic ' : 'Stale prior provider ').$record['last_success']['operation'].' observation · '
                        .gmdate('c',$record['last_success']['observed_at'])).'</p>';
                    $data = $record['last_success']['data'];
                }
                if ($data !== null) {
                    self::renderOrder($receipt['operation'],$data);
                    echo '<pre style="white-space:pre-wrap;overflow-wrap:anywhere">'.\esc_html(json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)).'</pre>';
                }
                if (!empty($record['history'])) {
                    echo '<details><summary>Recent read outcomes</summary><ol>';
                    foreach (array_reverse($record['history']) as $event) {
                        echo '<li>'.\esc_html(gmdate('c',$event['at']).' · '.$event['operation']
                            .' · '.$event['state'].' · '.$event['reason']).'</li>';
                    }
                    echo '</ol></details>';
                    self::renderEvidence($record['history']);
                }
            }
            $workspace = ProtectedGetWorkspace::snapshot($direct);
        }
        echo '<form method="post"><input type="hidden" name="'.self::ACTION.'" value="1">';
        foreach (array_keys(self::FIELDS) as $operation) {
            \wp_nonce_field(self::ACTION.':'.$operation, 'df_printify_get_nonce['.$operation.']', false);
        }
        echo '<input type="hidden" name="df_printify_get_revision" value="'.\esc_attr($revision).'">';
        if ($workspace !== null) {
            echo '<input type="hidden" name="df_printify_get_workspace" value="'.\esc_attr($workspace['revision']).'">';
        }
        echo '<label>Read operation <select style="max-width:100%" name="df_printify_get_operation">';
        foreach (self::FIELDS as $operation => $fields) {
            echo '<option value="'.\esc_attr($operation).'">'.\esc_html($operation).'</option>';
        }
        echo '</select></label><p>Fill only the fields needed by the operation. Product detail uses the catalog selection and comma-separated variant IDs. SKU lookup uses the exact product, blueprint, provider and literal SKU. Artwork lookup uses the product detail fields plus the linked image ID.</p>';
        foreach (self::INPUT_FIELDS as $field) {
            echo '<p><label>'.\esc_html($field).' <input style="display:block;width:100%;max-width:24rem;min-height:44px" name="df_printify_get['.\esc_attr($field).']" maxlength="200"></label></p>';
        }
        echo '<button class="button button-primary" style="min-height:44px" type="submit">'.($workspace === null ? 'Prepare GET' : ($synthetic ? 'Refresh synthetic observation' : 'Refresh Printify read')).'</button></form></section>';
    }

    private static function renderContinuation(string $operation,array $data,string $profile,string $workspace): void
    {
        if (!in_array($operation,['PRODUCTS_READ','ORDERS_READ'],true)) return;
        $current = $data['current_page'] ?? null;
        $page = $data['next_page']['page'] ?? null;
        if (!is_int($current) || !is_int($page) || $current<1 || $page!==$current+1 || $page>ProtectedGet::MAX_PAGE) return;
        self::renderReadAction($operation,['page'=>$page],$profile,$workspace,'Read next page');
    }

    private static function renderNavigation(string $operation,array $data,string $profile,string $workspace): void
    {
        if ($operation === 'PRODUCTS_READ') {
            foreach ($data['data'] as $product) {
                $fields = self::productSelection($product);
                if ($fields !== null) self::renderReadAction('PRODUCT_READ',$fields,$profile,$workspace,'Read product '.$product['id']);
            }
            return;
        }
        if ($operation === 'PRODUCT_READ') {
            $fields = self::productSelection($data);
            if ($fields === null) return;
            $images = [];
            foreach ($data['print_areas'] as $area) {
                foreach ($area['placeholders'] as $position) {
                    foreach ($position['images'] as $image) {
                        $images[$image['id']] = ['id'=>$image['id'],'title'=>$image['metadata']['name'] ?? null];
                    }
                }
            }
            if ($images !== []) self::renderReadAction('PRODUCT_ARTWORK_READ',$fields,$profile,$workspace,
                'Read selected linked artwork',['image_id',array_values($images)]);
            return;
        }
        if ($operation === 'VARIANT_READ' && isset($data['blueprint_id'],$data['provider_id'])) {
            self::renderReadAction('CATALOG_SHIPPING_READ',array_intersect_key($data,['blueprint_id'=>true,'provider_id'=>true]),
                $profile,$workspace,'Read provider shipping methods');
            return;
        }
        $action = match ($operation) {
            'BLUEPRINTS_READ' => ['BLUEPRINT_PROVIDERS_READ',[],'blueprint_id',$data,'Read selected blueprint providers'],
            'BLUEPRINT_PROVIDERS_READ' => ['VARIANTS_READ',['blueprint_id'=>$data[0]['blueprint_id'] ?? null],
                'provider_id',$data,'Read selected provider variants'],
            'ORDERS_READ' => ['ORDER_READ',[],'order_id',$data['data'],'Read selected order'],
            'CATALOG_SHIPPING_READ' => ['CATALOG_SHIPPING_METHOD_READ',
                ['blueprint_id'=>$data['blueprint_id'] ?? null,'provider_id'=>$data['provider_id'] ?? null],
                'method',array_map(static fn(string $method): array => ['id'=>$method],$data['methods']),
                'Read selected shipping rates'],
            'VARIANTS_READ' => ['VARIANT_READ',['blueprint_id'=>$data['blueprint_id'],'provider_id'=>$data['provider_id']],
                'variant_id',$data['variants'],'Read selected variant'],
            default => null,
        };
        if ($action === null || $action[3] === [] || in_array(null,$action[1],true)) return;
        [$next,$fixed,$field,$options,$label] = $action;
        self::renderReadAction($next,$fixed,$profile,$workspace,$label,[$field,$options]);
    }

    private static function productSelection(array $product): ?array
    {
        $variants = implode(',',array_column($product['variants'],'id'));
        if ($variants === '' || strlen($variants)>200) return null;
        return ['product_id'=>$product['id'],'blueprint_id'=>$product['blueprint_id'],
            'provider_id'=>$product['print_provider_id'],'variant_ids'=>$variants];
    }

    private static function renderReadAction(string $operation,array $fields,string $profile,string $workspace,
        string $label,?array $selection=null): void
    {
        echo '<form method="post">';
        $hidden = [self::ACTION=>'1','df_printify_get_revision'=>$profile,'df_printify_get_workspace'=>$workspace,
            'df_printify_get_operation'=>$operation,'df_printify_get_nonce['.$operation.']'=>\wp_create_nonce(self::ACTION.':'.$operation)];
        foreach ($fields as $key=>$value) $hidden['df_printify_get['.$key.']']=(string)$value;
        foreach ($hidden as $name=>$value) {
            echo '<input type="hidden" name="'.\esc_attr($name).'" value="'.\esc_attr($value).'">';
        }
        if ($selection !== null) {
            [$field,$rows] = $selection;
            echo '<label>'.\esc_html(ucfirst(str_replace('_id','',$field))).' <select style="max-width:100%" name="df_printify_get['.\esc_attr($field).']">';
            foreach ($rows as $row) {
                $id = (string)$row['id'];
                echo '<option value="'.\esc_attr($id).'">'.\esc_html(($row['title'] ?? $id).' · '.$id).'</option>';
            }
            echo '</select></label> ';
        }
        echo '<button type="submit" class="button" style="min-height:44px">'.\esc_html($label).'</button></form>';
    }

    private static function renderEvidence(array $events): void
    {
        $evidence = json_encode(['schema'=>'DF_READ_OUTCOMES_1','outcomes'=>$events],JSON_PRETTY_PRINT);
        if ($evidence === false) return;
        echo '<details><summary>Copy redacted evidence</summary><label>Current session outcomes (JSON)'
            .'<textarea readonly rows="6" style="width:100%">'.\esc_textarea($evidence).'</textarea></label></details>';
    }

    private static function renderOrder(string $operation,array $data): void
    {
        if ($operation !== 'ORDER_READ' || !isset($data['id'],$data['status'],$data['shipments'])) return;
        echo '<h3>Order and tracking</h3><p>'.\esc_html($data['id'].' · '.$data['status']).'</p>';
        if ($data['shipments'] === []) {
            echo '<p>Shipment evidence is unavailable in this observation.</p>';
            return;
        }
        echo '<ul>';
        foreach ($data['shipments'] as $shipment) {
            echo '<li>'.\esc_html($shipment['carrier'].' · Tracking: '.($shipment['tracking_reference'] ?? 'UNKNOWN')
                .' · Delivered: '.($shipment['delivered_at'] ?? 'UNKNOWN')).'</li>';
        }
        echo '</ul>';
    }

    public static function prepare(array $post): array
    {
        $denied = ProtectedGet::denied();
        $operation = $post['df_printify_get_operation'] ?? null;
        $nonces = $post['df_printify_get_nonce'] ?? null;
        if (!\current_user_can('manage_options') || !is_string($operation) || !isset(self::FIELDS[$operation])
            || !is_array($nonces) || count($nonces) > count(self::FIELDS)
            || !is_string($nonces[$operation] ?? null)
            || !\wp_verify_nonce($nonces[$operation], self::ACTION.':'.$operation)) {
            return ['reason'=>'OPERATOR_DENIED'] + $denied;
        }
        $profile = AccountProfile::current();
        $revision = AccountProfile::revisionToken();
        $session = \wp_get_session_token();
        if ($profile === null || $revision === null || $session === ''
            || $revision !== ($post['df_printify_get_revision'] ?? null)) {
            return ['reason'=>'SCOPE_OR_REVISION_UNAVAILABLE'] + $denied;
        }
        $fields = $post['df_printify_get'] ?? null;
        if (!is_array($fields) || count($fields) > count(self::INPUT_FIELDS)) {
            return ['reason'=>'INVALID_SELECTION'] + $denied;
        }
        $selection = [];
        try {
            foreach ($fields as $name => $value) {
                if ($value === '') {
                    continue;
                }
                if (!in_array($name, self::FIELDS[$operation], true) || !is_string($value) || strlen($value) > 200) {
                    throw new \InvalidArgumentException('Unexpected selection.');
                }
                if ($name === 'variant_ids') {
                    $selection[$name] = array_map(self::positiveInteger(...), explode(',', $value));
                } elseif (in_array($name, ['blueprint_id','provider_id','variant_id','page'], true)) {
                    $selection[$name] = self::positiveInteger($value);
                } else {
                    $selection[$name] = $value;
                }
            }
            $scope = self::scope($profile, $revision, $session);
            $request = ProtectedGet::request($scope, $operation, $selection);
            if ($profile !== AccountProfile::current() || $revision !== AccountProfile::revisionToken()) {
                return ['reason'=>'SCOPE_OR_REVISION_UNAVAILABLE'] + $denied;
            }
            if (isset($post['df_printify_get_workspace'])) {
                return self::refresh($request, $post['df_printify_get_workspace'], $session);
            }
            return ['state'=>'PREPARED_OFFLINE','path'=>$request['path']] + $denied;
        } catch (\Throwable) {
            return ['reason'=>'INVALID_SELECTION'] + $denied;
        }
    }

    private static function scope(array $profile, string $revision, string $session): array
    {
        return $profile + ['site'=>'wp-'.hash('sha256', \home_url('/')), 'actor'=>\get_current_user_id(),
            'session'=>hash_hmac('sha256', $session, \wp_salt('auth')), 'revision'=>$revision];
    }

    private static function refresh(array $request, mixed $revision, string $session): array
    {
        $blocked = ProtectedGet::denied();
        $scope = $request['scope'];
        $direct = DirectGet::selected();
        $snapshot = ProtectedGetWorkspace::snapshot($direct);
        if ($snapshot === null || $snapshot['revision'] !== $revision) {
            return ['reason'=>'WORKSPACE_UNAVAILABLE_OR_STALE'] + $blocked;
        }
        if (!self::currentSession($scope['actor'], $session)) return $blocked;
        $key = defined('DIGIFORGE_PRINTIFY_FIXTURE_REPLY_KEY') ? DIGIFORGE_PRINTIFY_FIXTURE_REPLY_KEY : null;
        if (!$direct && (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key))) {
            return $blocked;
        }

        $correlation = bin2hex(random_bytes(16));
        try {
            if ($direct) {
                $result = DirectGet::execute($request, static fn(): bool =>
                    ProtectedGetWorkspace::reserveDirect($scope,$revision)
                    && AccountProfile::revisionToken() === $scope['revision']
                    && \current_user_can('digiforge_printify_review_shop_'.$scope['shop'])
                    && self::currentSession($scope['actor'],$session));
            } elseif (defined('DIGIFORGE_PRINTIFY_GET_HTTP_FIXTURE') && DIGIFORGE_PRINTIFY_GET_HTTP_FIXTURE === true) {
                require_once __DIR__.'/ProtectedGetHttp.php';
                $reply = ProtectedGetHttp::exchange($request, $correlation, $revision);
            } else {
                $reply = \apply_filters('digiforge_printify_protected_get_fixture_reply', null, $request, $correlation);
            }
            if (!$direct) {
                $result = is_array($reply)
                    ? ProtectedGet::verifyReply($request, $correlation, $reply, hex2bin($key), time()) : $blocked;
            }
        } catch (\Throwable) {
            $result = ['state'=>'UNAVAILABLE','reason'=>'GATEWAY_UNAVAILABLE'] + $blocked;
        }
        if (($direct && !DirectGet::gatesOpen()) || AccountProfile::revisionToken() !== $scope['revision']
            || !\current_user_can('digiforge_printify_review_shop_'.$scope['shop'])
            || !self::currentSession($scope['actor'], $session)) {
            return ['reason'=>'SCOPE_OR_REVISION_UNAVAILABLE'] + $blocked;
        }

        $reason = $result['reason'] ?? null;
        if (!is_string($reason) || !preg_match('/^[A-Z_]{1,60}$/D', $reason)) {
            $reason = $result['state'] === 'OBSERVED' ? 'SYNTHETIC_OBSERVED' : 'GATEWAY_UNAVAILABLE';
        }
        $receipt = ['operation'=>$request['operation'],'state'=>$result['state'],'reason'=>$reason,
            'request_digest'=>ProtectedGet::digest($request),'correlation_id'=>$correlation];
        if ($result['state'] === 'OBSERVED') {
            $receipt += array_intersect_key($result, array_flip(['source','observed_at','response_sha256','data']));
        }
        if (isset($result['retry_after_seconds'])) $receipt['retry_after_seconds'] = $result['retry_after_seconds'];
        if (!ProtectedGetWorkspace::persist($scope, $revision, $receipt,$direct)) {
            return ['reason'=>'OBSERVATION_NOT_COMMITTED'] + $blocked;
        }
        return ['state'=>$receipt['state'],'reason'=>$reason] + $blocked;
    }

    private static function currentSession(int $actor, string $token): bool
    {
        return \get_current_user_id() === $actor && \wp_get_session_token() === $token
            && \WP_Session_Tokens::get_instance($actor)->verify($token);
    }

    private static function positiveInteger(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1 || (int)$value > 2147483647) {
            throw new \InvalidArgumentException('Invalid positive integer.');
        }
        return (int)$value;
    }
}
