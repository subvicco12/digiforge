<?php
declare(strict_types=1);
namespace DigiForge\ProductFactory;

use WP_Error;

/** Bounded local text/completeness evidence; never artwork, commercial or rights approval. */
final class ExternalDocumentEvidence
{
    public static function inspect(string $docxPath, string $pdfPath): array|WP_Error
    {
        try {
            if (!function_exists('proc_open') || !is_executable('/usr/bin/python3') || !is_executable('/usr/bin/pdftotext')) {
                throw new \RuntimeException();
            }
            $worker = __DIR__ . '/offline/verify_external_document.py';
            self::denySymlinkComponents($worker);
            $paths = [];
            $hashes = [];
            foreach ([$docxPath, $pdfPath] as $path) {
                self::denySymlinkComponents($path);
                if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path) < 1 || filesize($path) > 16777216) {
                    throw new \RuntimeException();
                }
                $real = realpath($path);
                $hash = hash_file('sha256', $path);
                if (!is_string($real) || !is_string($hash)) {
                    throw new \RuntimeException();
                }
                $paths[] = $real;
                $hashes[] = $hash;
            }
            $process = proc_open(
                ['/usr/bin/python3', '-B', '-I', $worker, $paths[0], $paths[1]],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                __DIR__,
                ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PYTHONNOUSERSITE' => '1'],
                ['bypass_shell' => true]
            );
            if (!is_resource($process)) {
                throw new \RuntimeException();
            }
            $output = '';
            $error = '';
            $deadline = microtime(true) + 20;
            try {
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                while (true) {
                    $output .= stream_get_contents($pipes[1], 8193);
                    $error .= stream_get_contents($pipes[2], 8193);
                    if (strlen($output) > 8192 || strlen($error) > 8192 || microtime(true) > $deadline) {
                        throw new \RuntimeException();
                    }
                    $state = proc_get_status($process);
                    if (!$state['running']) {
                        $output .= stream_get_contents($pipes[1], 8193);
                        $error .= stream_get_contents($pipes[2], 8193);
                        if ($state['exitcode'] !== 0 || $error !== '' || strlen($output) > 8192) {
                            throw new \RuntimeException();
                        }
                        break;
                    }
                    usleep(1000);
                }
            } finally {
                $state = proc_get_status($process);
                if ($state['running']) {
                    proc_terminate($process, 9);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            $receipt = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($receipt) || ($receipt['profile'] ?? null) !== 'digiforge-external-docx-pdf-v1'
                || ($receipt['technical_scope'] ?? null) !== 'SOURCE_TEXT_AND_DECLARED_BODY_BOUNDS_ONLY'
                || ($receipt['text_policy'] ?? null) !== 'UNICODE_WHITESPACE_COLLAPSE_ONLY_LITERAL_GLYPHS'
                || ($receipt['production_authorized'] ?? null) !== false || ($receipt['rights_certified'] ?? null) !== false
                || ($receipt['visual_approved'] ?? null) !== false
                || !is_int($receipt['page_count'] ?? null) || $receipt['page_count'] < 1 || $receipt['page_count'] > 200
                || !is_string($receipt['normalized_text_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $receipt['normalized_text_sha256']) !== 1) {
                throw new \RuntimeException();
            }
            foreach (['source_sha256', 'export_sha256'] as $index => $key) {
                if (($receipt[$key] ?? null) !== $hashes[$index] || hash_file('sha256', $paths[$index]) !== $hashes[$index]) {
                    throw new \RuntimeException();
                }
            }
            return $receipt + ['verifier_sha256' => hash_file('sha256', $worker), 'acceptance_state' => 'UNVERIFIED', 'external_execution_authorized' => false];
        } catch (\Throwable) {
            return new WP_Error('external_document_integrity_blocked', 'Exact source/export text and declared body bounds could not be verified by the bounded local parser. No visual, rights or commercial approval is granted.', ['status' => 409]);
        }
    }
    private static function denySymlinkComponents(string $path): void
    {
        $component = $path;
        while (true) {
            if (is_link($component)) {
                throw new \RuntimeException();
            }
            $parent = dirname($component);
            if ($parent === $component || $component === '.') {
                return;
            }
            $component = $parent;
        }
    }
}
