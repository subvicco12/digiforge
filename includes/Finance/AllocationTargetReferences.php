<?php
declare(strict_types=1);

namespace DigiForge\Finance;

/** Independent current-storage boundary; this is not an approval or caller evidence contract. */
interface AllocationTargetReferences
{
    /**
     * Resolve the exact order line and its current business/store/shop/product/version/
     * listing/provider-variant/environment/currency lineage from stored records.
     * Return ['target' => canonical target tuple, 'reference_hash' => SHA-256 of
     * current stored lineage]. Never echo caller data as proof. Missing, ambiguous,
     * unavailable or incompatible records must throw InvalidArgumentException.
     * Source declaration ownership/FX validation remains the repository's boundary.
     */
    public function resolve(array $source, array $target): array;
}
