<?php
declare(strict_types=1);

/** Reuse the existing exact synthetic artifact builders without inheriting/rerunning their tests. */
function digiforge_existing_render_fixture(string $method, array $arguments = []): mixed
{
    if (!class_exists(RenderArtifactCertificationTest::class)) {
        require_once dirname(__DIR__).'/RenderArtifactCertificationTest.php';
    }
    $case = new RenderArtifactCertificationTest('testActualImageDigestAndDimensionsComeFromBytes');
    return (new ReflectionMethod($case, $method))->invokeArgs($case, $arguments);
}

/** Same narrowly selected disposable fixture cleanup as the existing artifact test class. */
function digiforge_reset_existing_render_fixture(): void
{
    global $wpdb;
    $t = DigiForge\Database\Tables::class;
    $wpdb->query("DELETE usage_row FROM ".$t::shop_ai_usage()." usage_row INNER JOIN ".$t::pod_production_templates()." template ON template.fingerprint=usage_row.model_key WHERE template.template_id='personalized_pod' AND usage_row.workflow='production_template'");
    $wpdb->query("DELETE ps FROM ".$t::personalization_submissions()." ps INNER JOIN ".$t::order_line_items()." li ON li.id=ps.order_line_item_id INNER JOIN ".$t::orders()." o ON o.id=li.order_id WHERE o.external_order_reference='personalized_pod' AND o.shop_reference='personalized_pod'");
    $wpdb->query("DELETE li FROM ".$t::order_line_items()." li INNER JOIN ".$t::orders()." o ON o.id=li.order_id WHERE o.external_order_reference='personalized_pod' AND o.shop_reference='personalized_pod'");
    $wpdb->delete($t::orders(), ['external_order_reference'=>'personalized_pod', 'shop_reference'=>'personalized_pod']);
    $wpdb->delete($t::pod_mappings(), ['mapping_version'=>'personalized_pod', 'provider_product_key'=>'101', 'product_version_id'=>1]);
    $wpdb->delete($t::pod_production_templates(), ['template_id'=>'personalized_pod']);
    $wpdb->delete($t::personalization_schemas(), ['product_version_id'=>1, 'schema_key'=>'name', 'version_label'=>'1']);
    $wpdb->delete($t::businesses(), ['business_key'=>'digicraftifygoods', 'display_name'=>'Fixture goods']);
}
