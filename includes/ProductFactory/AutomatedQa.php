<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

/** Deterministic local QA for generated assets. No external provider calls. */
final class AutomatedQa
{
    /** @param array<string,mixed> $asset @return array{passed:bool,checks:list<array<string,mixed>>} */
    public function inspect(array $asset): array
    {
        $path = (string) ($asset['absolute_path'] ?? '');
        $format = strtolower((string) ($asset['format'] ?? ''));
        $expectedChecksum = strtolower((string) ($asset['checksum_sha256'] ?? ''));
        $checks = [];

        $exists = $path !== '' && is_file($path);
        $checks[] = $this->check('file_exists', $exists, ['path' => basename($path)]);
        if (! $exists) {
            return ['passed' => false, 'checks' => $checks];
        }
        $size = (int) filesize($path);
        $checks[] = $this->check('file_nonempty', $size > 0, ['byte_size' => $size]);
        $actualChecksum = hash_file('sha256', $path);
        $checks[] = $this->check('checksum_match', $expectedChecksum !== '' && hash_equals($expectedChecksum, $actualChecksum), []);

        $filename = basename($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $checks[] = $this->check('filename_safe', $filename !== '' && $filename === sanitize_file_name($filename), ['filename' => $filename]);
        $checks[] = $this->check('extension_match', $extension === $format, ['extension' => $extension, 'format' => $format]);

        $sample = (string) file_get_contents($path, false, null, 0, min(262144, max(1, $size)));
        $integrity = match ($format) {
            'pdf' => str_starts_with($sample, '%PDF-') && str_contains($sample, '%%EOF'),
            'svg' => str_contains(strtolower($sample), '<svg') && str_contains(strtolower($sample), '</svg>'),
            'html' => preg_match('/<(?:html|main|section|article|div|body)\b/i', $sample) === 1,
            'json' => json_decode($sample, true) !== null && json_last_error() === JSON_ERROR_NONE,
            'csv', 'txt' => trim($sample) !== '',
            'zip' => $this->zipReport($path)['valid'],
            default => false,
        };
        $checks[] = $this->check('format_integrity', $integrity, ['format' => $format]);

        $unsafe = preg_match('/\b(api[_ -]?key|secret[_ -]?key|access[_ -]?token|bearer\s+[a-z0-9._-]{12,})\b/i', $sample) === 1;
        $checks[] = $this->check('credential_leak_scan', ! $unsafe, []);

        if ($format === 'pdf') {
            $pageCount = preg_match_all('/\/Type\s*\/Page\b/', $sample);
            $checks[] = $this->check('pdf_page_count', is_int($pageCount) && $pageCount > 0, ['page_count' => (int) $pageCount]);
            preg_match('/\/MediaBox\s*\[\s*0\s+0\s+([0-9.]+)\s+([0-9.]+)\s*\]/', $sample, $mediaBox);
            $actualWidth = (float) ($mediaBox[1] ?? 0);
            $actualHeight = (float) ($mediaBox[2] ?? 0);
            $expectedGeometry = $this->expectedPdfGeometry($filename);
            $mediaBoxValid = isset($mediaBox[1], $mediaBox[2])
                && ($expectedGeometry === null
                    || (abs($actualWidth - $expectedGeometry['width']) < 0.01
                        && abs($actualHeight - $expectedGeometry['height']) < 0.01));
            $checks[] = $this->check('pdf_media_box', $mediaBoxValid, [
                'width' => $actualWidth,
                'height' => $actualHeight,
                'expected_width' => $expectedGeometry['width'] ?? null,
                'expected_height' => $expectedGeometry['height'] ?? null,
            ]);
            $streams = [];
            preg_match_all('/stream\R(.*?)\Rendstream/s', $sample, $matches);
            foreach ((array) ($matches[1] ?? []) as $stream) {
                $streams[] = hash('sha256', preg_replace('/\s+/', ' ', trim((string) $stream)) ?? (string) $stream);
            }
            $noDuplicatePages = count($streams) <= 1 || count($streams) === count(array_unique($streams));
            $checks[] = $this->check('pdf_duplicate_page_scan', $noDuplicatePages, ['content_streams' => count($streams)]);
            $forbiddenPdfFeatures = preg_match('/\\/(?:URI|Annots|JavaScript|JS|OpenAction|AA)\\b|\\/Subtype\\s*\\/Link\\b|\\/Type\\s*\\/XObject\\b|\\/Subtype\\s*\\/Image\\b/i', $sample) === 1;
            $checks[] = $this->check('pdf_link_qr_map_scan', ! $forbiddenPdfFeatures, [
                'links_present' => preg_match('/\\/(?:URI|Annots)\\b|\\/Subtype\\s*\\/Link\\b/i', $sample) === 1,
                'active_actions_present' => preg_match('/\\/(?:JavaScript|JS|OpenAction|AA)\\b/i', $sample) === 1,
                'image_xobjects_present' => preg_match('/\\/Type\\s*\\/XObject\\b|\\/Subtype\\s*\\/Image\\b/i', $sample) === 1,
                'generator_profile' => 'digiforge_local_text_pdf',
            ]);
        }

        if ($format === 'svg') {
            preg_match('/<svg\b([^>]*)>/i', $sample, $root);
            $attributes = (string) ($root[1] ?? '');
            $hasViewBox = preg_match('/\bviewBox\s*=\s*["\'][^"\']+["\']/i', $attributes) === 1;
            $hasWidth = preg_match('/\bwidth\s*=\s*["\'][^"\']+["\']/i', $attributes) === 1;
            $hasHeight = preg_match('/\bheight\s*=\s*["\'][^"\']+["\']/i', $attributes) === 1;
            $checks[] = $this->check('svg_dimensions', $hasViewBox || ($hasWidth && $hasHeight), [
                'viewbox' => $hasViewBox,
                'width' => $hasWidth,
                'height' => $hasHeight,
            ]);
            $active = preg_match('/<\s*(?:script|iframe|object|embed|foreignObject)\b|\bon[a-z]+\s*=|javascript\s*:/i', $sample) === 1;
            $checks[] = $this->check('svg_active_content_scan', ! $active, []);
        }

        if ($format === 'html') {
            $active = preg_match('/<\s*(?:script|iframe|object|embed)\b|\bon[a-z]+\s*=|javascript\s*:/i', $sample) === 1;
            $checks[] = $this->check('html_active_content_scan', ! $active, []);
        }

        if ($format === 'zip') {
            $zip = $this->zipReport($path);
            $checks[] = $this->check('zip_safe_members', $zip['safe_members'], ['member_count' => $zip['member_count']]);
            $checks[] = $this->check('zip_unique_members', $zip['unique_members'], ['member_count' => $zip['member_count']]);
            $checks[] = $this->check('zip_member_inventory', $zip['member_count'] > 0, ['member_count' => $zip['member_count'], 'members' => $zip['members']]);
        }

        return [
            'passed' => ! in_array(false, array_column($checks, 'passed'), true),
            'checks' => $checks,
        ];
    }

    /** @return array<string,mixed> */
    private function check(string $name, bool $passed, array $details): array
    {
        return ['name' => $name, 'passed' => $passed, 'details' => $details];
    }

    /** @return array{valid:bool,safe_members:bool,unique_members:bool,member_count:int,members:list<string>} */
    private function zipReport(string $path): array
    {
        $fallback = ['valid' => false, 'safe_members' => false, 'unique_members' => false, 'member_count' => 0, 'members' => []];
        if (! class_exists('ZipArchive')) { return $fallback; }
        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::CHECKCONS);
        if ($opened !== true) { return $fallback; }
        $names = [];
        $safe = true;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $names[] = $name;
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../') || str_contains($name, '..\\')) {
                $safe = false;
            }
        }
        $count = $zip->numFiles;
        $zip->close();
        return [
            'valid' => $count > 0,
            'safe_members' => $safe && $count > 0,
            'unique_members' => $count > 0 && count($names) === count(array_unique($names)),
            'member_count' => $count,
            'members' => array_values($names),
        ];
    }
    /** @return array{width:float,height:float}|null */
    private function expectedPdfGeometry(string $filename): ?array
    {
        $name = strtolower($filename);
        if (str_contains($name, 'mobile')) {
            return ['width' => 360.0, 'height' => 640.0];
        }
        if (preg_match('/(?:^|[_\\-])a4(?:[_\\-.]|$)/', $name) === 1) {
            return ['width' => 595.0, 'height' => 842.0];
        }
        if (str_contains($name, 'usletter') || str_contains($name, 'us_letter') || str_contains($name, 'us-letter')) {
            return ['width' => 612.0, 'height' => 792.0];
        }
        return null;
    }

}
