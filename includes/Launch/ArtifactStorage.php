<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use WP_Error;

/** Stores generated product artifacts below a deny-by-default private content directory. */
final class ArtifactStorage
{
    private const ROOT_NAME = 'digiforge-private';

    public function productDirectory(int $productId, int $productVersionId): string|WP_Error
    {
        if ($productId < 1 || $productVersionId < 1) {
            return new WP_Error('digiforge_artifact_invalid_parent', 'A valid product and product version are required.', ['status' => 400]);
        }
        $relative = sprintf('%s/product-%d/version-%d', self::ROOT_NAME, $productId, $productVersionId);
        $absolute = trailingslashit(WP_CONTENT_DIR) . $relative;
        if (! $this->ensureDirectory($absolute)) {
            return new WP_Error('digiforge_artifact_directory', 'Unable to prepare private artifact storage.', ['status' => 500]);
        }
        return $relative;
    }

    /** @return array{storage_reference:string,checksum_sha256:string,byte_size:int,mime_type:string}|WP_Error */
    public function write(string $directoryReference, string $filename, string $contents, string $mimeType): array|WP_Error
    {
        return $this->writeBytes($directoryReference, $filename, $contents, $mimeType);
    }

    /** @return array{storage_reference:string,checksum_sha256:string,byte_size:int,mime_type:string}|WP_Error */
    public function writeBytes(string $directoryReference, string $filename, string $bytes, string $mimeType): array|WP_Error
    {
        $filename = sanitize_file_name($filename);
        if ($filename === '' || str_contains($filename, '..')) {
            return new WP_Error('digiforge_artifact_filename', 'Invalid artifact filename.', ['status' => 400]);
        }
        $directory = $this->absoluteDirectory($directoryReference);
        if (is_wp_error($directory)) {
            return $directory;
        }
        $path = trailingslashit($directory) . $filename;
        $written = @file_put_contents($path, $bytes, LOCK_EX);
        if ($written === false || $written !== strlen($bytes)) {
            return new WP_Error('digiforge_artifact_write', 'Unable to write generated artifact.', ['status' => 500]);
        }
        @chmod($path, 0640);
        return [
            'storage_reference' => trailingslashit($directoryReference) . $filename,
            'checksum_sha256' => hash_file('sha256', $path) ?: hash('sha256', $bytes),
            'byte_size' => (int) filesize($path),
            'mime_type' => sanitize_text_field($mimeType),
        ];
    }

    /**
     * @param array<int,string> $references
     * @return array{storage_reference:string,checksum_sha256:string,byte_size:int,mime_type:string}|WP_Error
     */
    public function zip(string $directoryReference, string $filename, array $references): array|WP_Error
    {
        if (! class_exists('ZipArchive')) {
            return new WP_Error('digiforge_artifact_zip_unavailable', 'ZIP support is unavailable on this server.', ['status' => 500]);
        }
        $directory = $this->absoluteDirectory($directoryReference);
        if (is_wp_error($directory)) {
            return $directory;
        }
        $filename = sanitize_file_name($filename);
        if ($filename === '' || ! str_ends_with(strtolower($filename), '.zip')) {
            return new WP_Error('digiforge_artifact_zip_name', 'A valid ZIP filename is required.', ['status' => 400]);
        }
        $zipPath = trailingslashit($directory) . $filename;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('digiforge_artifact_zip_open', 'Unable to create product ZIP package.', ['status' => 500]);
        }
        $added = 0;
        foreach (array_values(array_unique($references)) as $reference) {
            $path = $this->resolve($reference);
            if (is_wp_error($path) || ! is_file($path)) {
                continue;
            }
            $zip->addFile($path, basename($path));
            $added++;
        }
        $zip->close();
        if ($added < 1 || ! is_file($zipPath)) {
            @unlink($zipPath);
            return new WP_Error('digiforge_artifact_zip_empty', 'Product ZIP package contained no files.', ['status' => 500]);
        }
        @chmod($zipPath, 0640);
        return [
            'storage_reference' => trailingslashit($directoryReference) . $filename,
            'checksum_sha256' => hash_file('sha256', $zipPath) ?: '',
            'byte_size' => (int) filesize($zipPath),
            'mime_type' => 'application/zip',
        ];
    }

    public function resolve(string $reference): string|WP_Error
    {
        $reference = ltrim(str_replace('\\', '/', sanitize_text_field($reference)), '/');
        if (! str_starts_with($reference, self::ROOT_NAME . '/') || str_contains($reference, '../')) {
            return new WP_Error('digiforge_artifact_reference', 'Invalid private artifact reference.', ['status' => 400]);
        }
        $root = trailingslashit(WP_CONTENT_DIR) . self::ROOT_NAME;
        $path = trailingslashit(WP_CONTENT_DIR) . $reference;
        $realRoot = realpath($root);
        $realPath = realpath($path);
        if (! is_string($realRoot) || ! is_string($realPath) || ! str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            return new WP_Error('digiforge_artifact_not_found', 'Private artifact was not found.', ['status' => 404]);
        }
        return $realPath;
    }

    private function absoluteDirectory(string $reference): string|WP_Error
    {
        $reference = trim(str_replace('\\', '/', sanitize_text_field($reference)), '/');
        if (! str_starts_with($reference, self::ROOT_NAME . '/') || str_contains($reference, '../')) {
            return new WP_Error('digiforge_artifact_reference', 'Invalid artifact directory reference.', ['status' => 400]);
        }
        $path = trailingslashit(WP_CONTENT_DIR) . $reference;
        if (! $this->ensureDirectory($path)) {
            return new WP_Error('digiforge_artifact_directory', 'Unable to prepare artifact directory.', ['status' => 500]);
        }
        return $path;
    }

    private function ensureDirectory(string $path): bool
    {
        if (! is_dir($path) && ! wp_mkdir_p($path)) {
            return false;
        }
        $root = trailingslashit(WP_CONTENT_DIR) . self::ROOT_NAME;
        if (! is_dir($root) && ! wp_mkdir_p($root)) {
            return false;
        }
        $htaccess = trailingslashit($root) . '.htaccess';
        if (! is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX);
        }
        $index = trailingslashit($root) . 'index.php';
        if (! is_file($index)) {
            @file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX);
        }
        return true;
    }
}
