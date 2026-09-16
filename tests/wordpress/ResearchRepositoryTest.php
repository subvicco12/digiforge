<?php

declare(strict_types=1);

final class ResearchRepositoryTest extends WP_UnitTestCase
{
    public function testResearchFlowIsDeterministicDeduplicatedAndHumanGated(): void
    {
        DigiForge\Core\Activator::activate();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $repository = new DigiForge\Research\Repository();

        $source = $repository->createSource([
            'name' => 'Manual Etsy research',
            'source_type' => 'manual',
            'environment' => 'sandbox',
            'config' => ['note' => 'fixture'],
        ], 'research-source-1');
        self::assertIsArray($source);
        self::assertSame(0, (int) $source['enabled']);
        self::assertSame('sandbox', $source['environment']);

        $observation = $repository->ingest([
            'source_id' => (int) $source['id'],
            'external_id' => 'fixture-1',
            'title' => 'Personalized family mug opportunity',
            'body' => 'Manual evidence only; no network collection.',
            'provenance' => ['origin' => 'manual_test'],
        ], 'research-observation-1');
        self::assertIsArray($observation);

        $duplicateObservation = $repository->ingest([
            'source_id' => (int) $source['id'],
            'external_id' => 'fixture-1',
            'title' => 'Personalized family mug opportunity',
            'body' => 'Manual evidence only; no network collection.',
        ]);
        self::assertIsArray($duplicateObservation);
        self::assertTrue((bool) ($duplicateObservation['deduplicated'] ?? false));

        $evidence = $repository->addEvidence((int) $observation['id'], [
            'evidence_type' => 'manual_note',
            'value' => 'Observed demand signal entered manually.',
            'provenance' => ['reviewer' => 'fixture'],
        ], 'research-evidence-1');
        self::assertIsArray($evidence);

        $candidate = $repository->createCandidate([
            'title' => 'Personalized Family Mug',
            'summary' => 'Candidate produced from stored manual evidence.',
            'signals' => [
                'demand' => 80,
                'competition_gap' => 60,
                'margin' => 70,
                'trend' => 50,
                'evidence_quality' => 90,
            ],
        ], 'research-candidate-1');
        self::assertIsArray($candidate);
        self::assertSame('v1', $candidate['score_version']);
        self::assertSame(DigiForge\Research\Repository::REVIEW_PENDING, $candidate['review_status']);
        self::assertEqualsWithDelta(71.0, (float) $candidate['score'], 0.001);

        $duplicateCandidate = $repository->createCandidate(['title' => '  PERSONALIZED   FAMILY MUG  ']);
        self::assertIsArray($duplicateCandidate);
        self::assertTrue((bool) ($duplicateCandidate['deduplicated'] ?? false));

        self::assertTrue($repository->linkEvidence((int) $candidate['id'], (int) $evidence['id']));

        $blockedPromotion = $repository->promote((int) $candidate['id']);
        self::assertWPError($blockedPromotion);
        self::assertSame('digiforge_approval_required', $blockedPromotion->get_error_code());

        $approved = $repository->review((int) $candidate['id'], 'APPROVED', 'Reviewed by fixture.');
        self::assertIsArray($approved);
        self::assertSame(DigiForge\Research\Repository::REVIEW_APPROVED, $approved['review_status']);

        $opportunity = $repository->promote((int) $candidate['id'], 'research-promotion-1');
        self::assertIsArray($opportunity);
        self::assertGreaterThan(0, (int) $opportunity['id']);

        $replay = $repository->promote((int) $candidate['id'], 'research-promotion-1');
        self::assertIsArray($replay);
        self::assertTrue((bool) ($replay['idempotent_replay'] ?? false));
        self::assertSame((int) $opportunity['id'], (int) $replay['id']);
    }
}
