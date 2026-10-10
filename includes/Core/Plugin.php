<?php
declare(strict_types=1);
namespace DigiForge\Core;

use DigiForge\Database\BusinessScopeInstaller;
use DigiForge\Database\FinanceSchema;
use DigiForge\Database\EtsyOperationSchema;
use DigiForge\Database\OrderSchema;
use DigiForge\Database\ListingSchema;
use DigiForge\Database\Migrator;
use DigiForge\Database\PodSchema;
use DigiForge\Database\ProductionSchema;
use DigiForge\Database\V6OperationalSchema;
use DigiForge\Database\ScopedPolicySchema;
use DigiForge\Database\CatalogAcceptanceSchema;
use DigiForge\REST\Controller;
use DigiForge\REST\ProductFactoryController;
use DigiForge\REST\DigitalFactoryController;
use DigiForge\REST\IntegrationsController;
use DigiForge\REST\EtsyControlledExecutionController;
use DigiForge\REST\EtsyPublishExecutionController;
use DigiForge\REST\EtsyWebhookController;
use DigiForge\REST\EtsyOperationsController;
use DigiForge\REST\ResearchController;
use DigiForge\REST\AiController;
use DigiForge\REST\ProductionController;
use DigiForge\REST\PodController;
use DigiForge\REST\ListingController;
use DigiForge\REST\OrderController;
use DigiForge\REST\FinanceController;
use DigiForge\REST\LaunchController;
use DigiForge\Queue\Scheduler;
use DigiForge\Security\Logger;

/** Coordinates the WordPress-facing plugin services. */
final class Plugin {
    private static ?self $instance = null;
    private bool $booted = false;
    public static function instance(): self { return self::$instance ??= new self(); }
    private function __construct() {}
    public function boot(): void {
        if ($this->booted) { return; }
        $this->booted = true;
        if (! ListingSchema::migrateIfNeeded()) { return; }
        if (! EtsyOperationSchema::migrateIfNeeded()) { return; }
        if (! OrderSchema::migrateIfNeeded()) { return; }
        if (! FinanceSchema::migrateIfNeeded()) { return; }
        if (! PodSchema::migrateIfNeeded()) { return; }
        if (! ProductionSchema::migrateIfNeeded()) { return; }
        if (! (new Migrator())->maybe_migrate()) { return; }
        if (! BusinessScopeInstaller::migrateIfNeeded()) { return; }
        // Active-plugin upgrades do not necessarily run the activation hook. Keep the
        // additive operational chain on normal boot so code/schema cannot diverge.
        if (! V6OperationalSchema::migrateIfNeeded()) { return; }
        if (! ScopedPolicySchema::migrateIfNeeded()) { return; }
        if (! CatalogAcceptanceSchema::migrateIfNeeded()) { return; }
        \DigiForge\ProductFactory\AssetStorage::ensureProtectedRoot();
        \DigiForge\Operations\RecoveryProviderRegistry::register(new \DigiForge\Operations\HostingerRecoveryProvider());
        (new Controller())->register();
        (new ProductFactoryController())->register();
        (new DigitalFactoryController())->register();
        (new IntegrationsController())->register();
        (new EtsyControlledExecutionController())->register();
        (new EtsyPublishExecutionController())->register();
        (new EtsyWebhookController())->register();
        (new EtsyOperationsController())->register();
        (new ResearchController())->register();
        (new AiController())->register();
        (new \DigiForge\REST\AiEvidenceController())->register();
        (new \DigiForge\Portal\AiEvidenceWorkflow())->register();
        (new ProductionController())->register();
        (new PodController())->register();
        (new \DigiForge\POD\DeterministicRenderWorkflow())->register();
        (new ListingController())->register();
        (new OrderController())->register();
        (new FinanceController())->register();
        (new LaunchController())->register();
        (new Scheduler())->register();
        (new \DigiForge\ProductFactory\ApprovalAutomation())->register();
        (new \DigiForge\Portal\Portal())->register();
        (new \DigiForge\Portal\U3ApprovalInbox())->register();
        (new \DigiForge\Portal\U3AssetReview())->register();
        (new \DigiForge\Portal\FrontendControls())->register();
        add_filter('allowed_redirect_hosts', static function (array $hosts): array {
            if (! in_array('www.etsy.com', $hosts, true)) { $hosts[] = 'www.etsy.com'; }
            return $hosts;
        });
        if (is_admin()) {
            (new Admin())->register();
            (new \DigiForge\DigitalFactory\Admin())->register();
            (new \DigiForge\Integrations\Admin())->register();
            (new \DigiForge\Integrations\EtsyOAuth())->register();
            (new \DigiForge\Research\Admin())->register();
            (new \DigiForge\AI\Admin())->register();
            (new \DigiForge\Production\Admin())->register();
            (new \DigiForge\POD\Admin())->register();
            (new \DigiForge\Listings\Admin())->register();
            (new \DigiForge\Orders\Admin())->register();
            (new \DigiForge\Finance\Admin())->register();
        }
        add_action('digiforge_log', [Logger::class, 'write'], 10, 4);
    }
}
