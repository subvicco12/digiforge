<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;

/** Local workflow/readiness policy. Publishing and every external action are deliberately absent. */
final class Lifecycle {
    public const STATES = ['DRAFT', 'FILES_PENDING', 'QA_PENDING', 'QA_FAILED', 'QA_PASSED', 'CONTENT_REVIEW', 'VISUAL_REVIEW', 'COMMERCIAL_REVIEW', 'IP_REVIEW', 'POLICY_REVIEW', 'PROFITABILITY_REVIEW', 'READY_FOR_LISTING', 'HUMAN_APPROVED', 'PLATFORM_DRAFT', 'FINAL_VALIDATION', 'PUBLISH_READY', 'RETIRED'];
    private const NEXT = [
        'DRAFT' => ['FILES_PENDING'], 'FILES_PENDING' => ['QA_PENDING'], 'QA_PENDING' => ['QA_FAILED', 'QA_PASSED'],
        'QA_FAILED' => ['QA_PENDING'], 'QA_PASSED' => ['CONTENT_REVIEW'], 'CONTENT_REVIEW' => ['VISUAL_REVIEW'],
        'VISUAL_REVIEW' => ['COMMERCIAL_REVIEW'], 'COMMERCIAL_REVIEW' => ['IP_REVIEW'], 'IP_REVIEW' => ['POLICY_REVIEW'],
        'POLICY_REVIEW' => ['PROFITABILITY_REVIEW'], 'PROFITABILITY_REVIEW' => ['READY_FOR_LISTING'],
        'READY_FOR_LISTING' => ['HUMAN_APPROVED'], 'HUMAN_APPROVED' => ['PLATFORM_DRAFT'],
        'PLATFORM_DRAFT' => ['FINAL_VALIDATION'], 'FINAL_VALIDATION' => ['PUBLISH_READY'], 'PUBLISH_READY' => ['RETIRED'], 'RETIRED' => [],
    ];
    public static function can_transition(string $from, string $to): bool { return in_array($to, self::NEXT[$from] ?? [], true); }
    /** @return array<string, bool> */
    public static function readiness(): array { return array_fill_keys(['files', 'technical_qa', 'content_qa', 'visual_qa', 'commercial_qa', 'copyright_ip_qa', 'policy_qa', 'profitability_qa', 'listing', 'human_approval', 'platform_draft', 'final_validation'], false); }
    /**
     * @param array<string, bool> $readiness
     * @return array<string, bool>
     */
    public static function advance_readiness(array $readiness, string $state): array {
        $readiness = array_replace(self::readiness(), array_intersect_key($readiness, self::readiness()));
        $gate = match ($state) {
            'QA_PENDING' => 'files',
            'QA_PASSED' => 'technical_qa',
            'VISUAL_REVIEW' => 'content_qa',
            'COMMERCIAL_REVIEW' => 'visual_qa',
            'IP_REVIEW' => 'commercial_qa',
            'POLICY_REVIEW' => 'copyright_ip_qa',
            'PROFITABILITY_REVIEW' => 'policy_qa',
            'READY_FOR_LISTING' => 'profitability_qa',
            'HUMAN_APPROVED' => 'listing',
            'PLATFORM_DRAFT' => 'human_approval',
            'FINAL_VALIDATION' => 'platform_draft',
            'PUBLISH_READY' => 'final_validation',
            default => null,
        };
        if ($gate !== null) { $readiness[$gate] = true; }
        if ($state === 'QA_FAILED') { $readiness['technical_qa'] = false; }
        return $readiness;
    }
}
