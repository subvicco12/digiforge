<?php
declare(strict_types=1);
namespace DigiForge\Research;
use WP_Error;
/** Server-owned adapter only. No client URL, key or executable tool input. */
interface ResearchProvider
{
    public function status(): array;
    public function generate(array $research,string $attemptKey): array|WP_Error;
}
