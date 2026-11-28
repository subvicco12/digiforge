<?php
declare(strict_types=1);

/** Check the reviewed endpoint matrix against the extracted fixture transport's route gate. */
use DigiForgePrintifyApi\SyntheticPrintifyTransport;

$path = $argv[2] ?? null;
$input = is_string($path) && is_file($path) && !is_link($path) ? file_get_contents($path,false,null,0,1048577) : false;
if (!is_string($input) || strlen($input)>1048576
    || !hash_equals('c96a1a14159e37ca1c8d88082fdf1ccd00287d944e2b93d44c62f1e8bc462062',hash('sha256',$input))) {
    throw new RuntimeException('Unapproved documented-route registry digest');
}
$matrix = fopen('php://memory','w+b');
fwrite($matrix,$input);
rewind($matrix);
define('ABSPATH', __DIR__.'/fixture-wordpress/');
require $argv[1].'/SyntheticPrintifyTransport.php';
$header = $matrix === false ? [] : fgetcsv($matrix, 0, "\t", '"', '');
if (!is_array($header) || array_slice($header, 0, 4)!==['method','exact_path','capability_family','selector']) {
    throw new RuntimeException('Reviewed 55-operation registry required');
}
$placeholders = ['shop_id'=>'107','blueprint_id'=>'42','print_provider_id'=>'7','image_id'=>'image-1',
    'product_id'=>'product-1','order_id'=>'order-1','line_item_id'=>'line-1','webhook_id'=>'webhook-1',
    'supportRequestId'=>'support-order-1-1','task_id'=>'preview-1'];
$inventory = [];
$pairs = [];
while (($row = fgetcsv($matrix, 0, "\t", '"', '')) !== false) {
    [$method,$path,,$operation] = $row;
    $endpoint = preg_replace_callback('/\{(\w+)\}/', static fn(array $m): string => $placeholders[$m[1]]
        ?? throw new RuntimeException('Unrecognized path parameter '.$m[1]), $path);
    if ($operation === 'WEBHOOK_DELETE') {
        $endpoint .= '?host=webhooks.example.invalid';
    }
    $fixture = new SyntheticPrintifyTransport('107');
    $request = ['operation'=>$operation,'method'=>$method,'endpoint'=>$endpoint,'provider_shop_id'=>'107','body'=>[]];
    foreach ([
        [403,['provider_shop_id'=>'108']],
        [404,['method'=>$method==='PATCH'?'POST':'PATCH']],
        [404,['endpoint'=>$endpoint.'/unexpected']],
        [404,['operation'=>'UNKNOWN_DOCUMENTED_SELECTOR']],
    ] as [$status,$invalid]) {
        if ($fixture->request($invalid+$request)['status'] !== $status || $fixture->admittedRequests() !== []) {
            throw new RuntimeException($operation.' admitted a substituted scope, method, path or selector');
        }
    }
    if (str_contains($path, '{shop_id}')) {
        $foreign = str_replace('/shops/107/', '/shops/108/', $endpoint);
        if ($fixture->request(['endpoint'=>$foreign]+$request)['status'] !== 404 || $fixture->admittedRequests() !== []) {
            throw new RuntimeException($operation.' admitted a foreign shop path');
        }
    }
    $substitute = match ($operation) {
        'PROVIDERS_READ', 'PROVIDER_READ' => str_replace('/catalog/', '/catalog/blueprints/42/', $endpoint),
        'BLUEPRINT_PROVIDERS_READ', 'BLUEPRINT_PROVIDER_READ' => str_replace('/blueprints/42', '', $endpoint),
        'WEBHOOK_DELETE' => str_replace('webhooks.example.invalid', 'foreign.example.invalid', $endpoint),
        default => null,
    };
    if ($substitute !== null && ($fixture->request(['endpoint'=>$substitute]+$request)['status'] !== 404
        || $fixture->admittedRequests() !== [])) {
        throw new RuntimeException($operation.' admitted a substituted endpoint identity');
    }
    // Route admission can pass while the bounded fixture rejects the resource or body.
    $fixture->request($request);
    if ($fixture->admittedRequests() !== [['method'=>$method,'endpoint'=>$endpoint,'operation'=>$operation]]) {
        throw new RuntimeException($operation.' did not admit the reviewed route '.$endpoint);
    }
    $inventory[$operation]=true;
    $pairs[$method.' '.$path]=true;
    echo "$operation\t$method\t$path\tPASS\n";
}
if (count($inventory) !== 52 || count($pairs) !== 55) {
    throw new RuntimeException('Incomplete reviewed route inventory');
}
echo "Route admission and boundary negatives PASS (52 selectors, 55 method/path pairs)\n";
