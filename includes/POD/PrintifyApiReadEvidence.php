<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Read-only response projection; provider pagination links are never followed. */
final class PrintifyApiReadEvidence
{
    public static function project(string $operation, array $data, string $shop, array $input): array
    {
        if ($operation === 'ORDER_READ') { return PrintifyOrderEvidence::project($data, (string)$input['order_id']); }
        if ($operation === 'ORDERS_READ') {
            if (!is_array($data['data'] ?? null) || !array_is_list($data['data']) || count($data['data']) > 10 || !is_int($data['current_page'] ?? null) || $data['current_page'] !== (int)($input['page'] ?? 1)) { throw new \InvalidArgumentException('Invalid bounded order page.'); }
            $rows = []; $seen = [];
            foreach ($data['data'] as $order) {
                if (!is_array($order) || !is_string($order['id'] ?? null) || isset($seen[$order['id']])) { throw new \InvalidArgumentException('Invalid order identity.'); }
                $seen[$order['id']] = true;
                $rows[] = PrintifyOrderEvidence::project($order, $order['id']);
            }
            return ['data'=>$rows,'current_page'=>$data['current_page'],'pagination_followed'=>false];
        }
        if ($operation === 'SHOPS_READ') {
            self::rows($data, 100); $matches = []; $seen = [];
            foreach ($data as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || isset($seen[$row['id']]) || !is_string($row['title'] ?? null) || strlen($row['title']) > 300 || !is_string($row['sales_channel'] ?? null) || strlen($row['sales_channel']) > 100) { throw new \InvalidArgumentException('Invalid shop evidence.'); }
                $seen[$row['id']] = true;
                if ((string)$row['id'] === $shop) { $matches[] = array_intersect_key($row, array_flip(['id','title','sales_channel'])); }
            }
            if (count($matches) !== 1) { throw new \InvalidArgumentException('Configured shop absent from account.'); }
            return ['shops'=>$matches,'other_shop_data_disclosed'=>false];
        }
        if ($operation === 'PROVIDERS_READ' || $operation === 'PROVIDER_READ') {
            $rows = $operation === 'PROVIDER_READ' ? [$data] : $data; self::rows($rows, 1000); $out = []; $seen = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || isset($seen[$row['id']]) || !is_string($row['title'] ?? null) || strlen($row['title']) > 300) { throw new \InvalidArgumentException('Malformed provider evidence.'); }
                if ($operation === 'PROVIDER_READ' && $row['id'] !== (int)$input['provider_id']) { throw new \InvalidArgumentException('Provider identity mismatch.'); }
                $seen[$row['id']] = true; $out[] = ['id'=>$row['id'],'title'=>$row['title'],'regional_shipping_verified'=>false];
            }
            return ['providers'=>$out];
        }
        throw new \InvalidArgumentException('No admitted response projection for operation.');
    }
    public static function supported(string $operation): bool
    {
        return in_array($operation, ['SHOPS_READ','PROVIDERS_READ','PROVIDER_READ','ORDER_READ','ORDERS_READ'], true);
    }
    private static function rows(array $rows, int $max): void
    {
        if (!array_is_list($rows) || count($rows) > $max) { throw new \InvalidArgumentException('Unbounded provider list.'); }
    }
}
