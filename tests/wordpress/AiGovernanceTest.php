<?php

declare(strict_types=1);

final class AiGovernanceTest extends WP_UnitTestCase
{
    private DigiForge\AI\Repository $repo;

    public function setUp(): void
    {
        parent::setUp();
        DigiForge\Core\Activator::activate();
        $this->repo = new DigiForge\AI\Repository();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function testGovernedRunIntentReviewOutputAndUsageRemainLocal(): void
    {
        $task = $this->repo->createTask([
            'task_key' => 'listing_copy', 'name' => 'Listing Copy', 'environment' => 'sandbox',
            'required_capabilities' => ['text'], 'min_quality_tier' => 2, 'max_latency_ms' => 5000, 'max_cost_per_1k' => 1.0,
        ], 'task-1');
        self::assertFalse(is_wp_error($task));

        $model = $this->repo->createModel([
            'model_key' => 'quality-text', 'provider' => 'ai', 'environment' => 'sandbox', 'model_class' => 'text',
            'capabilities' => ['text'], 'quality_tier' => 3, 'latency_ms' => 1000, 'cost_per_1k' => 0.2,
            'fallback_order' => 1, 'enabled' => true,
        ], 'model-1');
        self::assertFalse(is_wp_error($model));

        $prompt = $this->repo->createPrompt(['prompt_key' => 'listing_copy', 'name' => 'Listing Copy'], 'prompt-1');
        self::assertFalse(is_wp_error($prompt));
        $version = $this->repo->createPromptVersion((int)$prompt['id'], [
            'version_label' => '1.0.0', 'system_text' => 'Create structured listing copy.',
            'input_schema_id' => 'listing-input', 'input_schema_version' => '1',
            'output_schema_id' => 'listing-output', 'output_schema_version' => '1',
            'input_schema' => ['type'=>'object','required'=>['title'],'properties'=>['title'=>['type'=>'string','minLength'=>1,'maxLength'=>100]],'additionalProperties'=>false],
            'output_schema' => ['type'=>'object','required'=>['headline'],'properties'=>['headline'=>['type'=>'string','minLength'=>1,'maxLength'=>140]],'additionalProperties'=>false],
        ], 'prompt-version-1');
        self::assertFalse(is_wp_error($version));

        $bad = $this->repo->createRun(['task_id'=>$task['id'],'prompt_version_id'=>$version['id'],'input_payload'=>['title'=>'X','api_key'=>'not-allowed']], 'run-bad');
        self::assertTrue(is_wp_error($bad));

        $run = $this->repo->createRun(['task_id'=>$task['id'],'prompt_version_id'=>$version['id'],'input_payload'=>['title'=>'Teacher Mug'],'provenance'=>['source'=>'fixture']], 'run-1');
        self::assertFalse(is_wp_error($run));
        self::assertSame('DRAFT', $run['state']);
        self::assertSame((int)$model['id'], $run['model_id']);

        $executed = $this->repo->transitionRun((int)$run['id'], 'EXECUTED');
        self::assertTrue(is_wp_error($executed));
        self::assertSame(409, $executed->get_error_data()['status']);

        $validated = $this->repo->transitionRun((int)$run['id'], 'VALIDATED');
        self::assertFalse(is_wp_error($validated));
        $reviewRequired = $this->repo->transitionRun((int)$run['id'], 'REVIEW_REQUIRED');
        self::assertFalse(is_wp_error($reviewRequired));
        $premature = $this->repo->transitionRun((int)$run['id'], 'APPROVED_FOR_EXECUTION');
        self::assertTrue(is_wp_error($premature));

        $review = $this->repo->review((int)$run['id'], ['decision'=>'APPROVED','target_type'=>'run','notes'=>'Human reviewed'], 'review-1');
        self::assertFalse(is_wp_error($review));
        $approved = $this->repo->transitionRun((int)$run['id'], 'APPROVED_FOR_EXECUTION');
        self::assertFalse(is_wp_error($approved));

        $output = $this->repo->storeOutput((int)$run['id'], ['payload'=>['headline'=>'Teacher gift mug']], 'output-1');
        self::assertFalse(is_wp_error($output));
        self::assertSame($run['input_fingerprint'], $output['source_input_fingerprint']);
        self::assertSame((int)$version['id'], $output['prompt_version_id']);

        $usage = $this->repo->recordUsage((int)$run['id'], ['metering_unit'=>'tokens','request_units'=>1,'input_units'=>20,'output_units'=>10,'estimated_cost'=>0.01,'currency'=>'USD','estimate_source'=>'fixture','estimate_version'=>'v1'], 'usage-1');
        self::assertFalse(is_wp_error($usage));
        self::assertSame('sandbox', $usage['environment']);

        $replay = $this->repo->recordUsage((int)$run['id'], ['metering_unit'=>'tokens'], 'usage-1');
        self::assertFalse(is_wp_error($replay));
        self::assertTrue($replay['idempotent_replay']);
    }

    public function testPromptVersionsAreImmutableAndChecksummed(): void
    {
        $prompt = $this->repo->createPrompt(['prompt_key'=>'immutable','name'=>'Immutable Prompt'], 'immutable-prompt');
        self::assertFalse(is_wp_error($prompt));
        $first = $this->repo->createPromptVersion((int)$prompt['id'], [
            'version_label'=>'1.0.0','system_text'=>'Original','input_schema'=>['type'=>'object'],'output_schema'=>['type'=>'object'],
        ], 'immutable-v1');
        self::assertFalse(is_wp_error($first));
        self::assertSame(64, strlen((string)$first['checksum_sha256']));

        $duplicate = $this->repo->createPromptVersion((int)$prompt['id'], [
            'version_label'=>'1.0.0','system_text'=>'Changed','input_schema'=>['type'=>'object'],'output_schema'=>['type'=>'object'],
        ], 'immutable-v1-other-key');
        self::assertTrue(is_wp_error($duplicate));
        self::assertSame('digiforge_ai_immutable_version', $duplicate->get_error_code());
        self::assertSame(409, $duplicate->get_error_data()['status']);

        $stored = $this->repo->find(DigiForge\Database\Tables::ai_prompt_versions(), (int)$first['id']);
        self::assertSame('Original', $stored['system_text']);
        self::assertSame($first['checksum_sha256'], $stored['checksum_sha256']);
    }

    public function testStructuredAiDataRejectsCredentialKeysRecursively(): void
    {
        $model = $this->repo->createModel([
            'model_key'=>'credential-test','provider'=>'ai','environment'=>'sandbox','enabled'=>true,
            'capabilities'=>['text', ['metadata'=>['client_secret'=>'must-not-store']]],
        ], 'credential-model');
        self::assertTrue(is_wp_error($model));
        self::assertSame('digiforge_ai_credential_key', $model->get_error_code());
        self::assertSame(400, $model->get_error_data()['status']);
    }

    public function testRoutingPolicyIsDeterministicAndEnvironmentIsolated(): void
    {
        $policy = new DigiForge\AI\RoutingPolicy();
        $task=['environment'=>'sandbox','required_capabilities'=>['text'],'min_quality_tier'=>1,'max_latency_ms'=>5000,'max_cost_per_1k'=>1.0];
        $result=$policy->choose($task,[
            ['id'=>2,'model_key'=>'b','provider'=>'ai','environment'=>'production','capabilities'=>['text'],'quality_tier'=>5,'latency_ms'=>1,'cost_per_1k'=>0.01,'fallback_order'=>1,'enabled'=>true,'prohibited'=>false],
            ['id'=>3,'model_key'=>'c','provider'=>'ai','environment'=>'sandbox','capabilities'=>['text'],'quality_tier'=>3,'latency_ms'=>100,'cost_per_1k'=>0.1,'fallback_order'=>2,'enabled'=>true,'prohibited'=>false],
            ['id'=>1,'model_key'=>'a','provider'=>'ai','environment'=>'sandbox','capabilities'=>['text'],'quality_tier'=>2,'latency_ms'=>200,'cost_per_1k'=>0.2,'fallback_order'=>1,'enabled'=>true,'prohibited'=>false],
        ]);
        self::assertFalse(is_wp_error($result));
        self::assertSame(1,$result['model_id']);
        self::assertSame('sandbox',$result['environment']);
    }

    public function testSchemaValidatorRejectsUnknownAndCredentialFields(): void
    {
        $validator=new DigiForge\AI\SchemaValidator();
        $schema=['type'=>'object','required'=>['name'],'properties'=>['name'=>['type'=>'string','minLength'=>2],'count'=>['type'=>'integer','minimum'=>1,'maximum'=>5]],'additionalProperties'=>false];
        self::assertTrue($validator->validate(['name'=>'OK','count'=>2],$schema)['valid']);
        $invalid=$validator->validate(['name'=>'X','count'=>9,'token'=>'secret'],$schema);
        self::assertFalse($invalid['valid']);
        self::assertContains('credential_key',array_column($invalid['errors'],'code'));
        self::assertContains('additionalProperty',array_column($invalid['errors'],'code'));
    }
}
