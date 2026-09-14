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

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $checks[] = $this->check('extension_match', $extension === $format, ['extension' => $extension, 'format' => $format]);
        $sample = (string) file_get_contents($path, false, null, 0, min(65536, max(1, $size)));
        $integrity = match ($format) {
            'pdf' => str_starts_with($sample, '%PDF-') && str_contains($sample, '%%EOF'),
            'svg' => str_contains(strtolower($sample), '<svg') && str_contains(strtolower($sample), '</svg>'),
            'html' => preg_match('/<(?:html|main|section|article|div|body)\b/i', $sample) === 1,
            'json' => json_decode($sample, true) !== null && json_last_error() === JSON_ERROR_NONE,
            'csv', 'txt' => trim($sample) !== '',
            'zip' => $this->zipValid($path),
            default => false,
        };
        $checks[] = $this->check('format_integrity', $integrity, ['format' => $format]);

        $unsafe = preg_match('/\b(api[_ -]?key|secret[_ -]?key|access[_ -]?token|bearer\s+[a-z0-9._-]{12,})\b/i', $sample) === 1;
        $checks[] = $this->check('credential_leak_scan', ! $unsafe, []);

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

    private function zipValid(string $path): bool
    {
        if (! class_exists('ZipArchive')) { return false; }
        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::CHECKCONS);
        if ($opened !== true) { return false; }
        $valid = $zip->numFiles > 0;
        $zip->close();
        return $valid;
    }
}
