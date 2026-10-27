<?php
declare(strict_types=1);
namespace DigiForge\Portal;

/** Links to existing selected-shop pages; link visibility never grants access. */
final class ShopWorkflowNavigation
{
    public static function views(string $shop, callable $authorize): array
    {
        if (!in_array($shop,['digital','personalized_pod','standard_pod','jewelry'],true)) return [];
        $views=[
            'businesses'=>['Businesses / Brands / Shops','manage_digiforge'],
            'research'=>['Research','manage_digiforge_research'],
            'products'=>['Product Factory','manage_digiforge_products'],
            'digital'=>['Digital Products','manage_digiforge_digital'],
            'pod_personalized'=>['Personalized POD','manage_digiforge_pod'],
            'approvals'=>['Approval Inbox','manage_digiforge_research'],
            'listings'=>['Listings & Etsy','manage_digiforge_listings'],
            'orders'=>['Orders / Personalization','manage_digiforge_orders'],
            'fulfillment'=>['Fulfillment / Providers','manage_digiforge_orders'],
            'production'=>['Production','manage_digiforge_production'],
            'automation'=>['Automation / Google preparation','manage_digiforge'],
            'ai_budget'=>['AI & Budget','manage_digiforge_ai'],
            'audit'=>['Audit / Reconciliation','manage_digiforge'],
        ];
        $allowed=[];
        foreach ($views as $view=>[$label,$cap]) {
            if (($view==='digital' && $shop!=='digital') || ($view==='pod_personalized' && $shop!=='personalized_pod')) continue;
            $permitted=$authorize($shop,$cap)===true;
            if (!$permitted && $view==='approvals') $permitted=$authorize($shop,'manage_digiforge_products')===true;
            if ($permitted) $allowed[$view]=$label;
        }
        return $allowed;
    }
}
