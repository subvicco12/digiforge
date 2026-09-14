<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use WP_Error;

/** Converts a structured production brief into reviewable local product and marketing artifacts. */
final class ProductArtifactBuilder
{
    public function __construct(private ?ArtifactStorage $storage = null)
    {
        $this->storage ??= new ArtifactStorage();
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,array{prompt:string,bytes:string}> $generatedImages
     * @return array<string,mixed>|WP_Error
     */
    public function build(int $productId, int $productVersionId, string $productName, array $payload, array $generatedImages = []): array|WP_Error
    {
        $directory = $this->storage->productDirectory($productId, $productVersionId);
        if (is_wp_error($directory)) {
            return $directory;
        }

        $pages = $this->pages($payload);
        if ($pages === []) {
            return new WP_Error('digiforge_product_content_empty', 'Production output did not contain usable product pages.', ['status' => 502]);
        }

        $files = [];
        $customerRefs = [];
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return new WP_Error('digiforge_product_json', 'Unable to serialize generated product content.', ['status' => 500]);
        }
        $contentFile = $this->storage->write($directory, 'product-content.json', $json, 'application/json');
        if (is_wp_error($contentFile)) { return $contentFile; }
        $files[] = $contentFile + ['role' => 'product_source', 'name' => 'Product content'];
        $customerRefs[] = $contentFile['storage_reference'];

        $readme = $this->readme($productName, $payload);
        $readmeFile = $this->storage->write($directory, 'README.txt', $readme, 'text/plain');
        if (is_wp_error($readmeFile)) { return $readmeFile; }
        $files[] = $readmeFile + ['role' => 'customer_file', 'name' => 'Customer instructions'];
        $customerRefs[] = $readmeFile['storage_reference'];

        $html = $this->html($productName, $pages);
        $htmlFile = $this->storage->write($directory, 'printable-guide.html', $html, 'text/html');
        if (is_wp_error($htmlFile)) { return $htmlFile; }
        $files[] = $htmlFile + ['role' => 'customer_file', 'name' => 'Printable guide'];
        $customerRefs[] = $htmlFile['storage_reference'];

        $svgRefs = [];
        foreach ($pages as $index => $page) {
            $filename = sprintf('page-%02d.svg', $index + 1);
            $svg = $this->svgPage($productName, $page, $index + 1, count($pages));
            $file = $this->storage->write($directory, $filename, $svg, 'image/svg+xml');
            if (is_wp_error($file)) { return $file; }
            $files[] = $file + ['role' => 'customer_file', 'name' => sprintf('Printable page %d', $index + 1), 'width_px' => 1240, 'height_px' => 1754];
            $svgRefs[] = $file['storage_reference'];
            $customerRefs[] = $file['storage_reference'];
        }

        $pdf = $this->pdfFromSvg($directory, $svgRefs);
        if (! is_wp_error($pdf) && is_array($pdf)) {
            $files[] = $pdf + ['role' => 'customer_file', 'name' => 'Printable PDF'];
            $customerRefs[] = $pdf['storage_reference'];
        }

        $marketing = $this->marketingCards($directory, $productName, $payload);
        if (is_wp_error($marketing)) { return $marketing; }
        foreach ($marketing as $file) {
            $files[] = $file;
        }

        foreach (array_slice($generatedImages, 0, 4) as $index => $image) {
            if (! is_array($image) || ! isset($image['bytes']) || ! is_string($image['bytes']) || $image['bytes'] === '') {
                continue;
            }
            $file = $this->storage->writeBytes(
                $directory,
                sprintf('lifestyle-%02d.png', $index + 1),
                $image['bytes'],
                'image/png'
            );
            if (is_wp_error($file)) {
                continue;
            }
            $files[] = $file + [
                'role' => 'listing_image',
                'name' => sprintf('Lifestyle mockup %d', $index + 1),
                'width_px' => 1536,
                'height_px' => 1024,
                'prompt' => sanitize_textarea_field((string)($image['prompt'] ?? '')),
            ];
        }

        $package = $this->storage->zip($directory, 'customer-download.zip', $customerRefs);
        if (is_wp_error($package)) { return $package; }
        $files[] = $package + ['role' => 'customer_package', 'name' => 'Customer download package'];

        return [
            'directory' => $directory,
            'files' => $files,
            'package' => $package,
            'page_count' => count($pages),
            'pdf_generated' => ! is_wp_error($pdf),
        ];
    }

