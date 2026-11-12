<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** Synthetic transport seam only. No credentials or external execution authority. */
interface DriveOfflineTransport
{
    /** Normalized bounded fixture response: status, metadata/files, bytes; never raw errors. */
    public function exchange(array $request, string $sourceBytes): array;
}
