<?php

declare(strict_types=1);

final class AiRestTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        DigiForge\Core\Activator::activate();
    }

    public function testAiManagementRequiresCapability(): void
    {
        wp_set_current_user(0);
        $controller = new DigiForge\REST\AiController();
        self::assertFalse($controller->canManage());

        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);
        self::assertTrue($controller->canManage());
    }

    public function testAiMutationRequiresIdempotencyKeyAndRejectsReplay(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);
        $controller = new DigiForge\REST\AiController();

        $missing = $controller->createTask($this->jsonRequest('POST', ['task_key'=>'copy','name'=>'Copy']));
        self::assertWPError($missing);
        self::assertSame('missing_idempotency_key', $missing->get_error_code());
        self::assertSame(400, $missing->get_error_data()['status']);

        $first = $controller->createTask($this->jsonRequest('POST', ['task_key'=>'copy','name'=>'Copy'], 'ai-task-rest-1'));
        self::assertInstanceOf(WP_REST_Response::class, $first);
        self::assertSame(201, $first->get_status());

        $replay = $controller->createTask($this->jsonRequest('POST', ['task_key'=>'copy','name'=>'Copy'], 'ai-task-rest-1'));
        self::assertWPError($replay);
        self::assertSame('idempotency_conflict', $replay->get_error_code());
        self::assertSame(409, $replay->get_error_data()['status']);
    }

    public function testFailedAiMutationReleasesReservation(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);
        $controller = new DigiForge\REST\AiController();

        $failed = $controller->createTask($this->jsonRequest('POST', ['task_key'=>'','name'=>''], 'ai-task-rest-retry'));
        self::assertWPError($failed);

        $retry = $controller->createTask($this->jsonRequest('POST', ['task_key'=>'recovered','name'=>'Recovered'], 'ai-task-rest-retry'));
        self::assertInstanceOf(WP_REST_Response::class, $retry);
        self::assertSame(201, $retry->get_status());
    }

    private function jsonRequest(string $method,array $body,string $idempotencyKey=''): WP_REST_Request
    {
        $request=new WP_REST_Request($method,'/digiforge/v1/ai/tasks');
        $request->set_header('Content-Type','application/json');
        if($idempotencyKey!=='')$request->set_header('Idempotency-Key',$idempotencyKey);
        $request->set_body((string)wp_json_encode($body));
        return $request;
    }
}
