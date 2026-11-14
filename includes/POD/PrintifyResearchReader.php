<?php
declare(strict_types=1);
namespace DigiForge\POD;
interface PrintifyResearchReader
{
    public function describe(string $grant): array|\WP_Error;
    public function collect(string $grant,int $index,string $key): array|\WP_Error;
    public function evidence(string $grant,string $request): array|\WP_Error;
}
