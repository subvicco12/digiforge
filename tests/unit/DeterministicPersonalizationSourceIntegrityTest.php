<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use DigiForge\POD\DeterministicPersonalizationRenderer;
use DigiForge\POD\ProductionTemplateContract;
use DigiForge\POD\RasterArtworkCodec;
use DigiForge\Orders\Validator;

final class DeterministicPersonalizationSourceIntegrityTest extends TestCase
{
    public static function sourceReferences():array
    {
        return ['unscoped content address'=>['pod-render-artifacts/',true], 'shop content address'=>['pod-render-artifacts/shop-personalized_pod/',true], 'legacy named source'=>['legacy/',false]];
    }
    /**
     * @dataProvider sourceReferences
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testApprovedContentAddressedSourceCannotChangeToAnotherValidImage(string $folder,bool $contentAddressed): void
    {
        if (!class_exists('WP_Error')) eval('class WP_Error { public function __construct(private string $code, public string $message="", public mixed $data=null) {} public function get_error_code():string { return $this->code; } }');
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        // Only ownership is supplied by the fixture. Renderer, verifier, decoder,
        // payload validation and protected-path resolution execute their real code.
        eval('namespace DigiForge\\POD; final class BusinessScopeRepository { public function assertActiveOwnershipForMapping(int $id):array { return ["business_key"=>BusinessScope::DIGICRAFTIFY_GOODS,"program_key"=>BusinessScope::PERSONALIZED_POD]; } } final class TemplateCycleRepository { public function assertOwner(string $shop,string $hash):true { return true; } }');
        if (!function_exists('trailingslashit')) eval('function trailingslashit(string $path):string { return rtrim($path,"/")."/"; }');
        if (!function_exists('wp_upload_dir')) eval('function wp_upload_dir():array { return ["basedir"=>$GLOBALS["source_integrity_uploads"]]; }');
        foreach (['POD/ProductionTemplateContract','POD/RasterArtworkCodec','POD/RepeatLayout','POD/ArtworkGeometryQa','POD/PersonalizationPayloadContract','POD/DeterministicPersonalizationRenderer','POD/RenderArtifactVerifier','ProductFactory/AssetStorage','Database/QueryEvidence'] as $file) require_once dirname(__DIR__,2).'/includes/'.$file.'.php';
        $root=sys_get_temp_dir().'/df-source-integrity-'.bin2hex(random_bytes(6));
        $GLOBALS['source_integrity_uploads']=$root;
        $directory=$root.'/digiforge/product-factory/'.rtrim($folder,'/');
        self::assertTrue(mkdir($directory,0700,true));
        $original=RasterArtworkCodec::encode(1,1,"\xff\0\0\xff");
        $replacement=RasterArtworkCodec::encode(1,1,"\0\0\xff\xff");
        $reference='digiforge/product-factory/'.$folder.($contentAddressed?hash('sha256',$original):'source').'.png';
        $path=$root.'/'.$reference;
        file_put_contents($path,$original);
        $template=ProductionTemplateContract::normalize(['template_id'=>'synthetic-source','template_version'=>1,'supplier'=>'synthetic','provider_blueprint_id'=>1,'provider_id'=>2,'variant_ids'=>[3],'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'SINGLE_PHOTO','template_status'=>'VALIDATED','print_areas'=>[['position'=>'front','decoration_method'=>'dtg','width_px'=>1,'height_px'=>1]]]);
        $recipe=['engine'=>'SINGLE_PHOTO','background'=>'#ffffff','panels'=>['front'=>['width_px'=>1,'height_px'=>1,'slots'=>[['type'=>'image','field'=>'photo','x'=>0,'y'=>0,'width'=>1,'height'=>1]]]]];
        $proof=['approved_by'=>1,'template_sha256'=>$template['fingerprint'],'recipe'=>$recipe,'recipe_sha256'=>hash('sha256',Validator::canonicalJson($recipe))];
        $payload=Validator::canonicalJson(['photo'=>$reference]);
        global $wpdb;
        $previous=$wpdb??null;
        $wpdb=new class($template,$proof,$payload) {
            public string $prefix='wp_'; public string $options='wp_options'; public string $last_error='';
            public function __construct(public array $template,public array $proof,public string $payload) {}
            public function prepare(string $sql,mixed ...$args):string { return $sql; }
            public function get_row(string $sql,mixed $mode):array {
                if(str_contains($sql,'pod_production_templates'))return array_replace($this->template,['variant_ids'=>json_encode($this->template['variant_ids']),'print_areas'=>json_encode($this->template['print_areas'])]);
                return ['provider'=>'synthetic','provider_variant_key'=>'2:3','provider_product_key'=>'1'];
            }
            public function get_var(string $sql):mixed {
                if(str_contains($sql,'option_value'))return json_encode($this->proof);
                if(str_contains($sql,'shop_reference'))return 'personalized_pod';
                if(str_contains($sql,'SELECT environment'))return 'test';
                return 1;
            }
            public function get_results(string $sql,mixed $mode):array { return [['canonical_payload'=>$this->payload,'payload_hash'=>hash('sha256',$this->payload),'field_definitions'=>'[{"key":"photo","type":"image_reference"}]','schema_state'=>'APPROVED','schema_reviewer'=>1,'schema_reviewed_at'=>'2026-10-20 00:00:00']]; }
        };
        $render=['template_key'=>'synthetic-source','template_version'=>'1','template_sha256'=>$template['fingerprint'],'provider_mapping_id'=>1,'order_id'=>1,'personalization_evidence_hash'=>hash('sha256',$payload)];
        try {
            $renderer=new DeterministicPersonalizationRenderer();
            $before=$renderer->expected($render);
            self::assertIsArray($before);
            self::assertStringContainsString(base64_encode($original),$before['svg']);
            file_put_contents($path,$replacement);
            $reader=(new DigiForge\POD\RenderArtifactVerifier())->readArtifact($path);
            self::assertIsArray($reader, 'The substituted source is still a completely decoded valid PNG.');
            self::assertSame(hash('sha256',$replacement),$reader['sha256']);
            $after=$renderer->expected($render);
            if(!$contentAddressed){self::assertIsArray($after);self::assertStringContainsString(base64_encode($replacement),$after['svg']);return;}
            self::assertInstanceOf(WP_Error::class,$after,'An unchanged approved reference must not adopt different valid source pixels.');
            self::assertSame('deterministic_render_image_conflict',$after->get_error_code());
        } finally {
            $wpdb=$previous;
            unlink($path);
            for($dir=$directory;$dir!==dirname($root);$dir=dirname($dir))rmdir($dir);
            unset($GLOBALS['source_integrity_uploads']);
        }
    }
}
