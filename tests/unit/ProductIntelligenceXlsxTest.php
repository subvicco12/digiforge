<?php
declare(strict_types=1);
namespace DigiForge\Tests;

use DigiForge\POD\ProductIntelligenceXlsx;
use PHPUnit\Framework\TestCase;

if (is_file(dirname(__DIR__, 2).'/includes/POD/ProductIntelligenceXlsx.php')) {
    require_once dirname(__DIR__, 2).'/includes/POD/ProductIntelligenceXlsx.php';
}

final class ProductIntelligenceXlsxTest extends TestCase
{
    private function export(array $rows): string
    {
        self::assertTrue(class_exists(ProductIntelligenceXlsx::class), 'Server-side XLSX exporter must exist.');
        return ProductIntelligenceXlsx::export($rows);
    }

    private function entries(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'df-xlsx-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, $bytes);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($file, \ZipArchive::CHECKCONS));
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                $value = $zip->getFromIndex($i);
                self::assertNotFalse($value);
                $xml = new \DOMDocument();
                self::assertTrue($xml->loadXML($value, LIBXML_NONET), $name);
                $entries[$name] = $value;
            }
            $zip->close();
            return $entries;
        } finally {
            unlink($file);
        }
    }

    public function testProducesAValidSelfContainedWorkbookWithProvenanceAndBlankDistinctFromZero(): void
    {
        $entries = $this->entries($this->export([
            ['_snapshot_id' => '42', 'Price' => '0', 'Gap' => null],
            ['_snapshot_id' => '42', 'Price' => '001.20', 'Extra' => '雪 🐈'],
        ]));
        self::assertCount(5, $entries);
        self::assertArrayHasKey('[Content_Types].xml', $entries);
        self::assertStringContainsString('Target="worksheets/sheet1.xml"', $entries['xl/_rels/workbook.xml.rels']);
        $sheet = $entries['xl/worksheets/sheet1.xml'];
        self::assertStringContainsString('<t xml:space="preserve">_snapshot_id</t>', $sheet);
        self::assertStringContainsString('<c r="B2" t="inlineStr"><is><t xml:space="preserve">0</t>', $sheet);
        self::assertStringContainsString('001.20', $sheet);
        self::assertStringContainsString('雪 🐈', $sheet);
        self::assertStringNotContainsString('r="C2"', $sheet);
        self::assertStringContainsString('r="D3"', $sheet);
    }

    public function testFormulaMarkupAndUrlsStayLiteralTextWithoutExternalRelationships(): void
    {
        $entries = $this->entries($this->export([
            ['=Header' => '=HYPERLINK("https://example.invalid","run")', 'Other' => "\t+SUM(1,2)", 'XML' => '<f>1+1</f>&"'],
            ['=Header' => '@SUM(1)', 'Other' => '-1', 'XML' => 'https://example.invalid/image'],
        ]));
        $sheet = $entries['xl/worksheets/sheet1.xml'];
        self::assertStringContainsString('=HYPERLINK(&quot;', $sheet);
        self::assertStringContainsString('&lt;f&gt;1+1&lt;/f&gt;', $sheet);
        foreach ($entries as $entry) {
            self::assertStringNotContainsString('<f>', $entry);
            self::assertStringNotContainsString('TargetMode="External"', $entry);
            self::assertStringNotContainsString('<hyperlink', $entry);
        }
    }

    public function testXmlControlsAreRemovedAndExcelEscapeSequencesRemainLiteral(): void
    {
        $sheet = $this->entries($this->export([['Text' => "a\0\x01b\r\nc\t_x0041_"]]))['xl/worksheets/sheet1.xml'];
        self::assertStringContainsString("ab&#13;\nc\t_x005F_x0041_", $sheet);
    }

    /** @dataProvider invalidRows */
    public function testRejectsMalformedOrUnboundedCells(array $rows): void
    {
        self::assertTrue(class_exists(ProductIntelligenceXlsx::class));
        $this->expectException(\InvalidArgumentException::class);
        ProductIntelligenceXlsx::export($rows);
    }

    public static function invalidRows(): array
    {
        return [
            'integer cell' => [[['Text' => 0]]],
            'nested cell' => [[['Text' => ['bad']]]],
            'invalid utf8' => [[['Text' => "\xff"]]],
            'oversized string' => [[['Text' => str_repeat('a', 32768)]]],
            'oversized unicode' => [[['Text' => str_repeat('🐈', 16384)]]],
            'too many rows' => [array_fill(0, 50001, ['a' => null])],
            'too many columns' => [[array_fill_keys(array_map(static fn($i) => 'column'.$i, range(1, 97)), null)]],
            'numeric header' => [[[0 => 'bad']]],
            'non row' => [['bad']],
            'credential column' => [[['Access Token' => 'synthetic-test-only']]],
        ];
    }

    public function testRejectsOversizedArchiveRatherThanReturningATruncatedZip(): void
    {
        self::assertTrue(class_exists(ProductIntelligenceXlsx::class));
        $this->expectException(\InvalidArgumentException::class);
        ProductIntelligenceXlsx::export(array_fill(0, 600, ['Text' => str_repeat('&', 10000)]));
    }

    public function testEmptyEvidenceStillProducesAValidWorkbook(): void
    {
        $entries = $this->entries($this->export([]));
        self::assertStringContainsString('<sheetData></sheetData>', $entries['xl/worksheets/sheet1.xml']);
    }

    public function testMaximumColumnCoordinatesAndUnionAcrossRowsAreEnforced(): void
    {
        $columns = array_fill_keys(array_map(static fn($i) => 'column'.$i, range(1, 96)), 'evidence');
        $sheet = $this->entries($this->export([$columns]))['xl/worksheets/sheet1.xml'];
        self::assertStringContainsString('r="Z2"', $sheet);
        self::assertStringContainsString('r="AA2"', $sheet);
        self::assertStringContainsString('r="CR2"', $sheet);
        $this->expectException(\InvalidArgumentException::class);
        ProductIntelligenceXlsx::export([$columns, ['column97' => null]]);
    }
}
