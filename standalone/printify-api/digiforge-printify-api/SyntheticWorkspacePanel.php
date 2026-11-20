<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

final class SyntheticWorkspacePanel
{
    private const ACTION = 'df_printify_synthetic_workspace_v1';
    private const EXTRA_ROWS = 3;

    public static function render(SyntheticWorkspace $workspace, string $shop, array $shops): void
    {
        try {
            $current = $workspace->inspect();
            $result = self::post($workspace,$shop);
            if ($result !== null) {
                $current = $workspace->inspect();
            }
        } catch (\Throwable) {
            echo '<p role="alert">Synthetic workspace state could not be verified.</p>';
            return;
        }

        echo '<h2>Synthetic operator workspace</h2><p>Configured fixture shop '
            .\esc_html($shop).'. Provider execution is disabled. Enter fixture values only.</p>';
        if ($result !== null) {
            if (($result['reason'] ?? null) === 'INVALID_OPERATION_FIELD') {
                $field = self::FIELD_LABELS[$result['field']] ?? 'Required fields';
                echo '<p role="alert">Check the fixture input for '.\esc_html($field).'.</p>';
            } elseif (($result['state'] ?? null) === 'UNKNOWN') {
                echo '<p role="alert">The synthetic outcome is unknown. Inspect the audit and read back the fixture state.</p>';
            }
            $preview=$result['data']['synthetic_svg']??null;
            unset($result['data']['synthetic_svg']);
            if (is_string($preview) && strlen($preview)<=32768 && preg_match('/^[A-Za-z0-9+\/=]+$/D',$preview)===1) {
                echo '<figure><img alt="Locally rendered synthetic preview" src="data:image/svg+xml;base64,'
                    .\esc_attr($preview).'"></figure>';
            }
            echo '<h3>Operation result</h3><pre>'.\esc_html(
                json_encode($result, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''
            ).'</pre>';
        }

        if (count($shops)>1) {
            echo '<form method="get"><input type="hidden" name="page" value="digiforge-printify-api">';
            echo '<label for="df-fixture-shop">Fixture shop</label> <select id="df-fixture-shop" name="df_workspace_shop">';
            foreach ($shops as $id) {
                $id=(string)$id;
                echo '<option value="'.\esc_attr($id).'"'.($shop===$id?' selected':'').'>'.\esc_html($id).'</option>';
            }
            echo '</select> <button type="submit">Open workspace</button></form>';
        }
        self::renderOperationForms($shop, $current['revision']);
        echo '<details><summary>Advanced operation input (JSON)</summary>';
        self::startForm($shop, $current['revision']);
        echo '<p><label for="df-workspace-operation">Operation</label> ';
        echo '<select id="df-workspace-operation" name="df_workspace_operation">';
        $operations=array_values(array_diff(SyntheticPrintifyClient::operations(),['ORDER_SEND_TO_PRODUCTION']));
        foreach ([...$operations,'FIXTURE_PRODUCTION_CONFIRM','FIXTURE_FULFILL','FIXTURE_WEBHOOK_EVENT'] as $op) {
            echo '<option value="'.\esc_attr($op).'">'.\esc_html($op).'</option>';
        }
        echo '</select></p><p><label for="df-workspace-input">Operation input (JSON)</label><br>';
        echo '<textarea id="df-workspace-input" class="large-text code" name="df_workspace_input" rows="7" spellcheck="false">{}</textarea></p>';
        echo '<details><summary>Input examples</summary><ul>';
        foreach ([
            'Catalog list: {}. Variants: {"blueprint_id":42,"provider_id":7}.',
            'Artwork: file_name and base64 contents of a PNG.',
            'Product creation: title, description, blueprint_id, print_provider_id, variants and print_areas.',
            'Product update: product_id, expected_version and changed fields. Read the product first to find its synthetic version.',
            'Shipping quote: {"order":{…}}. Order creation: external_id, line_items, shipping_method, flags and a fixture address_to.',
            'Order read, cancellation, synthetic production and fulfillment: {"order_id":"order-1"}.',
            'Event fixture: id, type, created_at and resource with type, id and integer shop_id under data.',
        ] as $hint) echo '<li>'.\esc_html($hint).'</li>';
        echo '</ul></details><p><button type="submit" class="button button-primary">Run synthetic JSON operation</button></p></form></details>';

        echo '<h3>Recent synthetic audit</h3><table class="widefat striped"><thead><tr>';
        foreach (['Operation','Result','Reason','Reference','Actor ID','Time (UTC)'] as $label) echo '<th scope="col">'.\esc_html($label).'</th>';
        echo '</tr></thead><tbody>';
        foreach (array_reverse(array_slice($current['audit'],-20)) as $entry) {
            echo '<tr>';
            foreach (['operation','state','reason','operation_id','actor_id','occurred_at'] as $field) {
                echo '<td>'.\esc_html((string)($entry[$field]??'—')).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        self::renderEvents($current['events'] ?? []);
    }

    private static function renderEvents(array $events): void
    {
        if ($events===[]) {
            return;
        }
        echo '<h3>Recent fixture event observations</h3><table class="widefat striped"><thead><tr>';
        foreach (['Event','Resource ID','Event time','Shipment time'] as $label) {
            echo '<th scope="col">'.\esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_reverse(array_slice($events,-10)) as $event) {
            echo '<tr>';
            foreach ([$event['type']??null,$event['resource_id']??$event['order_id']??null,
                $event['created_at']??null,$event['shipment_at']??null] as $value) {
                echo '<td>'.\esc_html(is_string($value)&&strlen($value)<=160?$value:'—').'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function startForm(string $shop, int $revision, bool $multipart = false): void
    {
        echo '<form method="post"'.($multipart?' enctype="multipart/form-data"':'').'>';
        \wp_nonce_field(self::ACTION.':'.$shop,'df_workspace_nonce',false);
        foreach (['shop'=>$shop, 'revision'=>(string)$revision, 'id'=>bin2hex(random_bytes(32))] as $field=>$value) {
            echo '<input type="hidden" name="df_workspace_'.$field.'" value="'.\esc_attr($value).'">';
        }
    }

    /** Labels and typed input keys for every operation exposed by the workspace. */
    private const FORMS = [
        'Shops and catalog'=>[
            'SHOPS_READ'=>['List shops', []],
            'BLUEPRINTS_READ'=>['List blueprints', []],
            'BLUEPRINT_READ'=>['Inspect blueprint', ['blueprint_id']],
            'PROVIDERS_READ'=>['List print providers', []],
            'PROVIDER_READ'=>['Inspect print provider', ['provider_id']],
            'BLUEPRINT_PROVIDERS_READ'=>['List providers for a blueprint', ['blueprint_id']],
            'BLUEPRINT_PROVIDER_READ'=>['Inspect a blueprint provider', ['blueprint_id','provider_id']],
            'VARIANTS_READ'=>['List variants and print areas', ['blueprint_id','provider_id']],
            'VARIANT_READ'=>['Inspect a variant', ['blueprint_id','provider_id','variant_id']],
            'SIZE_GUIDE_READ'=>['Inspect size guide', ['blueprint_id']],
            'CATALOG_SHIPPING_READ'=>['Inspect catalog shipping', ['blueprint_id','provider_id']],
            'CATALOG_SHIPPING_METHOD_READ'=>['Inspect a catalog shipping method', ['blueprint_id','provider_id','method']],
        ],
        'Fixture artwork and products'=>[
            'IMAGE_UPLOAD'=>['Validate and upload fixture PNG', ['file_name','contents']],
            'IMAGE_READ'=>['Inspect fixture image', ['image_id']],
            'UPLOADS_READ'=>['List fixture images', ['page','limit']],
            'IMAGE_ARCHIVE'=>['Archive fixture image', ['image_id']],
            'PRODUCT_CREATE'=>['Create fixture product', ['title','description','blueprint_id','print_provider_id','variant_id','price','image_id','position','x','y','scale','angle']],
            'PRODUCT_UPDATE'=>['Update fixture product', ['product_id','expected_version','title','description','variant_id','price']],
            'PRODUCT_DELETE'=>['Delete fixture product', ['product_id','expected_version']],
            'PRODUCT_READ'=>['Inspect fixture product', ['product_id','blueprint_id','provider_id','variant_id']],
            'PRODUCTS_READ'=>['List fixture products', ['page','limit']],
            'PRODUCT_GPSR_READ'=>['Inspect GPSR fixture metadata', ['product_id','blueprint_id','provider_id','variant_id']],
        ],
        'Personalization'=>[
            'PERSONALIZATION_OPTIONS'=>['Inspect fixture personalization fields',['product_id']],
            'FIXTURE_PREVIEW_COMPLETE'=>['Complete local fixture preview',['product_id','task_id']],
            'PREVIEW_READ'=>['Inspect fixture preview task',['product_id','task_id']],
        ],
        'Shipping, orders and tracking'=>[
            'ORDER_SHIPPING_QUOTE'=>['Quote fixture shipping', ['external_id','product_id','variant_id','quantity','shipping_method']],
            'SHOP_DISCONNECT'=>['Disconnect fixture shop', []],
            'ORDER_EXPRESS_CREATE'=>['Create fixture Express order', ['external_id','product_id','variant_id','quantity']],
            'ORDER_CREATE'=>['Create fixture order', ['external_id','product_id','variant_id','quantity','shipping_method']],
            'ORDERS_READ'=>['List fixture orders', ['page','limit']],
            'ORDER_READ'=>['Inspect order and tracking', ['order_id']],
            'ORDER_CANCEL'=>['Cancel fixture order', ['order_id']],
            'LINE_ITEM_CANCEL'=>['Cancel fixture order line', ['order_id','line_item_id']],
            'FIXTURE_PRODUCTION_CONFIRM'=>['Confirm simulated production', ['order_id']],
            'FIXTURE_FULFILL'=>['Simulate fulfillment', ['order_id']],
        ],
        'Webhooks and events'=>[
            'WEBHOOKS_READ'=>['List fixture webhooks', []],
            'WEBHOOK_CREATE'=>['Create fixture webhook', ['topic','url']],
            'WEBHOOK_UPDATE'=>['Update fixture webhook', ['webhook_id','url']],
            'WEBHOOK_SIMULATE'=>['Simulate fixture webhook', ['webhook_id']],
            'WEBHOOK_DELETE'=>['Delete fixture webhook', ['webhook_id']],
            'FIXTURE_WEBHOOK_EVENT'=>['Record fixture order-created event', ['event_id','created_at','order_id']],
        ],
    ];

    private static function renderOperationForms(string $shop, int $revision): void
    {
        $supported = [...SyntheticPrintifyClient::operations(), 'FIXTURE_PRODUCTION_CONFIRM', 'FIXTURE_FULFILL', 'FIXTURE_WEBHOOK_EVENT','FIXTURE_PREVIEW_COMPLETE'];
        foreach (self::FORMS as $section=>$operations) {
            echo '<h3>'.\esc_html($section).'</h3>';
            foreach ($operations as $op=>[$label,$fields]) {
                if (!in_array($op,$supported,true)) {
                    continue;
                }
                echo '<details><summary>'.\esc_html($label).'</summary>';
                self::startForm($shop,$revision,$op==='IMAGE_UPLOAD');
                echo '<input type="hidden" name="df_workspace_operation" value="'.\esc_attr($op).'">';
                echo '<input type="hidden" name="df_workspace_fields_present" value="1">';
                if ($op === 'ORDER_SHIPPING_QUOTE' || $op === 'ORDER_CREATE') {
                    echo '<p>Uses a fixed synthetic US shipping address.</p>';
                }
                if ($op === 'PRODUCT_UPDATE') {
                    echo '<p>Read the product to find its synthetic version. For a variant edit, include every current variant and its price.</p>';
                }
                if ($op === 'PRODUCT_CREATE' || $op === 'PRODUCT_UPDATE') {
                    echo '<p>When setting placements, include artwork for each selected variant. Blank geometry centers the artwork at full scale.</p>';
                }
                if ($op === 'FIXTURE_PRODUCTION_CONFIRM') {
                    echo '<p>Submit this separate confirmation to advance the fixture order into simulated production.</p>';
                }
                foreach ($fields as $field) {
                    self::renderField($op,$field);
                }
                self::renderRows($op);
                if ($op === 'IMAGE_UPLOAD') {
                    echo '<p><label for="df-fixture-artwork">Fixture PNG file</label><br>';
                    echo '<input id="df-fixture-artwork" type="file" name="df_workspace_artwork" accept=".png,image/png"></p>';
                    echo '<p>Choose one PNG file or paste its base64 contents.</p>';
                }
                echo '<p><button type="submit" class="button">'.\esc_html($label).'</button></p></form></details>';
            }
        }
    }

    private static function renderField(string $op, string $field, ?string $group=null, int $row=0): void
    {
        $label = self::FIELD_LABELS[$field];
        $id = 'df-synthetic-'.strtolower($op).($group===null?'':"-$group-$row").'-'.$field;
        $key = $group===null ? "[$field]" : "[$group][$row][$field]";
        $attrs = ' id="'.\esc_attr($id).'" name="df_workspace_fields'.\esc_attr($key).'"';
        $required = $group!==null || self::optional($op,$field) || $field==='contents' ? '' : ' required';
        $value = $group===null ? (self::FIELD_DEFAULTS[$field] ?? '') : '';
        $options = self::options($field);
        echo '<p><label for="'.\esc_attr($id).'">'.\esc_html($label).'</label><br>';
        if ($options !== null) {
            echo '<select'.$attrs.$required.'>';
            foreach ($options as $option) {
                echo '<option value="'.\esc_attr($option).'"'.($value===$option?' selected':'').'>'.\esc_html($option).'</option>';
            }
            echo '</select>';
        } elseif (in_array($field, ['contents','description'],true)) {
            echo '<textarea'.$attrs.$required.' class="large-text" maxlength="'.self::length($field).'" rows="3" spellcheck="false"></textarea>';
        } else {
            echo '<input type="text"'.$attrs.$required.' value="'.\esc_attr($value).'" maxlength="'.self::length($field).'">';
        }
        echo '</p>';
    }

    private const FIELD_LABELS = [
        'blueprint_id'=>'Blueprint ID','provider_id'=>'Print provider ID','print_provider_id'=>'Print provider ID',
        'variant_id'=>'Variant ID','method'=>'Catalog shipping method','page'=>'Page','limit'=>'Page size',
        'file_name'=>'PNG filename','contents'=>'Fixture PNG contents (base64)','image_id'=>'Image ID',
        'product_id'=>'Product ID','task_id'=>'Preview task ID','title'=>'Product title','description'=>'Product description',
        'price'=>'Price (minor units)','position'=>'Print-area position','x'=>'Horizontal position (0–1)',
        'y'=>'Vertical position (0–1)','scale'=>'Artwork scale','angle'=>'Artwork angle in degrees',
        'expected_version'=>'Expected synthetic product version','external_id'=>'Fixture reference',
        'quantity'=>'Quantity','shipping_method'=>'Shipping method code','order_id'=>'Order ID','line_item_id'=>'Line item ID',
        'topic'=>'Event topic','url'=>'Synthetic callback destination','webhook_id'=>'Webhook ID',
        'event_id'=>'Event UUID','created_at'=>'Event time (YYYY-MM-DD HH:MM:SS+00:00)',
        'variants'=>'Additional variants','placements'=>'Additional artwork placements','line_items'=>'Additional order lines',
    ];

    private const FIELD_DEFAULTS = [
        'page'=>'1','limit'=>'10','blueprint_id'=>'42','provider_id'=>'7','print_provider_id'=>'7','variant_id'=>'9',
        'file_name'=>'fixture.png','position'=>'front','x'=>'0.5','y'=>'0.5','scale'=>'1','angle'=>'0',
        'quantity'=>'1','shipping_method'=>'1','created_at'=>'2026-01-01 00:00:00+00:00',
    ];

    private static function length(string $field): int
    {
        return match ($field) {
            'contents'=>2796204, 'description'=>10000, 'title'=>200, 'file_name'=>124,
            'created_at'=>25, 'event_id'=>36, default=>160,
        };
    }

    private static function optional(string $op, string $field): bool
    {
        return ($op==='PRODUCT_UPDATE' && in_array($field,['title','description','variant_id','price'],true))
            || ($op==='PRODUCT_CREATE' && in_array($field,['x','y','scale','angle'],true));
    }

    private static function options(string $field): ?array
    {
        return match ($field) {
            'method'=>['standard','priority','express','economy'],
            'shipping_method'=>['1','2','3','4'],
            'topic'=>SyntheticPrintifyTransport::WEBHOOK_TOPICS,
            'url'=>SyntheticPrintifyTransport::WEBHOOK_URLS,
            default=>null,
        };
    }

    private static function groups(string $op): array
    {
        return match ($op) {
            'PRODUCT_CREATE','PRODUCT_UPDATE'=>[
                'variants'=>['variant_id','price'],
                'placements'=>['variant_id','image_id','position','x','y','scale','angle'],
            ],
            'PRODUCT_READ','PRODUCT_GPSR_READ'=>['variants'=>['variant_id']],
            'ORDER_SHIPPING_QUOTE','ORDER_CREATE','ORDER_EXPRESS_CREATE'=>['line_items'=>['product_id','variant_id','quantity']],
            default=>[],
        };
    }

    private static function renderRows(string $op): void
    {
        foreach (self::groups($op) as $group=>$fields) {
            for ($row=0; $row<self::EXTRA_ROWS; ++$row) {
                echo '<details><summary>'.\esc_html(self::FIELD_LABELS[$group].' '.($row+1)).'</summary>';
                foreach ($fields as $field) {
                    self::renderField($op,$field,$group,$row);
                }
                echo '</details>';
            }
        }
    }

    private static function readRows(string $group, mixed $rows, array $keys): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>self::EXTRA_ROWS) {
            throw new \InvalidArgumentException($group);
        }
        $typed = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row),$keys)!==[]) {
                throw new \InvalidArgumentException($group);
            }
            $parsed = [];
            foreach ($row as $key=>$value) {
                $value = self::normalize($key,$value);
                if ($value!=='') {
                    $parsed[$key] = self::fieldValue($key,$value);
                }
            }
            if ($parsed===[]) {
                continue;
            }
            $required = $group==='placements' ? ['variant_id','image_id','position'] : $keys;
            if (array_diff($required,array_keys($parsed))!==[]) {
                throw new \InvalidArgumentException($group);
            }
            $typed[] = $parsed;
        }
        return $typed;
    }