    /** @param array<string,mixed> $payload @return array<int,array<string,mixed>> */
    private function pages(array $payload): array
    {
        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
        $out = [];
        foreach (array_slice($pages, 0, 20) as $page) {
            if (! is_array($page)) { continue; }
            $title = sanitize_text_field((string)($page['title'] ?? ''));
            if ($title === '') { continue; }
            $sections = [];
            foreach (array_slice((array)($page['sections'] ?? []), 0, 8) as $section) {
                if (! is_array($section)) { continue; }
                $heading = sanitize_text_field((string)($section['heading'] ?? ''));
                $body = sanitize_textarea_field((string)($section['body'] ?? ''));
                $bullets = array_values(array_filter(array_map(
                    static fn($v): string => sanitize_text_field((string)$v),
                    array_slice((array)($section['bullets'] ?? []), 0, 8)
                )));
                if ($heading !== '' || $body !== '' || $bullets !== []) {
                    $sections[] = compact('heading', 'body', 'bullets');
                }
            }
            $out[] = [
                'title' => $title,
                'subtitle' => sanitize_text_field((string)($page['subtitle'] ?? '')),
                'sections' => $sections,
                'footer' => sanitize_text_field((string)($page['footer'] ?? '')),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $payload */
    private function readme(string $productName, array $payload): string
    {
        $instructions = sanitize_textarea_field((string)($payload['customer_instructions'] ?? 'Open the printable guide in a modern browser and use Print to PDF for a local PDF copy.'));
        $license = sanitize_textarea_field((string)($payload['license_text'] ?? 'For personal use by the purchaser. Do not resell or redistribute the source files.'));
        return $productName . "\n" . str_repeat('=', min(70, max(10, strlen($productName)))) . "\n\n"
            . "HOW TO USE\n" . $instructions . "\n\nLICENSE\n" . $license . "\n";
    }

    /** @param array<int,array<string,mixed>> $pages */
    private function html(string $productName, array $pages): string
    {
        $body = '';
        foreach ($pages as $index => $page) {
            $body .= '<section class="page"><header><div class="eyebrow">' . esc_html($productName) . '</div><h1>' . esc_html((string)$page['title']) . '</h1>';
            if ((string)$page['subtitle'] !== '') { $body .= '<p class="subtitle">' . esc_html((string)$page['subtitle']) . '</p>'; }
            $body .= '</header>';
            foreach ((array)$page['sections'] as $section) {
                $body .= '<div class="section">';
                if ((string)$section['heading'] !== '') { $body .= '<h2>' . esc_html((string)$section['heading']) . '</h2>'; }
                if ((string)$section['body'] !== '') { $body .= '<p>' . nl2br(esc_html((string)$section['body'])) . '</p>'; }
                if ((array)$section['bullets'] !== []) {
                    $body .= '<ul>';
                    foreach ((array)$section['bullets'] as $bullet) { $body .= '<li>' . esc_html((string)$bullet) . '</li>'; }
                    $body .= '</ul>';
                }
                $body .= '</div>';
            }
            $body .= '<footer>Page ' . esc_html((string)($index + 1)) . ' of ' . esc_html((string)count($pages)) . '</footer></section>';
        }
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html($productName) . '</title><style>@page{size:A4;margin:0}*{box-sizing:border-box}body{margin:0;background:#eef1f5;font-family:Arial,Helvetica,sans-serif;color:#172033}.page{width:210mm;min-height:297mm;margin:10mm auto;background:white;padding:18mm 17mm 14mm;page-break-after:always;position:relative}.eyebrow{font-size:10pt;letter-spacing:.12em;text-transform:uppercase;color:#65708a}.page h1{font-family:Georgia,serif;font-size:28pt;line-height:1.1;margin:7mm 0 2mm}.subtitle{font-size:12pt;color:#5c6780}.section{margin-top:8mm}.section h2{font-size:15pt;margin:0 0 2mm}.section p,.section li{font-size:10.5pt;line-height:1.55}.section ul{padding-left:5mm}.page footer{position:absolute;bottom:8mm;left:17mm;right:17mm;font-size:9pt;color:#8a93a6;border-top:1px solid #e5e8ef;padding-top:3mm}@media print{body{background:white}.page{margin:0;box-shadow:none}}</style></head><body>' . $body . '</body></html>';
    }

    /** @param array<string,mixed> $page */
    private function svgPage(string $productName, array $page, int $pageNo, int $pageCount): string
    {
        $titleLines = $this->wrap((string)$page['title'], 34, 2);
        $y = 210;
        $content = '';
        foreach ($titleLines as $line) {
            $content .= '<text x="100" y="' . $y . '" font-family="Georgia,serif" font-size="58" font-weight="700" fill="#172033">' . $this->xml($line) . '</text>';
            $y += 70;
        }
        if ((string)$page['subtitle'] !== '') {
            foreach ($this->wrap((string)$page['subtitle'], 58, 3) as $line) {
                $content .= '<text x="100" y="' . $y . '" font-family="Arial,sans-serif" font-size="28" fill="#667085">' . $this->xml($line) . '</text>';
                $y += 40;
            }
        }
        $y += 35;
        foreach ((array)$page['sections'] as $section) {
            if ($y > 1510) { break; }
            if ((string)$section['heading'] !== '') {
                $content .= '<text x="100" y="' . $y . '" font-family="Arial,sans-serif" font-size="31" font-weight="700" fill="#25304a">' . $this->xml((string)$section['heading']) . '</text>';
                $y += 45;
            }
            if ((string)$section['body'] !== '') {
                foreach ($this->wrap((string)$section['body'], 72, 6) as $line) {
                    if ($y > 1570) { break; }
                    $content .= '<text x="100" y="' . $y . '" font-family="Arial,sans-serif" font-size="24" fill="#364152">' . $this->xml($line) . '</text>';
                    $y += 34;
                }
            }
            foreach ((array)$section['bullets'] as $bullet) {
                if ($y > 1570) { break; }
                foreach ($this->wrap('• ' . (string)$bullet, 68, 3) as $line) {
                    $content .= '<text x="120" y="' . $y . '" font-family="Arial,sans-serif" font-size="23" fill="#364152">' . $this->xml($line) . '</text>';
                    $y += 32;
                }
            }
            $y += 25;
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="1240" height="1754" viewBox="0 0 1240 1754">'
            . '<rect width="1240" height="1754" fill="#ffffff"/><rect x="58" y="58" width="1124" height="1638" rx="30" fill="none" stroke="#d9dee9" stroke-width="3"/>'
            . '<text x="100" y="125" font-family="Arial,sans-serif" font-size="20" letter-spacing="2" fill="#697386">' . $this->xml(strtoupper($productName)) . '</text>'
            . $content
            . '<line x1="100" y1="1630" x2="1140" y2="1630" stroke="#e3e7ef" stroke-width="2"/><text x="100" y="1670" font-family="Arial,sans-serif" font-size="19" fill="#8a93a6">PAGE ' . $pageNo . ' / ' . $pageCount . '</text></svg>';
    }

    /** @param array<string,mixed> $payload @return array<int,array<string,mixed>>|WP_Error */
    private function marketingCards(string $directory, string $productName, array $payload): array|WP_Error
    {
        $cards = is_array($payload['listing_images'] ?? null) ? $payload['listing_images'] : [];
        if ($cards === []) {
            $cards = [
                ['headline' => $productName, 'subheadline' => 'Premium editable digital bundle'],
                ['headline' => 'What is included', 'subheadline' => 'A complete, organized customer-ready toolkit'],
                ['headline' => 'Designed for easy use', 'subheadline' => 'Print, save, personalize and share'],
            ];
        }
        $out = [];
        foreach (array_slice($cards, 0, 6) as $index => $card) {
            if (! is_array($card)) { continue; }
            $headline = sanitize_text_field((string)($card['headline'] ?? $productName));
            $subheadline = sanitize_text_field((string)($card['subheadline'] ?? 'Digital download')); 
            $svg = $this->marketingSvg($productName, $headline, $subheadline, $index);
            $file = $this->storage->write($directory, sprintf('listing-card-%02d.svg', $index + 1), $svg, 'image/svg+xml');
            if (is_wp_error($file)) { return $file; }
            $out[] = $file + ['role' => 'listing_image', 'name' => sprintf('Listing card %d', $index + 1), 'width_px' => 2000, 'height_px' => 1600];
        }
        return $out;
    }

    private function marketingSvg(string $productName, string $headline, string $subheadline, int $index): string
    {
        $palette = [['#f8f2ec','#3f2e2a','#b77f66'],['#eff4f7','#18324a','#6f95ad'],['#f4f1f7','#302641','#9b7fb0'],['#f1f6f0','#213b2a','#7c9d82']];
        [$bg,$ink,$accent] = $palette[$index % count($palette)];
        $title = implode('</text><text x="130" dy="90"', array_map(fn(string $line): string => '>' . $this->xml($line), $this->wrap($headline, 32, 3)));
        return '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="2000" height="1600" viewBox="0 0 2000 1600">'
            . '<rect width="2000" height="1600" fill="' . $bg . '"/><rect x="95" y="95" width="1810" height="1410" rx="70" fill="none" stroke="' . $accent . '" stroke-width="8"/>'
            . '<circle cx="1630" cy="370" r="250" fill="' . $accent . '" opacity=".16"/><circle cx="1730" cy="1230" r="390" fill="' . $accent . '" opacity=".10"/>'
            . '<text x="130" y="220" font-family="Arial,sans-serif" font-size="34" letter-spacing="5" fill="' . $accent . '">' . $this->xml(strtoupper($productName)) . '</text>'
            . '<text x="130" y="560" font-family="Georgia,serif" font-size="100" font-weight="700" fill="' . $ink . '"' . $title . '</text>'
            . '<text x="130" y="1030" font-family="Arial,sans-serif" font-size="46" fill="' . $ink . '" opacity=".78">' . $this->xml($subheadline) . '</text>'
            . '<text x="130" y="1390" font-family="Arial,sans-serif" font-size="32" fill="' . $accent . '">DIGICRAFTIFY DIGITAL</text></svg>';
    }

    /** @param array<int,string> $svgRefs @return array<string,mixed>|WP_Error */
    private function pdfFromSvg(string $directory, array $svgRefs): array|WP_Error
    {
        if (! class_exists('Imagick') || $svgRefs === []) {
            return new WP_Error('digiforge_pdf_renderer_unavailable', 'Server PDF renderer is unavailable.', ['status' => 501]);
        }
        try {
            $pdf = new \Imagick();
            foreach ($svgRefs as $reference) {
                $path = $this->storage->resolve($reference);
                if (is_wp_error($path)) { continue; }
                $page = new \Imagick();
                $page->setBackgroundColor('white');
                $page->readImage($path);
                $page->setImageFormat('pdf');
                $pdf->addImage($page);
                $page->clear();
                $page->destroy();
            }
            if ($pdf->getNumberImages() < 1) {
                return new WP_Error('digiforge_pdf_empty', 'No PDF pages could be rendered.', ['status' => 500]);
            }
            $pdf->setImageFormat('pdf');
            $blob = $pdf->getImagesBlob();
            $pdf->clear();
            $pdf->destroy();
            if (! is_string($blob) || $blob === '') {
                return new WP_Error('digiforge_pdf_empty', 'PDF renderer returned no data.', ['status' => 500]);
            }
            return $this->storage->writeBytes($directory, 'printable-guide.pdf', $blob, 'application/pdf');
        } catch (\Throwable $e) {
            return new WP_Error('digiforge_pdf_render_failed', 'PDF rendering failed; SVG and HTML source files remain available.', ['status' => 500]);
        }
    }

    /** @return array<int,string> */
    private function wrap(string $text, int $width, int $maxLines): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)) ?? '');
        if ($text === '') { return []; }
        $wrapped = wordwrap($text, max(12, $width), "\n", true);
        return array_slice(array_values(array_filter(array_map('trim', explode("\n", $wrapped)))), 0, max(1, $maxLines));
    }

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
