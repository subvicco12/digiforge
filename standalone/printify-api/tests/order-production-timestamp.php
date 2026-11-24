<?php
declare(strict_types=1);

use DigiForgePrintifyApi\Engine\POD\PrintifyOrderEvidence;

require $argv[1].'/Engine/POD/PrintifyOrderEvidence.php';
$order = ['id'=>'order-1','status'=>'in-production','line_items'=>[]];
foreach (['2024-02-29T10:00:00Z','2026-11-24 11:00:00+0530','2026-11-24T11:00:00+05:30',null] as $value) {
    $result = PrintifyOrderEvidence::project(['sent_to_production_at'=>$value]+$order,'order-1');
    if ($result['sent_to_production_at'] !== $value) {
        throw new RuntimeException('Valid production observation changed');
    }
}
foreach (['2026-02-30T10:00:00Z','2025-02-29T11:00:00Z','2026-04-31T10:00:00Z','2026-11-24T24:00:00Z'] as $value) {
    try {
        PrintifyOrderEvidence::project(['sent_to_production_at'=>$value]+$order,'order-1');
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Invalid production timestamp accepted: '.$value);
}
echo "Production timestamps PASS: four exact valid cases and four rollover denials\n";
