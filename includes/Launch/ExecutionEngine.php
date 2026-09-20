<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Core\Settings;
use DigiForge\Database\Tables;
use DigiForge\ProductFactory\Repository as ProductRepository;
use DigiForge\Research\Repository as ResearchRepository;
use DigiForge\Security\Logger;

/** Orchestrates the first live DigiForge launch path while preserving explicit approval gates. */
final class ExecutionEngine
{
    /** @return array<string,mixed>|\WP_Error */
    public function research(array $input, string $key): array|\WP_Error
    {
        if (! Settings::is_enabled('research') || ! Settings::is_enabled('ai')) {
            return $this->error('switch_disabled', 'Research and AI must be effectively enabled before live research can run.', 409);
        }

        $market = sanitize_text_field((string) ($input['market'] ?? 'United States and Europe'));
        $shop = sanitize_key((string) ($input['shop'] ?? 'goods'));
        if (! in_array($shop, ['digital', 'goods'], true)) {
            return $this->error('validation', 'shop must be digital or goods.');
        }
        $focus = sanitize_text_field((string) ($input['focus'] ?? ($shop === 'goods' ? 'personalized print-on-demand products' : 'digital download products')));
        $query = sanitize_text_field((string) ($input['query'] ?? 'Find a commercially attractive Etsy product opportunity'));

        $brief = $this->researchPrompt($market, $shop, $focus, $query);
        $ai = (new OpenAIClient())->research($brief);
        if (is_wp_error($ai)) {
            return $ai;
        }
        $payload = is_array($ai['payload'] ?? null) ? $ai['payload'] : [];
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $summary = sanitize_textarea_field((string) ($payload['summary'] ?? ''));
        if ($title === '' || $summary === '') {
            return $this->error('invalid_research_output', 'Research output did not contain a usable title and summary.', 502);
        }

        $repo = new ResearchRepository();
        $source = $repo->createSource([
            'name' => 'DigiForge OpenAI Web Research',
            'source_type' => 'openai_web_search',
            'environment' => 'production',
            'config' => ['model' => (string) ($ai['model'] ?? ''), 'shop' => $shop],
        ], $key . '-source');
        if (is_wp_error($source)) {
            return $source;
        }

        $evidenceIds = [];
        $observations = is_array($payload['observations'] ?? null) ? $payload['observations'] : [];
        foreach (array_slice($observations, 0, 8) as $index => $observation) {
            if (! is_array($observation)) {
                continue;
            }
            $obs = $repo->ingest([
                'source_id' => (int) $source['id'],
                'external_id' => sanitize_text_field((string) ($observation['source_url'] ?? '')),
                'title' => sanitize_text_field((string) ($observation['title'] ?? '')),
                'body' => sanitize_textarea_field((string) ($observation['body'] ?? '')),
                'provenance' => ['url' => esc_url_raw((string) ($observation['source_url'] ?? '')), 'response_id' => (string) ($ai['response_id'] ?? '')],
            ], $key . '-observation-' . $index);
            if (is_wp_error($obs)) {
                continue;
            }
            $evidenceValue = sanitize_textarea_field((string) ($observation['evidence'] ?? ($observation['body'] ?? '')));
            if ($evidenceValue === '') {
                continue;
            }
            $evidence = $repo->addEvidence((int) $obs['id'], [
                'evidence_type' => 'market_signal',
                'value' => $evidenceValue,
                'provenance' => ['url' => esc_url_raw((string) ($observation['source_url'] ?? '')), 'response_id' => (string) ($ai['response_id'] ?? '')],
            ], $key . '-evidence-' . $index);
            if (! is_wp_error($evidence)) {
                $evidenceIds[] = (int) $evidence['id'];
            }
        }

        $candidate = $repo->createCandidate([
            'title' => $title,
            'summary' => $summary,
            'signals' => is_array($payload['signals'] ?? null) ? $payload['signals'] : [],
        ], $key . '-candidate');
        if (is_wp_error($candidate)) {
            return $candidate;
        }
        foreach ($evidenceIds as $evidenceId) {
            $repo->linkEvidence((int) $candidate['id'], $evidenceId);
        }

        Logger::audit('launch_research_completed', [
            'candidate_id' => (int) $candidate['id'],
            'shop' => $shop,
            'evidence_count' => count($evidenceIds),
            'response_id' => (string) ($ai['response_id'] ?? ''),
        ], 'research_candidate', (string) $candidate['id']);

        return [
            'candidate' => $candidate,
            'shop' => $shop,
            'evidence_count' => count($evidenceIds),
            'ai' => ['model' => $ai['model'] ?? '', 'response_id' => $ai['response_id'] ?? '', 'usage' => $ai['usage'] ?? []],
            'next_action' => 'Review and APPROVE the candidate before product development.',
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public function develop(int $candidateId, array $input, string $key): array|\WP_Error
    {
        if (! Settings::is_internal_enabled('ai') || ! Settings::is_internal_enabled('product_development')) {
            return $this->error('switch_disabled', 'AI and Product Development must be configured on before internal development can run.', 409);
        }
        $candidate = $this->candidate($candidateId);
        if ($candidate === null) {
            return $this->error('not_found', 'Research candidate not found.', 404);
        }
        if (($candidate['review_status'] ?? '') !== ResearchRepository::REVIEW_APPROVED) {
            return $this->error('approval_required', 'Research candidate must be explicitly APPROVED before product development.', 409);
        }

        $shop = sanitize_key((string) ($input['shop'] ?? 'goods'));
        if (! in_array($shop, ['digital', 'goods'], true)) {
            return $this->error('validation', 'shop must be digital or goods.');
        }

        $brief = $this->developmentPrompt($candidate, $shop);
        $ai = (new OpenAIClient())->develop($brief);
        if (is_wp_error($ai)) {
            return $ai;
        }
        $spec = is_array($ai['payload'] ?? null) ? $ai['payload'] : [];
        if ($shop === 'digital') { $normalized = $this->normalizeDigitalSpecification($spec); if (is_wp_error($normalized)) return $normalized; $spec = $normalized; }
        $productName = sanitize_text_field((string) ($spec['product_name'] ?? $candidate['title']));
        $familyName = sanitize_text_field((string) ($spec['family_name'] ?? ($productName . ' Collection')));
        $description = sanitize_textarea_field((string) ($spec['description'] ?? $candidate['summary']));

        $researchRepo = new ResearchRepository();
        $opportunity = $researchRepo->promote($candidateId, $key . '-opportunity');
        if (is_wp_error($opportunity)) {
            return $opportunity;
        }
        $products = new ProductRepository();
        $family = $products->create('product_family', [
            'opportunity_id' => (int) $opportunity['id'],
            'name' => $familyName,
            'description' => $description,
        ], $key . '-family');
        if (is_wp_error($family)) {
            return $family;
        }
        $product = $products->create('product', [
            'product_family_id' => (int) $family['id'],
            'name' => $productName,
            'description' => $description,
        ], $key . '-product');
        if (is_wp_error($product)) {
            return $product;
        }
        $versionToken = substr(hash('sha256', $key), 0, 10);
        $version = $products->create('product_version', [
            'product_id' => (int) $product['id'],
            'version_label' => (str_starts_with($key, 'u3-auto-') || str_starts_with($key, 'u3-repair-')) ? 'Capability Spec ' . $versionToken : 'Launch 1.0',
            'notes' => wp_json_encode(['shop' => $shop, 'spec' => $this->sanitizeStructured($spec)]),
        ], $key . '-version');
        if (is_wp_error($version)) {
            return $version;
        }

        Logger::audit('launch_product_developed', [
            'candidate_id' => $candidateId,
            'opportunity_id' => (int) $opportunity['id'],
            'product_id' => (int) $product['id'],
            'product_version_id' => (int) $version['id'],
            'shop' => $shop,
            'response_id' => (string) ($ai['response_id'] ?? ''),
        ], 'product', (string) $product['id']);

        return [
            'opportunity' => $opportunity,
            'product_family' => $family,
            'product' => $product,
            'product_version' => $version,
            'spec' => $this->sanitizeStructured($spec),
            'shop' => $shop,
            'next_action' => 'Generate production assets and listing package; Etsy publishing remains gated.',
        ];
    }

    /** @return array<string,mixed>|null */
    private function candidate(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Tables::research_candidates() . ' WHERE id=%d', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function researchPrompt(string $market, string $shop, string $focus, string $query): string
    {
        return "You are the live market-research engine for DigiForge, an Etsy product factory. Use web search extensively and return ONLY one JSON object, no markdown.\n"
            . "Target market: {$market}. Shop type: {$shop}. Focus: {$focus}. Research request: {$query}.\n"
            . "Research Etsy-relevant demand, competition gaps, likely pricing/margin, current trend strength, buyer intent, personalization opportunity, and policy/IP risks. Prefer recent evidence and concrete source URLs.\n"
            . 'Required JSON keys: title (string), summary (string), signals (object with demand, competition_gap, margin, trend, evidence_quality; each 0-100), observations (array of up to 8 objects with title, body, evidence, source_url). Do not invent numerical marketplace facts you cannot support.';
    }

    /** @param array<string,mixed> $candidate */
    private function developmentPrompt(array $candidate, string $shop): string
    {
        $title = sanitize_text_field((string) ($candidate['title'] ?? ''));
        $summary = sanitize_textarea_field((string) ($candidate['summary'] ?? ''));
        return "You are DigiForge Product Development. Return ONLY one JSON object, no markdown.\n"
            . "Approved opportunity: {$title}\nResearch summary: {$summary}\nShop: {$shop}.\n"
            . 'Create a production-ready product concept optimized for Etsy US and European buyers. Required keys: product_name, family_name, description, target_buyer, differentiation, personalization, variants, price_strategy, estimated_cost_strategy, seo_keywords, listing_title_draft, listing_description_draft, asset_requirements, qa_checklist, ip_policy_notes. For digital products, the approved specification MUST be directly producible by DigiForge using only local html, txt, json, csv, svg, pdf and zip assets. PDF files may be required because DigiForge can generate static, non-interactive PDFs locally. Never specify, promise, or require fillable, editable, interactive, AcroForm, form-field, save/reopen-form, or typed-entry PDF behavior. Do not require Canva templates, Canva access links, editable third-party templates, remote design services, or any other external-service deliverable. Do not require QR codes, QR placeholders, maps, map placeholders, RSVP links or placeholders, registry links or placeholders, hotel links or placeholders, or other destination-dependent functionality unless a real verified destination URL is already present in the approved opportunity input. Never invent URLs or require empty destination/link fields. For locally generated files, provenance evidence may consist of deterministic DigiForge generation metadata, SHA-256 checksums, creation records and an explicit declaration that only system fonts and locally generated text/layout/SVG content are used; do not require third-party font-license records when no third-party font is embedded. Every listing title, description, feature, variant and buyer promise must be backed by an asset requirement DigiForge can actually generate locally. If editable source files are promised, require individual flat SVG files with exact filenames for each promised source; do not describe or require an SVG source ZIP because DigiForge packages the complete customer product set into the final delivery ZIP. Include explicit provenance/licensing requirements for locally generated text, layouts and SVG content and avoid unnecessary third-party brand references. Preserve useful language variants only when their complete customer-facing content can be generated in the local package. For digital products, multilingual variants are NOT production-ready merely because AI can generate them: unless the approved opportunity input contains explicit human/fluent language-review evidence, the production specification must select one English-only delivery variant and move Spanish/French/bilingual variants to deferred_variants for later review. Include delivery_selection with selected_variant, selected_language, selected_files and deferred_variants. asset_requirements must include expected_page_counts mapping every promised PDF filename to an integer page count; when a PDF corresponds to standalone SVG source pages, the count must match those source pages. Customer-facing README/quick-start prose must never expose internal machine flags. For goods, make the concept compatible with Printify/Gelato where practical.';
    }

    /** Build the exact development brief for resumable/background Product Factory execution. */
    public function developmentBrief(int $candidateId, string $shop): string|\WP_Error
    {
        if (! Settings::is_internal_enabled('ai') || ! Settings::is_internal_enabled('product_development')) {
            return $this->error('switch_disabled', 'AI and Product Development must be configured on before internal development can run.', 409);
        }
        $candidate = $this->candidate($candidateId);
        if ($candidate === null) {
            return $this->error('not_found', 'Research candidate not found.', 404);
        }
        if (($candidate['review_status'] ?? '') !== ResearchRepository::REVIEW_APPROVED) {
            return $this->error('approval_required', 'Research candidate must be explicitly APPROVED before product development.', 409);
        }
        $shop = sanitize_key($shop);
        if (! in_array($shop, ['digital', 'goods'], true)) {
            return $this->error('validation', 'shop must be digital or goods.');
        }
        return $this->developmentPrompt($candidate, $shop);
    }

    /** Persist a completed background development payload without making another AI request. */
    public function persistDevelopment(int $candidateId, string $shop, string $key, array $ai): array|\WP_Error
    {
        $candidate = $this->candidate($candidateId);
        if ($candidate === null) return $this->error('not_found', 'Research candidate not found.', 404);
        if (($candidate['review_status'] ?? '') !== ResearchRepository::REVIEW_APPROVED) return $this->error('approval_required', 'Research candidate must be explicitly APPROVED before product development.', 409);
        $shop = sanitize_key($shop); if (! in_array($shop, ['digital','goods'], true)) return $this->error('validation', 'shop must be digital or goods.');
        $spec = is_array($ai['payload'] ?? null) ? $ai['payload'] : [];
        if ($spec === []) return $this->error('invalid_development_output', 'Development output did not contain a usable structured specification.', 502);
        if ($shop === 'digital') { $normalized = $this->normalizeDigitalSpecification($spec); if (is_wp_error($normalized)) return $normalized; $spec = $normalized; }
        $productName=sanitize_text_field((string)($spec['product_name']??$candidate['title']));$familyName=sanitize_text_field((string)($spec['family_name']??($productName.' Collection')));$description=sanitize_textarea_field((string)($spec['description']??$candidate['summary']));
        $researchRepo=new ResearchRepository();$opportunity=$researchRepo->promote($candidateId,$key.'-opportunity');if(is_wp_error($opportunity))return $opportunity;
        $products=new ProductRepository();$family=$products->create('product_family',['opportunity_id'=>(int)$opportunity['id'],'name'=>$familyName,'description'=>$description],$key.'-family');if(is_wp_error($family))return $family;
        $product=$products->create('product',['product_family_id'=>(int)$family['id'],'name'=>$productName,'description'=>$description],$key.'-product');if(is_wp_error($product))return $product;
        $versionToken=substr(hash('sha256',$key),0,10);$version=$products->create('product_version',['product_id'=>(int)$product['id'],'version_label'=>(str_starts_with($key,'u3-auto-')||str_starts_with($key,'u3-repair-'))?'Capability Spec '.$versionToken:'Launch 1.0','notes'=>wp_json_encode(['shop'=>$shop,'spec'=>$this->sanitizeStructured($spec)])],$key.'-version');if(is_wp_error($version))return $version;
        Logger::audit('launch_product_developed',['candidate_id'=>$candidateId,'opportunity_id'=>(int)$opportunity['id'],'product_id'=>(int)$product['id'],'product_version_id'=>(int)$version['id'],'shop'=>$shop,'response_id'=>(string)($ai['response_id']??'')],'product',(string)$product['id']);
        return ['opportunity'=>$opportunity,'product_family'=>$family,'product'=>$product,'product_version'=>$version,'spec'=>$this->sanitizeStructured($spec),'shop'=>$shop,'next_action'=>'Generate production assets and listing package; Etsy publishing remains gated.'];
    }

    /** @return array<string,mixed> */
    private function normalizeDigitalSpecification(array $spec): array|\WP_Error
    {
        $variants=is_array($spec['variants']??null)?array_values(array_filter($spec['variants'],'is_array')):[];
        if($variants===[])return $spec;

        $isTranslationVariant=static function(array $variant):bool{
            $name=strtolower((string)($variant['name']??''));
            return (bool)preg_match('/\b(?:spanish|french|german|italian|portuguese|dutch|polish|swedish|norwegian|danish|finnish|bilingual|multilingual)\b/i',$name);
        };
        $translationVariants=[];$productionVariants=[];
        foreach($variants as$variant){if($isTranslationVariant($variant))$translationVariants[]=$variant;else$productionVariants[]=$variant;}
        if($translationVariants===[])return $spec;
        if($productionVariants===[])return $this->error('translation_review_required','Digital specification contains only translated/bilingual variants without a reviewed source-language production variant.',502);

        $selected=$productionVariants[0];
        foreach($productionVariants as$variant){$name=strtolower((string)($variant['name']??''));if(str_contains($name,'english')){$selected=$variant;break;}}
        $deferred=[];
        foreach($translationVariants as$variant){$variant['availability']='requires_language_review';$variant['defer_reason']='No explicit human/fluent translation-review evidence is attached to the approved opportunity.';$deferred[]=$variant;}
        foreach($productionVariants as&$variant){$variant['availability']=$variant['availability']??'production_ready';}unset($variant);
        $spec['variants']=$productionVariants;$spec['deferred_variants']=$deferred;

        $requirements=is_array($spec['asset_requirements']??null)?$spec['asset_requirements']:[];
        $roots=array_values(array_filter(array_map('sanitize_file_name',(array)($requirements['required_root_files']??[]))));
        $existingSelection=is_array($spec['delivery_selection']??null)?$spec['delivery_selection']:[];
        $selectedFiles=array_values(array_filter(array_map('sanitize_file_name',(array)($existingSelection['selected_files']??[]))));
        $selectedFiles=array_values(array_filter($selectedFiles,static fn(string $file):bool=>!preg_match('/(?:_en_(?:es|fr|de|it|pt|nl|pl|sv|no|da|fi)_|_(?:es|fr|de|it|pt|nl|pl|sv|no|da|fi)_|spanish|french|german|italian|portuguese|dutch|polish|swedish|norwegian|danish|finnish)/i',$file)));
        if($selectedFiles===[]&&is_array($requirements['english_files']??null))$selectedFiles=array_values(array_filter(array_map('sanitize_file_name',$requirements['english_files'])));
        if($selectedFiles===[]){
            $allFiles=array_values(array_filter(array_map('sanitize_file_name',(array)($requirements['package_structure']??[]))));
            $selectedFiles=array_values(array_filter($allFiles,static fn(string $file):bool=>!preg_match('/(?:_en_(?:es|fr|de|it|pt|nl|pl|sv|no|da|fi)_|_(?:es|fr|de|it|pt|nl|pl|sv|no|da|fi)_|spanish|french|german|italian|portuguese|dutch|polish|swedish|norwegian|danish|finnish)/i',$file)));
        }
        $selectedFiles=array_values(array_unique(array_merge($roots,$selectedFiles)));
        $machineEvidence=['DELIVERY-MANIFEST.json','LICENSE-AND-PROVENANCE.txt','provenance.json'];
        $requirements['machine_evidence_files']=$machineEvidence;
        $requirements['package_structure']=$selectedFiles;

        $pageStructure=is_array($requirements['page_structure']??null)?$requirements['page_structure']:[];
        $defaultPageCount=count($pageStructure);
        $existingCounts=is_array($requirements['expected_page_counts']??null)?$requirements['expected_page_counts']:[];
        $pageCounts=[];
        foreach($selectedFiles as$file){
            if(!str_ends_with(strtolower($file),'.pdf'))continue;
            $canonical=sanitize_file_name($file);
            $existing=0;
            foreach($existingCounts as$countName=>$countValue){
                if(sanitize_file_name((string)$countName)===$canonical){$existing=(int)$countValue;break;}
            }
            $pageCounts[$canonical]=$existing>0?$existing:max(1,$defaultPageCount);
        }
        $requirements['expected_page_counts']=$pageCounts;
        $requirements['content_specification']='Current production scope contains only reviewed-language customer files. Every customer-facing file must contain complete final copy with no placeholder links, invented facts, or unreviewed translation claims.';
        $requirements['delivery_specification']='Package only delivery_selection.selected_files in the customer ZIP. Machine audit evidence remains outside the customer ZIP. PDFs are static; SVG sources are standalone files.';
        $spec['asset_requirements']=$requirements;
        $spec['delivery_selection']=[
            'selected_variant'=>(string)($selected['name']??'English Complete Kit'),
            'selected_language'=>'en',
            'selected_files'=>$selectedFiles,
            'selection_rule'=>'reviewed-language-only production; unreviewed translations are deferred',
            'deferred_variants'=>array_map(static fn(array $v):string=>(string)($v['name']??'deferred'),$deferred),
            'machine_evidence_files'=>$machineEvidence,
        ];

        $productName=sanitize_text_field((string)($spec['product_name']??'Digital Product'));
        $spec['listing_title_draft']=$productName.' | Static PDF + SVG Digital Download';
        $description=sanitize_textarea_field((string)($spec['description']??''));
        $spec['listing_description_draft']=$description."\n\nCurrent production delivery: reviewed English-language files only. Includes the exact files listed in delivery_selection.selected_files. PDFs are static and non-interactive; SVG source files are supplied individually for compatible local vector editing. Unreviewed translated variants are deferred and are not included or advertised as production-ready.";
        foreach(['differentiation','seo_keywords','qa_checklist']as$key){if(!is_array($spec[$key]??null))continue;$spec[$key]=array_values(array_filter($spec[$key],static fn($v):bool=>!preg_match('/\b(?:spanish|french|german|italian|portuguese|dutch|polish|swedish|norwegian|danish|finnish|bilingual|multilingual)\b/i',(string)$v)));}
        return $spec;
    }

    /** @return array<string,mixed> */
    private function sanitizeStructured(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $cleanKey = sanitize_key((string) $key);
            if ($cleanKey === '') {
                continue;
            }
            if (is_array($item)) {
                $out[$cleanKey] = array_is_list($item)
                    ? array_map(fn($v) => is_array($v) ? $this->sanitizeStructured($v) : sanitize_textarea_field((string) $v), $item)
                    : $this->sanitizeStructured($item);
            } else {
                $out[$cleanKey] = sanitize_textarea_field((string) $item);
            }
        }
        return $out;
    }

    private function error(string $code, string $message, int $status = 400): \WP_Error
    {
        return new \WP_Error('digiforge_launch_' . $code, __($message, 'digiforge'), ['status' => $status]);
    }
}
