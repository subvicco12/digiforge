<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Bounded, dependency-free OOXML evidence download. Every supplied value is text. */
final class ProductIntelligenceXlsx
{
    public const MAX_BYTES = 16777216;
    private const XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @param array<array<string, string|null>> $rows */
    public static function export(array $rows): string
    {
        if (!array_is_list($rows) || count($rows) > 50000) {
            throw new \InvalidArgumentException('XLSX requires at most 50000 evidence rows.');
        }
        $columns = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) > 96) {
                throw new \InvalidArgumentException('XLSX requires named rows with at most 96 columns.');
            }
            foreach ($row as $key => $value) {
                if (!is_string($key) || $key === '' || strlen($key) > 120) {
                    throw new \InvalidArgumentException('XLSX requires bounded named columns.');
                }
                $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
                if (in_array($normalized, ['password', 'authorization', 'apikey', 'accesstoken', 'refreshtoken', 'clientsecret', 'privatekey'], true)) {
                    throw new \InvalidArgumentException('Credential columns cannot be exported.');
                }
                if ($value !== null && !is_string($value)) {
                    throw new \InvalidArgumentException('XLSX evidence cells must be strings or null.');
                }
                $columns[$key] = true;
                if (count($columns) > 96) {
                    throw new \InvalidArgumentException('XLSX column limit exceeded.');
                }
            }
        }
        $columns = array_keys($columns);
        $sheet = self::XML . '<worksheet xmlns="'.self::NS.'"><sheetData>';
        if ($columns !== []) {
            $sheet .= self::row($columns, 1);
        }
        foreach ($rows as $index => $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $sheet .= self::row($values, $index + 2);
            // Leave room for the fixed package files and ZIP directory below.
            if (strlen($sheet) > self::MAX_BYTES - 8192) {
                throw new \InvalidArgumentException('XLSX exceeds the 16 MiB export limit.');
            }
        }
        $sheet .= '</sheetData></worksheet>';
        $rels = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $office = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        return self::zip([
            '[Content_Types].xml' => self::XML.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => self::XML.'<Relationships xmlns="'.$rels.'"><Relationship Id="rId1" Type="'.$office.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => self::XML.'<workbook xmlns="'.self::NS.'" xmlns:r="'.$office.'"><sheets><sheet name="Evidence" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => self::XML.'<Relationships xmlns="'.$rels.'"><Relationship Id="rId1" Type="'.$office.'/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    private static function row(array $values, int $number): string
    {
        $xml = '<row r="'.$number.'">';
        foreach ($values as $index => $value) {
            if ($value === null) {
                continue;
            }
            $column = '';
            for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
                $column = chr(65 + (($n - 1) % 26)) . $column;
            }
            $xml .= '<c r="'.$column.$number.'" t="inlineStr"><is><t xml:space="preserve">'.self::text($value).'</t></is></c>';
        }
        return $xml.'</row>';
    }

    private static function text(string $value): string
    {
        if (strlen($value) > 131068 || preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('XLSX requires bounded valid UTF-8 strings.');
        }
        // Count UTF-16 code units without requiring mbstring/iconv (Excel cell limit).
        $characters = preg_match_all('/./us', $value);
        $supplementary = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
        if ($characters === false || $supplementary === false || $characters + $supplementary > 32767) {
            throw new \InvalidArgumentException('XLSX cell exceeds 32767 UTF-16 code units.');
        }
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        // Preserve literal Excel escape syntax rather than decoding it on readback.
        $value = (string) preg_replace('/_x(?=[0-9A-Fa-f]{4}_)/', '_x005F_x', $value);
        return str_replace("\r", '&#13;', htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** Fixed safe entry names only; ZIP_STORED with CRCs, directory, and no extra fields. */
    private static function zip(array $files): string
    {
        $body = '';
        $directory = '';
        foreach ($files as $name => $contents) {
            $offset = strlen($body);
            $size = strlen($contents);
            $crc = crc32($contents);
            $length = strlen($name);
            // DOS date 1980-01-01; deterministic, no filesystem metadata.
            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $size, $size, $length, 0).$name.$contents;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, $length, 0, 0, 0, 0, 0, $offset).$name;
        }
        $archive = $body.$directory.pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
        if (strlen($archive) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('XLSX exceeds the 16 MiB export limit.');
        }
        return $archive;
    }
}
