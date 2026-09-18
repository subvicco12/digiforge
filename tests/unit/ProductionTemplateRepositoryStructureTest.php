<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductionTemplateRepositoryStructureTest extends TestCase
{
    public function testRepositoryUsesVersionedCentralizedPersistenceBoundary(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateRepository.php');
        self::assertIsString($source);
        self::assertStringContainsString('Tables::pod_production_templates()',$source);
        self::assertStringContainsString('template_id=%s AND template_version=%d',$source);
        self::assertStringContainsString("hash_equals((string)(\$existing['fingerprint']??''),(string)\$row['fingerprint'])",$source);
        self::assertStringContainsString("'idempotent'=>true",$source);
    }

    public function testChangedSameVersionFailsClosed(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateRepository.php');
        self::assertStringContainsString('ProductionTemplateContract::assertMutable($existing)',$source);
        self::assertStringContainsString('digiforge_template_immutable',$source);
        self::assertStringContainsString('digiforge_template_version_conflict',$source);
        self::assertStringContainsString('requires a new template_version',$source);
    }

    public function testRepositoryContainsNoProviderOrPublishingExecution(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/includes/POD/ProductionTemplateRepository.php');
        self::assertStringNotContainsString('wp_remote_',$source);
        self::assertStringNotContainsString('createOrder',$source);
        self::assertStringNotContainsString('publish',$source);
    }
}
