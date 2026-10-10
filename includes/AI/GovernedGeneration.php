<?php
declare(strict_types=1);

namespace DigiForge\AI;

use DigiForge\Launch\OpenAIClient;
use WP_Error;

/**
 * Business-layer guard for billable AI generation.
 * Polling/retrieval deliberately remains outside this boundary because it does
 * not start a new provider generation.
 */
final class GovernedGeneration
{
    public function __construct(
        private ?ShopAiGovernanceRepository $governance = null,
        private ?OpenAIClient $client = null
    ) {
        $this->governance ??= new ShopAiGovernanceRepository();
        $this->client ??= new OpenAIClient();
    }

    /** @return array<string,mixed>|WP_Error */
    public function develop(string $shop, string $stage, string $prompt, string $attemptKey, ?array $runContext = null): array|WP_Error
    {
        return $this->execute($shop, $stage, $attemptKey, $runContext, fn(): array|WP_Error => $this->client->develop($prompt));
    }

    /** @return array<string,mixed>|WP_Error */
    public function research(string $shop, string $prompt, string $attemptKey, ?array $runContext = null): array|WP_Error
    {
        return $this->execute($shop, 'research', $attemptKey, $runContext, fn(): array|WP_Error => $this->client->research($prompt));
    }

    /** @return array<string,mixed>|WP_Error */
    public function startBackgroundDevelop(string $shop, string $stage, string $prompt, string $attemptKey, ?array $runContext = null, int $maxOutputTokens = 8000): array|WP_Error
    {
        return $this->execute($shop, $stage, $attemptKey, $runContext, fn(): array|WP_Error => $this->client->startBackgroundDevelop($prompt, $maxOutputTokens));
    }

    /** @param callable():array|WP_Error $provider @return array<string,mixed>|WP_Error */
    private function execute(string $shop, string $stage, string $attemptKey, ?array $runContext, callable $provider): array|WP_Error
    {
        $shop = sanitize_key($shop);
        $stage = sanitize_key($stage);
        $attemptKey = sanitize_text_field($attemptKey);
        if ($shop === '' || $attemptKey === '' || ! in_array($stage, ShopAiPlan::STAGES, true)) {
            return new WP_Error('ai_generation_context_invalid', 'A valid shop, stage and attempt identity are required before AI generation.');
        }

        $projection = $this->governance->evaluate($shop, 'production', $runContext);
        if ($projection instanceof WP_Error) return $projection;
        $unitCost = max(0.0, (float)($projection['stages'][$stage]['estimated_unit_cost'] ?? 0));
        $budgets = (array)($projection['budgets'] ?? []);
        if (max(0.0, (float)($budgets['run'] ?? 0)) > 0
            || max(0.0, (float)($budgets['day'] ?? 0)) > 0
            || max(0.0, (float)($budgets['month'] ?? 0)) > 0) {
            return new WP_Error(
                'ai_generation_cost_accounting_unavailable',
                'AI generation is blocked because a monetary budget is active but authoritative actual-cost attribution is unavailable.'
            );
        }
        $preflight = $this->governance->preflight($shop, $stage, 1, $unitCost, 'production', $runContext);
        if ($preflight instanceof WP_Error) return $preflight;
        if (empty($preflight['execution_allowed'])) {
            return new WP_Error('ai_generation_budget_blocked', 'Shop AI policy does not authorize this generation attempt.', ['reasons'=>$preflight['reasons'] ?? []]);
        }

        // The final check and durable reservation share a database lock. No
        // provider execution occurs while holding it; an uncertain reservation
        // or release blocks the call and leaves consumed quantities conservative.
        $usage = $this->governance->reserveGeneration($shop, $stage, $attemptKey, $runContext);
        if ($usage instanceof WP_Error) return $usage;
        if (! empty($usage['idempotent_replay'])) {
            return new WP_Error('ai_generation_attempt_replayed', 'This AI generation attempt was already reserved; provider execution will not be repeated.');
        }

        return $provider();
    }
}
