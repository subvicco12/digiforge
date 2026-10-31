<?php
declare(strict_types=1);
namespace DigiForge\Finance;

/** Owner-supplied header witness only, not original-file custody or recognized financial transactions. */
final class EtsyMonthlyCsvStage
{
    public const SCHEMA='digiforge-etsy-monthly-csv-observation-v1';
    public const HEADER_SHA256='4b9711a954913dd223c23cde077a22900e836f45803a61442dc0484c81b74f11';
    public const FORMAT='etsy_monthly_csv_v1';
    public static function preview(string $provider,array $documents,array $context,array $selected,int $asOf):array
    {
        return ObservedCsvHeaderStage::preview('etsy',$provider,$documents,$context,$selected,$asOf);
    }
}
