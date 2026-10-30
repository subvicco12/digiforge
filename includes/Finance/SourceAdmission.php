<?php
declare(strict_types=1);
namespace DigiForge\Finance;

use WP_Error;

/** Parser selection only: a provider label cannot turn an internal statement into a native schema. */
final class SourceAdmission
{
    public static function preview(string $provider,array $documents,array $context,array $selected,int $asOf):array|WP_Error
    {
        if($provider!=='etsy')return new WP_Error(
            in_array($provider,['printify','gelato','openai'],true)?'finance_source_contract_missing':'finance_source_provider_invalid',
            'A pinned authoritative native source contract is required before admission.',
            ['status'=>422,'state'=>'BLOCKED','authenticity'=>'UNKNOWN','settlement'=>'UNKNOWN','source_completeness'=>'UNKNOWN',
                'persistence_performed'=>false,'accounting_import_authorized'=>false,'period_approval_authorized'=>false,'external_execution_authorized'=>false]);
        return EtsyNativeObservationStage::projection(EtsyNativeObservationStage::preview($documents,$context,$selected,$asOf));
    }
}
