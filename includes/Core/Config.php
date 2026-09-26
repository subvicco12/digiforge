<?php

declare(strict_types=1);

namespace DigiForge\Core;

/** Authoritative registry for non-secret DigiForge runtime settings. */
final class Config
{
    public const SWITCHES = [
        'stop_all',
        'research',
        'ai',
        'product_development',
        'printify',
        'gelato',
        'etsy_draft',
        'etsy_publish',
        'order_automation',
        'gst_automation',
    ];

    /**
     * Internal activation gates are deliberately non-user-writable.
     *
     * @return array<string, array{type: string, default: bool, writable: bool}>
     */
    public static function settings(): array
    {
        $settings = [
            'activation_authorized' => ['type' => 'boolean', 'default' => false, 'writable' => false],
            'automation_armed' => ['type' => 'boolean', 'default' => false, 'writable' => false],
            'research_activation_authorized' => ['type' => 'boolean', 'default' => false, 'writable' => false],
            'cleanup_on_uninstall' => ['type' => 'boolean', 'default' => false, 'writable' => true],
        ];

        foreach (self::SWITCHES as $switch) {
            $settings[$switch] = [
                'type' => 'boolean',
                'default' => $switch === 'stop_all',
                'writable' => true,
            ];
        }

        return $settings;
    }

    /** @return array<string, bool> */
    public static function default_settings(): array
    {
        return array_map(
            static fn(array $definition): bool => $definition['default'],
            self::settings()
        );
    }

    public static function allowed_switch(string $key): bool
    {
        return in_array($key, self::SWITCHES, true);
    }

    public static function allowed_setting(string $key): bool
    {
        return isset(self::settings()[$key]);
    }

    public static function setting_type(string $key): ?string
    {
        return self::settings()[$key]['type'] ?? null;
    }

    public static function writable_setting(string $key): bool
    {
        return (bool) (self::settings()[$key]['writable'] ?? false);
    }

    public static function valid_value(string $key, mixed $value): bool
    {
        return self::setting_type($key) === 'boolean' && is_bool($value);
    }
}
