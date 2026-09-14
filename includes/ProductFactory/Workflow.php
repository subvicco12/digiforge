<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

/**
 * Product-level lifecycle projection for the unified DigiForge console.
 *
 * This does not replace the lower-level ProductFactory/Production lifecycles;
 * it translates their facts into the operator-facing stages defined for U3.
 */
final class Workflow
{
    public const RESEARCH_PENDING = 'RESEARCH_PENDING';
    public const RESEARCH_APPROVED = 'RESEARCH_APPROVED';
    public const DEVELOPMENT_QUEUED = 'DEVELOPMENT_QUEUED';
    public const SPECIFICATION_BUILDING = 'SPECIFICATION_BUILDING';
    public const SPECIFICATION_READY = 'SPECIFICATION_READY';
    public const ASSET_PRODUCTION = 'ASSET_PRODUCTION';
    public const MARKETING_ASSET_PRODUCTION = 'MARKETING_ASSET_PRODUCTION';
    public const QA_RUNNING = 'QA_RUNNING';
    public const QA_FAILED = 'QA_FAILED';
    public const QA_PASSED = 'QA_PASSED';
    public const PRODUCT_REVIEW_REQUIRED = 'PRODUCT_REVIEW_REQUIRED';
    public const PRODUCT_APPROVED = 'PRODUCT_APPROVED';
    public const LISTING_BUILDING = 'LISTING_BUILDING';
    public const LISTING_REVIEW_REQUIRED = 'LISTING_REVIEW_REQUIRED';
    public const ETSY_DRAFT_READY = 'ETSY_DRAFT_READY';
    public const PUBLISH_APPROVAL_REQUIRED = 'PUBLISH_APPROVAL_REQUIRED';
    public const LIVE = 'LIVE';

    /**
     * @param array<string,mixed> $facts
     */
    public static function project(array $facts): string
    {
        if (($facts['research_approved'] ?? false) !== true) {
            return self::RESEARCH_PENDING;
        }
        if (($facts['product_version_created'] ?? false) !== true) {
            return self::RESEARCH_APPROVED;
        }
        if (($facts['production_plan_created'] ?? false) !== true) {
            return self::SPECIFICATION_READY;
        }
        if (($facts['assets_started'] ?? false) !== true) {
            return self::ASSET_PRODUCTION;
        }
        if (($facts['marketing_assets_started'] ?? false) !== true) {
            return self::MARKETING_ASSET_PRODUCTION;
        }
        if (($facts['qa_complete'] ?? false) !== true) {
            return self::QA_RUNNING;
        }
        if (($facts['qa_passed'] ?? false) !== true) {
            return self::QA_FAILED;
        }
        if (($facts['product_approved'] ?? false) !== true) {
            return self::PRODUCT_REVIEW_REQUIRED;
        }
        if (($facts['listing_started'] ?? false) !== true) {
            return self::PRODUCT_APPROVED;
        }
        if (($facts['listing_approved'] ?? false) !== true) {
            return self::LISTING_REVIEW_REQUIRED;
        }
        if (($facts['etsy_draft_ready'] ?? false) !== true) {
            return self::ETSY_DRAFT_READY;
        }
        if (($facts['publish_approved'] ?? false) !== true) {
            return self::PUBLISH_APPROVAL_REQUIRED;
        }
        return ($facts['live'] ?? false) === true ? self::LIVE : self::PUBLISH_APPROVAL_REQUIRED;
    }
}
