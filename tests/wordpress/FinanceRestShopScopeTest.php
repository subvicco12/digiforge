<?php
declare(strict_types=1);

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\REST\FinanceController;
use DigiForge\Security\ShopAccessPolicy;
use DigiForge\Security\ShopAccessRepository;

require_once __DIR__.'/fixtures/ShopAccessFixture.php';

/** Global Finance endpoints require independently signed assignments for every shop. */
final class FinanceRestShopScopeTest extends WP_UnitTestCase
{
    private int $externalCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        Settings::protectProduction();
        ShopAccessFixture::reset();
        (new FinanceController())->register();
        do_action('rest_api_init');
        add_filter('pre_http_request', [$this, 'blockExternal'], 1, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'blockExternal'], 1);
        self::assertSame(0, $this->externalCalls);
        self::assertTrue(Settings::safety_locked());
        self::assertTrue(Settings::get('stop_all', true));
        self::assertFalse(Settings::get('automation_armed', false));
        parent::tearDown();
    }

    public function blockExternal(mixed $preempt, array $args, string $url): WP_Error
    {
        $this->externalCalls++;
        return new WP_Error('unexpected_external_request', 'Finance scope tests permit no external requests.');
    }

    private function dispatch(string $method, string $path, array $query = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/digiforge/v1/finance/'.$path);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_query_params($query);
        if ($method === 'POST') {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body('{}');
        }
        return rest_do_request($request);
    }

    public function testUnauthenticatedActorIsDenied(): void
    {
        wp_set_current_user(0);
        self::assertFalse((new FinanceController())->canManage());
        self::assertSame(401, $this->dispatch('GET', 'ledger')->get_status());
    }

    public static function incompleteAssignments(): array
    {
        return [['unassigned', []], ['one shop', ['digital']], ['two shops', ['digital', 'personalized_pod']]];
    }

    /** @dataProvider incompleteAssignments */
    public function testPartialAssignmentsCannotReadGlobalCollectionOrScopeItWithQuery(string $label, array $shops): void
    {
        $subject = self::factory()->user->create(['role'=>'administrator']);
        wp_set_current_user($subject);
        if ($shops !== []) ShopAccessFixture::authorizeActor($subject, $shops, ['manage_digiforge_finance']);
        self::assertTrue(current_user_can('manage_digiforge_finance'));
        $queries = [];
        $capture = static function (string $sql) use (&$queries): string {
            if (str_contains($sql, Tables::finance_ledger())) $queries[] = $sql;
            return $sql;
        };
        add_filter('query', $capture);
        try {
            $plain = $this->dispatch('GET', 'ledger');
            $claimedScope = $this->dispatch('GET', 'ledger', ['shop_key'=>'digital', 'shop'=>'digital']);
        } finally {
            remove_filter('query', $capture);
        }
        self::assertSame(403, $plain->get_status(), $label);
        self::assertSame(403, $claimedScope->get_status(), $label);
        self::assertSame([], $queries, 'Denied requests must not read global Finance evidence.');
        self::assertFalse((new FinanceController())->canManage());
    }

    public function testEveryMutationRouteDeniesAnUnassignedAdministratorBeforeCallback(): void
    {
        wp_set_current_user(self::factory()->user->create(['role'=>'administrator']));
        foreach (['ledger', 'fx', 'tax', 'tax/1/review', 'periods/calculate', 'analytics', 'intents', 'period/1/state', 'alert/1/state', 'intent/1/state'] as $path) {
            $response = $this->dispatch('POST', $path, ['shop_key'=>'digital']);
            self::assertSame(403, $response->get_status(), $path);
            self::assertSame('rest_forbidden', $response->get_data()['code'], $path);
        }
    }

    public function testAllFourAssignmentsAllowGlobalReadAndRevocationImmediatelyDenies(): void
    {
        $subject = self::factory()->user->create(['role'=>'administrator']);
        wp_set_current_user($subject);
        $owner = ShopAccessFixture::authorizeActor($subject, ShopAccessPolicy::canonicalShops(), ['manage_digiforge_finance']);
        self::assertTrue((new FinanceController())->canManage());
        self::assertSame(200, $this->dispatch('GET', 'ledger')->get_status());
        // Admission reaches the existing callback validation, without a financial write.
        $mutation = $this->dispatch('POST', 'ledger');
        self::assertSame(400, $mutation->get_status());
        self::assertSame('missing_idempotency_key', $mutation->get_data()['code']);
        $repository = new ShopAccessRepository();
        $state = $repository->read('jewelry');
        self::assertNotWPError($state);
        $grants = array_filter($state['grants'], static fn(array $grant): bool => $grant['actor_id'] === $subject);
        self::assertCount(1, $grants);
        wp_set_current_user($owner);
        self::assertNotWPError($repository->revoke('jewelry', array_key_first($grants), str_replace('-', '_', wp_generate_uuid4()), $state['revision']));
        wp_set_current_user($subject);
        self::assertFalse((new FinanceController())->canManage());
        self::assertSame(403, $this->dispatch('GET', 'ledger', ['shop_key'=>'digital'])->get_status());
        self::assertSame(403, $this->dispatch('POST', 'ledger')->get_status());
    }

    public function testAssignmentsCannotReplaceMissingOrdinaryFinanceCapability(): void
    {
        $subject = self::factory()->user->create(['role'=>'administrator']);
        wp_set_current_user($subject);
        ShopAccessFixture::authorizeActor($subject, ShopAccessPolicy::canonicalShops(), ['manage_digiforge_finance']);
        wp_get_current_user()->add_cap('manage_digiforge_finance', false);
        self::assertFalse(current_user_can('manage_digiforge_finance'));
        self::assertFalse((new FinanceController())->canManage());
        self::assertSame(403, $this->dispatch('GET', 'ledger')->get_status());
    }
}
