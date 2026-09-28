<?php
declare(strict_types=1);

final class IntegrityEvidenceNavigationTest extends WP_UnitTestCase
{
    public function testOnlyExactStoredHashShapeProducesAnInternalReadOnlyLink(): void
    {
        $portal=new DigiForge\Portal\Portal();
        $method=new ReflectionMethod($portal,'integrityEvidenceUrl');
        foreach (['', 'not-a-hash', str_repeat('g',64), str_repeat('a',65)] as $invalid) {
            self::assertSame('',$method->invoke($portal,$invalid));
        }
        $hash=hash('sha256','integrity-navigation-fixture');
        $url=$method->invoke($portal,$hash);
        self::assertStringContainsString('df_view=attention',$url);
        self::assertStringContainsString('df_integrity_auth='.$hash,$url);
        self::assertStringEndsWith('#df-integrity-evidence',$url);
        self::assertSame(wp_parse_url(home_url('/'),PHP_URL_HOST),wp_parse_url($url,PHP_URL_HOST));
        $source=file_get_contents(dirname(__DIR__,2).'/includes/Portal/Portal.php');
        self::assertStringContainsString('Integrity evidence</a>',$source);
        self::assertStringContainsString('Lookup does not acknowledge, retry or execute.',$source);
        self::assertStringContainsString('<td>NO</td><td>NO</td>',$source);
    }
}
