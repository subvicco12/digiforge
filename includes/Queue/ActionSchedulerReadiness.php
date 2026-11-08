<?php
declare(strict_types=1);
namespace DigiForge\Queue;

/** Inspect an optional host-provided dependency; never load, initialize or run it. */
final class ActionSchedulerReadiness
{
    /** Conservative supported API line, verified against upstream 3.5.0 and current 4.2.0 source. */
    public static function inspect(): array
    {
        $evidence = ['state'=>'ABSENT', 'reason_code'=>'DEPENDENCY_ABSENT', 'version'=>null, 'registered_version'=>null, 'minimum_version'=>'3.5.0', 'maximum_major_exclusive'=>5];
        try {
            if (!class_exists('ActionScheduler') && !class_exists('ActionScheduler_Versions') && !class_exists('ActionScheduler_Store') && !function_exists('as_get_scheduled_actions') && !function_exists('as_enqueue_async_action')) return $evidence;
            $evidence['state'] = 'INCOMPATIBLE';
            $evidence['reason_code'] = 'REQUIRED_API_MISSING';
            if (!is_callable(['ActionScheduler','is_initialized']) || !function_exists('as_get_scheduled_actions') || !function_exists('as_enqueue_async_action')) return $evidence;
            $evidence['reason_code'] = 'STATUS_CONTRACT_INVALID';
            foreach (['STATUS_PENDING','STATUS_RUNNING','STATUS_FAILED'] as $status) {
                if (!defined('ActionScheduler_Store::'.$status) || !is_string(constant('ActionScheduler_Store::'.$status))) return $evidence;
            }
            $evidence['reason_code'] = 'INITIALIZATION_CHECK';
            if (\ActionScheduler::is_initialized() !== true) { $evidence['state'] = 'UNINITIALIZED'; $evidence['reason_code'] = 'NOT_INITIALIZED'; return $evidence; }
            $evidence['reason_code'] = 'VERSION_REGISTRY_INVALID';
            if (!is_callable(['ActionScheduler_Versions','instance'])) return $evidence;
            $versions = \ActionScheduler_Versions::instance();
            if (!is_object($versions) || !is_callable([$versions,'latest_version'])) return $evidence;
            $registered = $versions->latest_version();
            if (!is_string($registered) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $registered)) return $evidence;
            $evidence['registered_version'] = $registered;
            // latest_version() is only the highest registered copy, not proof of the loaded copy.
            // plugin_path() points to the source passed to the active ActionScheduler::init().
            $evidence['reason_code'] = 'LOADED_VERSION_UNVERIFIED';
            if (!is_callable(['ActionScheduler','plugin_path'])) return $evidence;
            $path = \ActionScheduler::plugin_path('action-scheduler.php');
            if (!is_string($path) || is_link($path) || !is_file($path) || !is_readable($path)) return $evidence;
            $header = @file_get_contents($path, false, null, 0, 8192);
            if (!is_string($header) || !preg_match('/^[ \t*#\/]*Version:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)[ \t\r]*$/mi', $header, $match)) return $evidence;
            $version = $match[1];
            if (!is_string($version) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)) return $evidence;
            $evidence['version'] = $version;
            $evidence['reason_code'] = 'VERSION_MISMATCH';
            if ($registered !== $version) return $evidence;
            $evidence['reason_code'] = 'VERSION_UNSUPPORTED';
            if (version_compare($version,'3.5.0','<') || version_compare($version,'5.0.0','>=')) return $evidence;
            $evidence['state'] = 'AVAILABLE';
            $evidence['reason_code'] = 'INSPECTION_PASSED';
        } catch (\Throwable) {
            $evidence['state'] = 'UNAVAILABLE';
            $evidence['reason_code'] = 'INSPECTION_EXCEPTION';
        }
        return $evidence;
    }
}
