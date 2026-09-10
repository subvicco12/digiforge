<?php
declare(strict_types=1);
namespace DigiForge\Core;
final class Config {
    public const SWITCHES = ['stop_all','research','ai','product_development','printify','gelato','etsy_draft','etsy_publish','order_automation','gst_automation'];
    public static function default_settings(): array { return array_fill_keys(self::SWITCHES, false) + ['cleanup_on_uninstall' => false]; }
    public static function allowed_switch(string $key): bool { return in_array($key, self::SWITCHES, true); }
}
