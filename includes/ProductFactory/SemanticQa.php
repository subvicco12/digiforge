<?php

declare(strict_types=1);

namespace DigiForge\ProductFactory;

use DigiForge\Launch\OpenAIClient;
use WP_Error;

/** Independent AI review of produced content before Gate 2. */
final class SemanticQa
{
    /**
     * @param array<string,mixed> $specification
     * @param list<array<string,mixed>> $assets
     * @return array{passed:bool,checks:list<array<string,mixed>>,model:string,response_id:string,usage:array<string,mixed>}|WP_Error
     */
    public function inspect(array $specification, array $assets): array|WP_Error
    {
        $inventory = [];
        foreach ($assets as $asset) {
            $file = (array) ($asset['file'] ?? []);
            $inventory[] = [
                'group' => sanitize_key((string) ($asset['group'] ?? '')),
                'filename' => sanitize_file_name((string) ($file['filename'] ?? '')),
                'format' => sanitize_key((string) ($file['format'] ?? '')),
                'purpose' => sanitize_text_field((string) (($asset['spec']['purpose'] ?? '') ?: '')),
                'byte_size' => (int) ($file['byte_size'] ?? 0),
                'checksum_sha256' => sanitize_text_field((string) ($file['checksum_sha256'] ?? '')),
                'sample' => $this->sample((string) ($file['absolute_path'] ?? ''), (string) ($file['format'] ?? '')),\n                'deterministic_qa' => array_map(static fn(array $check): array => ['name'=>(string)($check['name']??''),'passed'=>(bool)($check['passed']??false),'details'=>(array)($check['details']??[])], (array)($asset['qa']??[])),
            ];
        }

        $response = (new OpenAIClient())->develop($this->prompt($specification, $inventory));
        if (is_wp_error($response)) {
            return $response;
        }
        $payload = is_array($response['payload'] ?? null) ? $response['payload'] : [];
        $rawChecks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];
        $required = [
            'specification_match',
            'spelling_text_quality',
            'ip_trademark_risk',
            'prohibited_content',
            'link_qr_integrity',
            'marketing_product_consistency',
            'mockup_production_separation',
        ];

        $indexed = [];
        foreach ($rawChecks as $check) {
            if (! is_array($check)) { continue; }
            $name = sanitize_key((string) ($check['name'] ?? ''));
            if (! in_array($name, $required, true)) { continue; }
            $status = strtoupper(sanitize_key((string) ($check['status'] ?? 'FAIL')));
            $indexed[$name] = [
                'name' => $name,
                'passed' => $status === 'PASS',
                'details' => [
                    'summary' => sanitize_textarea_field((string) ($check['summary'] ?? '')),
                    'findings' => $this->findings($check['findings'] ?? []),
                ],
            ];
        }

        $checks = [];
        foreach ($required as $name) {
            $checks[] = $indexed[$name] ?? [
                'name' => $name,
                'passed' => false,
                'details' => ['summary' => 'Required semantic QA check was missing.', 'findings' => []],
            ];
        }

        return [
            'passed' => ! in_array(false, array_column($checks, 'passed'), true),
            'checks' => $checks,
            'model' => sanitize_text_field((string) ($response['model'] ?? '')),
            'response_id' => sanitize_text_field((string) ($response['response_id'] ?? '')),
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
        ];
    }

    /** @param mixed $value @return list<string> */
    private function findings($value): array
    {
        if (! is_array($value)) { return []; }
        $out = [];
        foreach (array_slice($value, 0, 12) as $finding) {
            $text = sanitize_text_field((string) $finding);
            if ($text !== '') { $out[] = $text; }
        }
        return $out;
    }

    private function sample(string $path, string $format): string
    {
        if ($path === '' || ! is_file($path)) { return ''; }
        $format = strtolower($format);
        if ($format === 'zip') { return '[ZIP package: binary integrity is checked separately]'; }
        $size = min(20000, max(1, (int) filesize($path)));
        $contents = file_get_contents($path, false, null, 0, $size);
        if (! is_string($contents)) { return ''; }
        if ($format === 'pdf') {
            return $this->pdfText($contents);
        }
        // HTML/SVG are already sanitized by LocalAssetProducer. Preserve markup here so
        // QA can inspect hrefs, visible text, placeholders and marketing/production claims.
        return substr($contents, 0, 18000);
    }

    private function pdfText(string $pdf): string
    {
        if (preg_match_all('/\((.*?)(?<!\\\\)\)\s*Tj/s', $pdf, $matches) !== 1 && empty($matches[1])) {
            return '[PDF text extraction unavailable; deterministic PDF integrity checked separately]';
        }
        $lines = [];
        foreach (array_slice((array) ($matches[1] ?? []), 0, 250) as $encoded) {
            $line = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], (string) $encoded);
            if (function_exists('mb_convert_encoding')) {
                $line = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
            }
            $lines[] = $line;
        }
        return substr(implode("\n", $lines), 0, 18000);
    }

    /** @param list<array<string,mixed>> $inventory */
    private function prompt(array $specification, array $inventory): string
    {
        $spec = wp_json_encode($specification, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $assets = wp_json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return "You are DigiForge independent Product QA. Return ONLY one JSON object with key checks; no markdown.\n"
            . "Approved product specification: {$spec}\nProduced asset inventory, checksums and content samples: {$assets}\n"
            . "Evaluate conservatively. Required checks, each exactly once: specification_match, spelling_text_quality, ip_trademark_risk, prohibited_content, link_qr_integrity, marketing_product_consistency, mockup_production_separation. "
            . "Each check must be {name,status,summary,findings}; status is PASS or FAIL only. Use the supplied deterministic_qa, checksum_sha256 and byte_size as valid machine evidence for binary/file integrity; do not fail merely because a binary PDF/ZIP cannot be fully rendered in the text sample. FAIL whenever the combined deterministic and semantic evidence is insufficient to verify a claimed link/QR, trademark/copyright safety, customer-file completeness, or separation of marketing from production assets. "
            . "For ip_trademark_risk, identify apparent unauthorized brands, protected characters, copyrighted franchises, celebrity/publicity-rights exploitation, copied marketplace content, or risky trademark use. "
            . "For prohibited_content, flag unsafe, illegal, hateful, sexual-minor, regulated-goods, deceptive, or policy-risk material. "
            . "For spelling_text_quality, check obvious spelling, grammar, placeholders and contradictory instructions. "
            . "Do not rewrite the product; act only as an independent fail-closed reviewer.";
    }
}
