<?php

declare(strict_types=1);

final class ResearchRestTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        DigiForge\Core\Activator::activate();
    }

    public function testResearchManagementRequiresCapability(): void
    {
        wp_set_current_user(0);
        $controller = new DigiForge\REST\ResearchController();
        self::assertFalse($controller->canManage());

        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);
        self::assertTrue($controller->canManage());
    }

    public function testResearchMutationRequiresIdempotencyKeyAndRejectsReplay(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);
        $controller = new DigiForge\REST\ResearchController();

        $missing = $this->jsonRequest('POST', ['name' => 'Manual Market Notes', 'source_type' => 'manual']);
        $missingResult = $controller->createSource($missing);
        self::assertWPError($missingResult);
        self::assertSame('missing_idempotency_key', $missingResult->get_error_code());
        self::assertSame(400, $missingResult->get_error_data()['status']);

        $request = $this->jsonRequest('POST', ['name' => 'Manual Market Notes', 'source_type' => 'manual'], 'source-rest-001');
        $first = $controller->createSource($request);
        self::assertInstanceOf(WP_REST_Response::class, $first);
        self::assertSame(201, $first->get_status());
        self::assertSame('sandbox', $first->get_data()['environment']);

        $replay = $controller->createSource($this->jsonRequest('POST', ['name' => 'Manual Market Notes', 'source_type' => 'manual'], 'source-rest-001'));
        self::assertWPError($replay);
        self::assertSame('idempotency_conflict', $replay->get_error_code());
        self::assertSame(409, $replay->get_error_data()['status']);
    }

    public function testFailedResearchMutationReleasesReservation(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);
        $controller = new DigiForge\REST\ResearchController();

        $failed = $controller->createSource($this->jsonRequest('POST', ['name' => ''], 'source-rest-retry'));
        self::assertWPError($failed);
        self::assertSame('digiforge_validation', $failed->get_error_code());

        $retry = $controller->createSource($this->jsonRequest('POST', ['name' => 'Recovered Source', 'source_type' => 'manual'], 'source-rest-retry'));
        self::assertInstanceOf(WP_REST_Response::class, $retry);
        self::assertSame(201, $retry->get_status());
    }

    private function jsonRequest(string $method, array $body, string $idempotencyKey = ''): WP_REST_Request
    {
        $request = new WP_REST_Request($method, '/digiforge/v1/research/sources');
        $request->set_header('Content-Type', 'application/json');
        if ($idempotencyKey !== '') {
            $request->set_header('Idempotency-Key', $idempotencyKey);
        }
        $request->set_body((string) wp_json_encode($body));
        return $request;
    }
}
