<?php
declare(strict_types=1);

namespace DigiForge\Tests;

final class FinanceDeclarationFixtures
{
    /** Fictional CSV inspection only; supplied identities and headers establish no provider ownership/schema. */
    public static function csvInspection(array $scope,array $overrides=[]):array
    {
        $context=array_replace(['shop_key'=>$scope['shop_key'],'environment'=>$scope['environment']??'test','business_id'=>$scope['business_id'],'store_id'=>$scope['store_id'],
            'external_shop_id'=>777,'external_account_id'=>778,'integration_id'=>3,'source_contract_sha256'=>hash('sha256','digiforge-finance-csv-structure-v1')],$overrides);
        $raw="Fixture ID,Fixture Amount\n001,0.00100\n";
        $capture=array_intersect_key($context,array_flip(['shop_key','environment','business_id','store_id','external_shop_id']))+
            ['source_sha256'=>hash('sha256',$raw),'observed_at'=>'2026-10-19T12:00:00Z','source_reference'=>'local://synthetic/csv-marker'];
        return ['context'=>$context,'documents'=>[['raw'=>$raw,'layout'=>['delimiter'=>',','headers'=>['Fixture ID','Fixture Amount']],'declared_rows'=>1,'capture'=>$capture]]];
    }

    public static function input(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema' => 'digiforge-finance-declaration-v1', 'provider' => 'etsy', 'environment' => 'test',
            'external_transaction_id' => 'offline-payment-7', 'revision' => 1, 'previous_revision_hash' => '',
            'category' => 'REVENUE', 'currency' => 'USD', 'amount' => '10.0000',
            'base_currency' => 'USD', 'base_amount' => '10.0000', 'fx_snapshot_id' => 0,
            'occurred_at' => '2026-10-10T12:00:00Z', 'observed_at' => '2026-10-10T12:01:00Z',
            'source' => ['kind' => 'DECLARATION', 'document_ref' => 'offline/statement-7', 'document_hash' => str_repeat('a', 64)],
            'scope' => ['shop_key' => 'digital', 'business_id' => 1, 'store_id' => 2, 'order_id' => 7,
                'product_version_id' => 3, 'listing_id' => 4, 'provider_mapping_id' => 0, 'variant_ref' => ''],
            'reversal_of_hash' => '',
        ], $overrides);
    }
}
