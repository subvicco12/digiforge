#!/usr/bin/env php
<?php
declare(strict_types=1);
// CLI-only offline adapter. No WordPress bootstrap, persistence, approvals or provider calls.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root=dirname(__DIR__);
foreach(['Research/OpportunityDesignContract','Research/CommercialResearchContract','Research/DesignFactoryContract','Research/ArtworkPersonalizationContract','Listings/Validator','POD/PersonalizationPayloadContract','POD/LocalPersonalizationProof','POD/OfflineDateDisplayProof'] as $class) require_once $root.'/includes/'.$class.'.php';
if (!function_exists('wp_json_encode')) { function wp_json_encode($value,$flags=0) {return json_encode($value,$flags);} }
try {
    $raw=stream_get_contents(STDIN,131073);
    if (!is_string($raw)||strlen($raw)>131072) throw new InvalidArgumentException('Bounded JSON request required.');
    $r=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($r)||count($r)!==5||array_diff(array_keys($r),['product','specification','artwork_svg','canonical_inputs','policy'])) throw new InvalidArgumentException('Exact five-field offline request required.');
    $proof=\DigiForge\POD\OfflineDateDisplayProof::render($r['product'],$r['specification'],$r['artwork_svg'],$r['canonical_inputs'],$r['policy']);
    echo json_encode($proof,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit($proof['proof']['state']==='MANUAL_HOLD'?3:0);
} catch (Throwable) {
    fwrite(STDERR,"OFFLINE_DATE_PROOF_REJECTED: invalid request, source, policy, mapping or canonical values.\n");
    exit(2);
}
