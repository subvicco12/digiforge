<?php
declare(strict_types=1);
namespace DigiForge\POD;
use DigiForge\Research\{ArtworkPersonalizationContract, CommercialResearchContract};

/** Explicit offline preparation; never changes stored ISO data, native fields or approvals. */
final class OfflineDateDisplayProof
{
    public const POLICY = 'digiforge-en-month-day-year-v1';
    private const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    public static function printedDate(string $iso): string
    {
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $iso, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new \InvalidArgumentException('Exact valid Gregorian ISO date with year 0001–9999 required.');
        }
        return self::MONTHS[(int)$parts[2]-1].' '.(int)$parts[3].', '.$parts[1];
    }

    public static function render(string $product, array $spec, string $artwork, array $canonical, string $policy = self::POLICY): array
    {
        $profile = ['FL-03'=>'fl03_mug_11oz','FL-04'=>'fl04_poster_8x10'][$product] ?? null;
        if ($policy !== self::POLICY || $profile === null || ($spec['profile'] ?? null) !== $profile) {
            throw new \InvalidArgumentException('Exact supported product/profile and versioned display policy required.');
        }
        $nameCap = $product === 'FL-03' ? 13 : 16;
        $dateCap = $product === 'FL-03' ? 18 : 24;
        $fields = [['key'=>'first_name','type'=>'text','required'=>true,'max_length'=>$nameCap],
            ['key'=>'second_name','type'=>'text','required'=>true,'max_length'=>$nameCap],
            ['key'=>'date','type'=>'date','required'=>true,'max_length'=>10]];
        // Validate canonical values before making a separate display copy. No implicit trim/normalization.
        PersonalizationPayloadContract::validate($fields, $canonical);
        $display = $canonical;
        $display['date'] = self::printedDate($canonical['date']);
        if (strlen($display['date']) > $dateCap) {
            throw new \InvalidArgumentException('Printed date exceeds preserved native date cap; manual HOLD.');
        }
        $displayFields = $fields;
        $displayFields[2] = ['key'=>'date','type'=>'text','required'=>true,'max_length'=>$dateCap];
        $proof = ArtworkPersonalizationContract::proof($spec, $artwork, $fields, $canonical);
        // Preserve existing name/glyph/layout HOLD decisions. The comma is generated only by
        // this approved date policy, never admitted from arbitrary customer name text.
        if ($proof['svg'] !== null) {
            try {
                $render = LocalPersonalizationProof::render($spec, $artwork, $display);
                $proof['svg'] = $render['svg'];
                $proof['proof_sha256'] = hash('sha256', $render['svg']);
                $proof['render_receipt'] = $render['receipt'];
            } catch (\InvalidArgumentException) {
                $proof['svg'] = null;
                $proof['proof_sha256'] = null;
                $proof['render_receipt'] = null;
                $proof['state'] = 'MANUAL_HOLD';
                $proof['reasons'][] = 'PRINTED_DATE_LAYOUT_REQUIRES_MANUAL_REVIEW';
            }
        }
        $proof['samples'] = $display;
        $proof['fields'] = $displayFields;
        $proof['sample_values_sha256'] = self::hash($display);
        $policyRecord = ['id'=>$policy,'months'=>self::MONTHS,'format'=>'EnglishMonth D, YYYY',
            'canonical_format'=>'YYYY-MM-DD','year_range'=>[1,9999], 'name_caps'=>[$nameCap,$nameCap],
            'native_date_cap'=>$dateCap,'offline_only'=>true];
        $r = ['schema'=>'digiforge-offline-date-display-proof-v1','product'=>$product,
            'canonical_inputs'=>$canonical,'canonical_inputs_sha256'=>self::hash($canonical),
            'policy'=>$policyRecord,'policy_sha256'=>self::hash($policyRecord),
            'display_inputs_sha256'=>self::hash($display), 'proof'=>$proof,
            'production_file'=>false,'external_execution_authorized'=>false,'release_hold'=>true,
            'native_fit_approved'=>false,'owner_artwork_approval'=>null,
            'remaining_gates'=>['NATIVE_FONT_WEIGHT_SIZE_AND_FIT','DATE_FLOOR','PREVIEW_FINAL_OUTPUT_PARITY','PHYSICAL_LEGIBILITY','OWNER_ART_RIGHTS_MEDIA_APPROVAL']];
        // Binds canonical input AND display policy to exact art/spec/render identities from the proof.
        $r['receipt_sha256'] = self::hash($r);
        return $r;
    }

    private static function hash(array $record): string
    {
        return hash('sha256', CommercialResearchContract::json($record));
    }
}
