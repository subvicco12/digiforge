<?php
declare(strict_types=1);
namespace DigiForge\Research;
use WP_Error;
/** Server-owned image/artwork adapter. status() is local/read-only; never accept client URLs or credentials. */
interface DesignGenerationProvider
{
    public function status(): array;
    /** Exact result: asset payload plus bounded provider/model/response_id/generated_at provenance. */
    public function generate(array $specification,string $attemptKey): array|WP_Error;
}
