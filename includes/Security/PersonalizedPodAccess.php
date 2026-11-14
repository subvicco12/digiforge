<?php
declare(strict_types=1);
namespace DigiForge\Security;

/** Shared admission for the held MVP; existing signed grants remain authoritative. */
final class PersonalizedPodAccess
{
    public static function allows(bool $generation=false): bool
    {
        if (get_current_user_id()<1) return false;
        $caps=['manage_digiforge_research','manage_digiforge_pod'];
        if ($generation) $caps[]='manage_digiforge_ai';
        foreach ($caps as $cap) {
            if (!current_user_can($cap) || ShopAccess::authorize('personalized_pod',$cap)!==true) return false;
        }
        return true;
    }
}
