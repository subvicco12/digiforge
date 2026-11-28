<?php

declare(strict_types=1);

namespace DigiForge\POD;

use DigiForge\Listings\Validator;
use DigiForge\Research\CommercialResearchContract;
use DigiForge\Research\DesignFactoryContract;
use InvalidArgumentException;

/** Local text-layout review only. No supplier font metrics, print file or execution authority. */
final class LocalPersonalizationProof
{
    public static function render(array $specification, string $artwork, array $samples): array
    {
        $context = ['research' => $specification['research'] ?? [],
            'concept' => $specification['concept'] ?? [],
            'commercial_hash' => $specification['commercial_hash'] ?? null,
            'selected_concept' => $specification['selected_concept'] ?? null,
            'briefs' => $specification['source_briefs'] ?? []];
        $spec = DesignFactoryContract::specification($context, $specification['profile'] ?? '', $specification['layout'] ?? []);
        if (CommercialResearchContract::json($spec) !== CommercialResearchContract::json($specification)) {
            throw new InvalidArgumentException('Exact validated design specification required.');
        }
        DesignFactoryContract::svg($artwork, $spec);
        $regions = $spec['layout']['native_regions'];
        $keys = array_column($regions, 'name');
        if (count($samples) !== count($keys) || array_diff(array_keys($samples), $keys)) {
            throw new InvalidArgumentException('One explicit sample for each reserved text region required.');
        }
        $doc = new \DOMDocument();
        $doc->loadXML($artwork, LIBXML_NONET);
        $root = $doc->documentElement;
        $description = $doc->createElementNS('http://www.w3.org/2000/svg', 'desc');
        $description->appendChild($doc->createTextNode('LOCAL SAMPLE PROOF ONLY. Generic font; supplier typography, safe zones and print parity UNVERIFIED. RELEASE HOLD. Not a production print file.'));
        $root->appendChild($description);
        $fields = [];
        foreach ($regions as $region) {
            $sample = $samples[$region['name']];
            // No shrinking, clipping, multiline interpretation or silent image-to-text conversion.
            if ($region['kind'] !== 'text' || !is_string($sample) || strlen($sample) > 320
                || trim($sample) === '' || preg_match('//u', $sample) !== 1
                || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $sample)) {
                throw new InvalidArgumentException('Bounded single-line UTF-8 text required; image proofs are unsupported.');
            }
            $fontSize = min(48, intdiv($region['height'], 2));
            $length = preg_match_all('/./us', $sample);
            $limit = $fontSize > 0 ? min(80, intdiv($region['width'], $fontSize)) : 0;
            if ($length === false || $length > $limit || $limit < 1) {
                throw new InvalidArgumentException('Sample exceeds conservative layout budget; manual review required.');
            }
            $text = $doc->createElementNS('http://www.w3.org/2000/svg', 'text');
            foreach (['x' => $region['x'] + intdiv($region['width'], 2),
                'y' => $region['y'] + intdiv($region['height'], 2),
                'font-size' => $fontSize, 'font-family' => 'sans-serif',
                'text-anchor' => 'middle', 'fill' => '#111111'] as $name => $value) {
                $text->setAttribute($name, (string) $value);
            }
            $text->appendChild($doc->createTextNode($sample));
            $root->appendChild($text);
            $fields[] = ['layer' => $region['name'], 'sample_sha256' => hash('sha256', $sample),
                'codepoints' => $length, 'layout_budget' => $limit, 'font_size_px' => $fontSize];
        }
        $svg = $doc->saveXML($root);
        if (!is_string($svg) || strlen($svg) > 70000) {
            throw new InvalidArgumentException('Proof exceeds local bound.');
        }
        $receipt = ['schema' => 'digiforge-local-text-proof-v1', 'profile' => $spec['profile'],
            'artwork_sha256' => hash('sha256', $artwork),
            'specification_sha256' => hash('sha256', CommercialResearchContract::json($spec)),
            'proof_sha256' => hash('sha256', $svg),
            'width_px' => $spec['geometry']['width_px'], 'height_px' => $spec['geometry']['height_px'],
            'fields' => $fields, 'supplier_certified' => false, 'production_file' => false,
            'external_execution_authorized' => false, 'release_hold' => true,
            'remaining_gates' => ['PROVIDER_FONT_METRICS_UNVERIFIED', 'SUPPLIER_SAFE_ZONE_UNVERIFIED',
                'PREVIEW_PRODUCTION_PARITY_REQUIRED', 'HUMAN_PROOF_REVIEW_REQUIRED']];
        $receipt['sha256'] = hash('sha256', Validator::canonicalJson($receipt));
        return ['svg' => $svg, 'receipt' => $receipt];
    }
}
