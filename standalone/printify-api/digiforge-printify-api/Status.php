<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (! \defined('ABSPATH')) {
    exit;
}

/** In-process diagnostics. Loading the plugin does not register any WordPress entry points. */
final class Status
{
    /**
     * @return array{schema:string,version:string,mode:string,capabilities:list<string>,stop_all:bool,external_lock:bool,automation:bool,release_hold:bool,provider_execution_authorized:bool}
     */
    public static function snapshot(): array
    {
        return [
            'schema' => 'DF_STANDALONE_PRINTIFY_STATUS_1',
            'version' => '0.6.0',
            'mode' => 'PASSIVE_OFFLINE',
            'capabilities' => [],
            'stop_all' => true,
            'external_lock' => true,
            'automation' => false,
            'release_hold' => true,
            'provider_execution_authorized' => false,
        ];
    }
}