    private static function normalize(string $field, mixed $value): string
    {
        $limit = self::length($field);
        if (!is_string($value) || strlen($value)>2*$limit) {
            throw new \InvalidArgumentException($field);
        }
        $value=trim(\wp_unslash($value));
        if (strlen($value)>$limit) {
            throw new \InvalidArgumentException($field);
        }
        return $value;
    }

    private static function fieldInput(string $shop, string $op, mixed $fields): array
    {
        foreach (self::FORMS as $operations) {
            if (isset($operations[$op])) {
                $keys = $operations[$op][1];
                break;
            }
        }
        $groups = self::groups($op);
        if (!isset($keys) || !is_array($fields) || array_diff(array_keys($fields),$keys,array_keys($groups))!==[]) {
            throw new \InvalidArgumentException('OPERATION_FIELDS');
        }
        $typed = [];
        foreach ($keys as $field) {
            $value = self::normalize($field,$fields[$field] ?? '');
            if ($value==='' && self::optional($op,$field)) {
                continue;
            }
            if ($value==='') {
                throw new \InvalidArgumentException($field);
            }
            $typed[$field] = self::fieldValue($field,$value);
        }
        foreach ($groups as $group=>$keys) {
            $typed[$group] = self::readRows($group,$fields[$group] ?? [],$keys);
        }
        return self::assemble($shop,$op,$typed);
    }

