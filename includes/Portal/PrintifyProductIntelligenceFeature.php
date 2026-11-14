<?php
declare(strict_types=1);
namespace DigiForge\Portal;

use DigiForge\POD\PrintifyResearchDependencies;
use WP_Error;

/** Explicit feature-only entry point. No installer, bootstrap hook, migration or catalog issuer. */
final class PrintifyProductIntelligenceFeature
{
    private static bool $registered=false;
    public function register(): true|WP_Error
    {
        if(self::$registered)return true;
        // This checks known source interfaces only. It is never installed-site acceptance.
        $readiness=PrintifyResearchDependencies::inspect();
        if(empty($readiness['portal_controls_ready']))return new WP_Error('pod_feature_compatibility_required','Existing shared interfaces and shop admission must be reconciled before feature registration.',['status'=>409,'external_execution_authorized'=>false]);
        (new ProductIntelligencePanel())->registerFeatureOnly();
        add_action('wp_enqueue_scripts',[$this,'enqueue']);
        self::$registered=true;
        return true;
    }
    public function render(string $baseUrl): void
    {
        if(!self::$registered)return;
        (new ProductIntelligencePanel())->render($baseUrl);
    }
    public function enqueue(): void
    {
        if(!self::$registered||($_GET['df_shop']??null)!=='personalized_pod'||!ProductIntelligencePanel::canRead()||!defined('DIGIFORGE_FILE'))return;
        wp_enqueue_style('digiforge-printify-intelligence',plugins_url('assets/printify-product-intelligence.css',DIGIFORGE_FILE),[],'1.0.0');
    }
}
