<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

use InvalidArgumentException;

/**
 * Normalizes reusable product definitions before they enter orchestration.
 * Pure contract only: no persistence, scheduling, AI, or external calls.
 */
final class ProductDefinitionContract
{
    /** @return array<string,mixed> */
    public static function normalize(array $input): array
    {
        $key = sanitize_key((string) ($input['key'] ?? ''));
        $category = sanitize_key((string) ($input['category'] ?? ''));
        $shop = sanitize_key((string) ($input['shop'] ?? ''));
        $title = sanitize_text_field((string) ($input['title'] ?? ''));

        if ($key === '' || $category === '' || $title === '') {
            throw new InvalidArgumentException('key, category and title are required.');
        }
        if (! in_array($shop, ['digital', 'goods'], true)) {
            throw new InvalidArgumentException('shop must be digital or goods.');
        }

        $deliverables = self::strings($input['deliverables'] ?? []);
        $qa = self::strings($input['qa_requirements'] ?? []);
        if ($deliverables === [] || $qa === []) {
            throw new InvalidArgumentException('deliverables and qa_requirements must not be empty.');
        }

        return [
            'schema' => 'digiforge-product-definition-v1',
            'key' => $key,
            'category' => $category,
            'shop' => $shop,
            'title' => $title,
            'deliverables' => $deliverables,
            'qa_requirements' => $qa,
            'personalization' => ($input['personalization'] ?? false) === true,
            'external_execution' => false,
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $clean = sanitize_text_field((string) $value);
            if ($clean !== '') {
                $out[$clean] = $clean;
            }
        }
        return array_values($out);
    }
}
