<?php
declare(strict_types=1);

// Run only through WP-CLI in a disposable local WordPress with the plugin loaded.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local') {
    throw new RuntimeException('Local WordPress CLI fixture required');
}
require_once WP_PLUGIN_DIR.'/digiforge-printify-api/CustodyReadTransport.php';

if (!is_array($args??null) || count($args)!==1 || !in_array($args[0],['prepare','review'],true)) {
    throw new InvalidArgumentException('Expected prepare or review mode');
}
$input=stream_get_contents(STDIN,280577);
if (!is_string($input) || strlen($input)>=280577) {
    throw new InvalidArgumentException('Bounded JSON input required');
}
$data=json_decode($input,true,16,JSON_THROW_ON_ERROR);
if (!is_array($data) || !is_array($data['scope']??null)) {
    throw new InvalidArgumentException('Missing fixture scope');
}
if ($args[0]==='prepare') {
    if (count($data)!==1) throw new InvalidArgumentException('Unexpected preparation input');
    $result=DigiForgePrintifyApi\CustodyReadTransport::prepareHostedFixture($data['scope']);
} else {
    if (count($data)!==4 || !is_string($data['operation_id']??null) || !is_string($data['request_sha256']??null)
        || !is_string($data['export']??null)) throw new InvalidArgumentException('Missing approved intent or export');
    $result=DigiForgePrintifyApi\CustodyReadTransport::reviewHostedFixture(
        $data['scope'],$data['operation_id'],$data['request_sha256'],$data['export']);
}
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
