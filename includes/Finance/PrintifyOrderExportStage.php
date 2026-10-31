<?php
declare(strict_types=1);
namespace DigiForge\Finance;

/** Observed header profile only. No verified row semantics, paid invoice, transaction or settlement contract. */
final class PrintifyOrderExportStage
{
    public const SCHEMA='digiforge-printify-order-export-observation-v1';
    public const HEADER_SHA256='8b5db86bf5a3855b24a3082e660c97d646743e48023b8d421dea0e65a882c78d';
    public const FORMAT='printify_order_export_v1';

    public static function preview(string $provider,array $documents,array $context,array $selected,int $asOf):array
    {
        return ObservedCsvHeaderStage::preview('printify',$provider,$documents,$context,$selected,$asOf);
    }
}
