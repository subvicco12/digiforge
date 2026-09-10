<?php
declare(strict_types=1);
namespace DigiForge\Core;
/** DigiForge capabilities are granted only to administrators on activation. */
final class Capabilities {
    public const ALL = ['manage_digiforge','manage_digiforge_products','manage_digiforge_digital','manage_digiforge_research','manage_digiforge_automation','manage_digiforge_connections','manage_digiforge_settings','publish_digiforge','view_digiforge_analytics'];
    public static function add(): void { if ($role = get_role('administrator')) { foreach (self::ALL as $cap) { $role->add_cap($cap); } } }
    public static function remove(): void { if ($role = get_role('administrator')) { foreach (self::ALL as $cap) { $role->remove_cap($cap); } } }
    public static function can(string $cap = 'manage_digiforge'): bool { return current_user_can($cap); }
}
