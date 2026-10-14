<?php

declare(strict_types=1);

use DigiForge\POD\ProductionTemplateContract;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/includes/POD/ProductionTemplateContract.php';

final class PrintifyNativeTemplateGeometryContractTest extends TestCase
{
    private function catalogVariant(int $id=202,int $width=1800): array
    {
        $payload=['id'=>$id,'title'=>'White / 11oz','options'=>['size'=>'11oz','color'=>'White'],'placeholders'=>[['position'=>'front','decoration_method'=>'dtg','width'=>$width,'height'=>1800]]];
        $encoded=wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return ['provider'=>'printify','environment'=>'production','provider_product_key'=>'101','provider_variant_key'=>'2:'.$id,'attributes'=>$payload+['_digiforge_source_evidence'=>['schema'=>'printify-variant-evidence-v1','endpoint_path'=>'/v1/catalog/blueprints/101/print_providers/2/variants.json','response_sha256'=>str_repeat('a',64),'observed_at'=>'2026-10-11T12:00:00Z','variant_sha256'=>hash('sha256',(string)$encoded),'blueprint_id'=>101,'provider_id'=>2,'variant_id'=>$id,'environment'=>'production']],'source_revision'=>'rev-1'];
    }

    private function geometry(int $width=1800): array
    {
        return [['position'=>'front','decoration_method'=>'dtg','width_px'=>$width,'height_px'=>1800,'region'=>'US']];
    }

    private function template(string $status='GEOMETRY_LOCKED'): array
    {
        return ['template_id'=>'printify-shirt','template_version'=>1,'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'PHOTO_TEXT','template_status'=>$status];
    }

    public function testPrintifyPlaceholderIsAuthoritativeAndFingerprintBound(): void
    {
        $template=ProductionTemplateContract::fromPrintifyCatalog($this->catalogVariant(),$this->geometry(),$this->template());
        self::assertSame([202],$template['variant_ids']);
        self::assertSame(1800,$template['print_areas'][0]['width_px']);
        self::assertSame('printify-placeholder-binding-v1',$template['print_areas'][0]['_catalog_bindings'][0]['schema']);
        self::assertSame(str_repeat('a',64),$template['print_areas'][0]['_catalog_bindings'][0]['response_sha256']);
    }

    public function testCallerDimensionsCannotReplaceSupplierGeometry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProductionTemplateContract::fromPrintifyCatalog($this->catalogVariant(),$this->geometry(1799),$this->template());
    }

    public function testSelectedVariantsWithDifferentPhysicalGeometryMustSplit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProductionTemplateContract::fromPrintifyVariants([$this->catalogVariant(202,1800),$this->catalogVariant(203,1790)],$this->geometry(),$this->template());
    }

    public function testStaleOrForgedVariantEvidenceFailsClosed(): void
    {
        $variant=$this->catalogVariant();$variant['attributes']['placeholders'][0]['width']=1700;
        $this->expectException(InvalidArgumentException::class);
        ProductionTemplateContract::fromPrintifyCatalog($variant,$this->geometry(),$this->template());
    }

    public function testNonDraftPrintifyTemplateCannotBeNormalizedFromCallerGeometryAlone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProductionTemplateContract::normalize(['template_id'=>'freehand','template_version'=>1,'supplier'=>'printify','provider_blueprint_id'=>101,'provider_id'=>2,'variant_ids'=>[202],'print_areas'=>$this->geometry(),'personalization_pipeline'=>'DIGIFORGE_RENDER','personalization_engine'=>'PHOTO_TEXT','template_status'=>'VALIDATED']);
    }

    public function testPrintifyVariantCannotBindUnlistedPlacement(): void
    {
        $geometry=$this->geometry();$geometry[0]['position']='back';
        $this->expectException(InvalidArgumentException::class);
        ProductionTemplateContract::fromPrintifyCatalog($this->catalogVariant(),$geometry,$this->template());
    }
}