    private static function fieldValue(string $field, string $value): string|int|float
    {
        $options = self::options($field);
        if ($options !== null) {
            if (!in_array($value,$options,true)) {
                throw new \InvalidArgumentException($field);
            }
            return $field==='shipping_method'?(int)$value:$value;
        }
        $max = match ($field) {
            'blueprint_id','provider_id','print_provider_id','variant_id','page'=>2147483647,
            'limit'=>10, 'price'=>100000000, 'expected_version','quantity'=>1000, default=>null,
        };
        if ($max !== null) {
            if (preg_match('/^[1-9][0-9]{0,9}$/D',$value)!==1 || (int)$value>$max) {
                throw new \InvalidArgumentException($field);
            }
            return (int)$value;
        }
        if (in_array($field,['x','y','scale','angle'],true)) {
            $maximum = match ($field) { 'x','y'=>1, 'scale'=>10, 'angle'=>360 };
            $minimum = $field==='scale'?0.000001:0;
            if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,6})?$/D',$value)!==1
                || (float)$value<$minimum || (float)$value>$maximum) {
                throw new \InvalidArgumentException($field);
            }
            return (float)$value;
        }
        $ok = match ($field) {
            'title','description'=>preg_match('//u',$value)===1 && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value),
            'file_name'=>preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.png$/D',$value)===1 && !str_contains($value,'..'),
            'contents'=>self::isFixturePng($value),
            'event_id'=>preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$value)===1,
            'created_at'=>preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D',$value)===1
                && ($date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP',$value))!==false
                && $date->format('Y-m-d H:i:sP')===$value,
            default=>preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$value)===1,
        };
        if (!$ok) {
            throw new \InvalidArgumentException($field);
        }
        return $value;
    }

    private static function isFixturePng(string $value): bool
    {
        $bytes = base64_decode($value,true);
        return is_string($bytes) && strlen($bytes)<=2097152 && str_starts_with($bytes,"\x89PNG\r\n\x1a\n")
            && base64_encode($bytes)===$value;
    }

    /** Read only the one bounded multipart file PHP created for this request. */
    private static function artworkFields(string $op, mixed $fields): mixed
    {
        $file = $_FILES['df_workspace_artwork'] ?? null;
        if ($file===null || (is_array($file) && ($file['error']??null)===UPLOAD_ERR_NO_FILE)) {
            return $fields;
        }
        if ($op!=='IMAGE_UPLOAD' || !is_array($fields) || ($fields['contents']??'')!=='' || !is_array($file)
            || ($file['error']??null)!==UPLOAD_ERR_OK || !is_int($file['size']??null) || $file['size']<1 || $file['size']>2097152
            || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('contents');
        }
        $bytes = @file_get_contents($file['tmp_name'],false,null,0,2097153);
        if (!is_string($bytes) || strlen($bytes)!==$file['size'] || !str_starts_with($bytes,"\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('contents');
        }
        $fields['contents'] = base64_encode($bytes);
        return $fields;
    }

    private static function assemble(string $shop, string $op, array $fields): array
    {
        if ($op === 'PRODUCT_CREATE' || $op === 'PRODUCT_UPDATE') {
            return self::assembleProduct($op,$fields);
        }
        if ($op === 'PRODUCT_READ' || $op === 'PRODUCT_GPSR_READ') {
            $fields['variant_ids']=self::variantIds([['variant_id'=>$fields['variant_id']], ...$fields['variants']]);
            unset($fields['variant_id'],$fields['variants']);
        }
        if (in_array($op,['ORDER_CREATE','ORDER_SHIPPING_QUOTE','ORDER_EXPRESS_CREATE'],true)) {
            $method=$op==='ORDER_EXPRESS_CREATE'?3:$fields['shipping_method'];
            $order = [
                'external_id'=>$fields['external_id'],
                'line_items'=>[array_intersect_key($fields,array_flip(['product_id','variant_id','quantity'])), ...$fields['line_items']],
                'shipping_method'=>$method,
                'is_printify_express'=>$method===3, 'is_economy_shipping'=>$method===4,
                'send_shipping_notification'=>false,
                'address_to'=>['first_name'=>'Fixture','last_name'=>'Only','email'=>'workspace@example.invalid','phone'=>'',
                    'country'=>'US','region'=>'CA','address1'=>'Fixture street','address2'=>'','city'=>'Fixture','zip'=>'90001'],
            ];
            if ($op==='ORDER_EXPRESS_CREATE') {
                unset($order['is_printify_express'],$order['is_economy_shipping']);
                return $order;
            }
            return $op==='ORDER_CREATE'?$order:['order'=>$order];
        }
        if ($op === 'FIXTURE_WEBHOOK_EVENT') {
            return [
                'id'=>$fields['event_id'],'type'=>'order:created','created_at'=>$fields['created_at'],
                'resource'=>['id'=>$fields['order_id'],'type'=>'order','data'=>['shop_id'=>(int)$shop]],
            ];
        }
        return $fields;
    }

    private static function assembleProduct(string $op, array $fields): array
    {
        $create = $op==='PRODUCT_CREATE';
        $variants = $fields['variants'];
        $placements = $fields['placements'];
        if (isset($fields['price'])!==isset($fields['variant_id'])) {
            throw new \InvalidArgumentException(isset($fields['price'])?'variant_id':'price');
        }
        if (isset($fields['variant_id'])) {
            array_unshift($variants,['variant_id'=>$fields['variant_id'],'price'=>$fields['price']]);
        }
        if ($create) {
            array_unshift($placements,array_intersect_key($fields,array_flip(self::groups($op)['placements'])));
        }
        $data = array_intersect_key($fields,array_flip(['title','description','blueprint_id','print_provider_id','product_id','expected_version']));
        $ids = self::variantIds($variants);
        if ($ids!==[]) {
            $data['variants']=array_map(static fn(array $v):array=>['id'=>$v['variant_id'],'price'=>$v['price'],'is_enabled'=>true],$variants);
            if (!$create) {
                $data['known_variant_ids']=$ids;
            }
        }
        if ($placements!==[]) {
            $data['print_areas']=self::assemblePrintAreas($placements,$ids);
        }
        if (!$create && count($data)===2) {
            throw new \InvalidArgumentException('PRODUCT_UPDATE_FIELDS');
        }
        return $data;
    }

    private static function variantIds(array $variants): array
    {
        $ids = array_column($variants,'variant_id');
        if (count(array_unique($ids))!==count($ids)) {
            throw new \InvalidArgumentException('variants');
        }
        return $ids;
    }

    private static function assemblePrintAreas(array $placements, array $ids): array
    {
        $areas = [];
        foreach ($placements as $placement) {
            $variant = $placement['variant_id'];
            $position = $placement['position'];
            $image = ['id'=>$placement['image_id'],'x'=>$placement['x']??0.5,'y'=>$placement['y']??0.5,
                'scale'=>$placement['scale']??1.0,'angle'=>$placement['angle']??0.0];
            if (!in_array($variant,$ids,true) || in_array($image,$areas[$variant][$position]??[],true)) {
                throw new \InvalidArgumentException('placements');
            }
            $areas[$variant][$position][] = $image;
        }
        if (array_diff($ids,array_keys($areas))!==[]) {
            throw new \InvalidArgumentException('placements');
        }

        $result = [];
        foreach ($areas as $variant=>$positions) {
            $placeholders = [];
            foreach ($positions as $position=>$images) {
                $placeholders[] = ['position'=>(string)$position,'images'=>$images];
            }
            $result[] = ['variant_ids'=>[$variant],'placeholders'=>$placeholders];
        }
        return $result;
    }

    private static function post(SyntheticWorkspace $workspace,string $shop): ?array
    {
        if (($_SERVER['REQUEST_METHOD']??null)!=='POST' || !isset($_POST['df_workspace_operation'])) {
            return null;
        }
        $nonce=$_POST['df_workspace_nonce']??null;
        $id=$_POST['df_workspace_id']??null;
        $op=$_POST['df_workspace_operation'];
        $revision=$_POST['df_workspace_revision']??null;
        if (($_POST['df_workspace_shop']??null)!==$shop || !is_string($nonce) || !preg_match('/^[a-f0-9]{10}$/D',$nonce)
            || !\wp_verify_nonce($nonce,self::ACTION.':'.$shop)
            || !is_string($id) || !preg_match('/^[a-f0-9]{64}$/D',$id) || !is_string($op) || strlen($op)>40
            || !is_string($revision) || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D',$revision)) {
            return ['state'=>'DENIED','reason'=>'INVALID_FORM_OR_SESSION'];
        }
        if (array_key_exists('df_workspace_fields_present',$_POST)) {
            if ($_POST['df_workspace_fields_present']!=='1' || array_key_exists('df_workspace_input',$_POST)) {
                return ['state'=>'DENIED','reason'=>'INVALID_FORM_OR_SESSION'];
            }
            try {
                $fields=array_key_exists('df_workspace_fields',$_POST)?$_POST['df_workspace_fields']:[];
                $input=self::fieldInput($shop,$op,self::artworkFields($op,$fields));
            } catch (\InvalidArgumentException $e) {
                return ['state'=>'DENIED','reason'=>'INVALID_OPERATION_FIELD','field'=>$e->getMessage()];
            }
            return $workspace->execute((int)$revision,$id,$op,$input);
        }
        $raw=$_POST['df_workspace_input']??null;
        if (array_key_exists('df_workspace_fields',$_POST) || !is_string($raw) || strlen($raw)>3000000) {
            return ['state'=>'DENIED','reason'=>'INVALID_FORM_OR_SESSION'];
        }
        try {
            $json=trim(\wp_unslash($raw));
            $input=json_decode($json,true,24,JSON_THROW_ON_ERROR);
            if (!str_starts_with($json,'{')||!is_array($input)) {
                return ['state'=>'DENIED','reason'=>'INPUT_MUST_BE_JSON_OBJECT'];
            }
            return $workspace->execute((int)$revision,$id,$op,$input);
        } catch (\JsonException) {
            return ['state'=>'DENIED','reason'=>'MALFORMED_OPERATION_JSON'];
        }
    }
}
