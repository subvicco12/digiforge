<?php
declare(strict_types=1);
namespace DigiForge\POD;

/** Immutable identity of the reviewed Master 500 v2 research/migration workbook. */
final class MasterCatalogV2Reference {
 public const CATALOG_KEY='digicraftifygoods-master-500-v2';
 public const SOURCE_FILE='DigiForge_DigiCraftifyGoods_Master_500_v2_Research_Migration_Plan.xlsx';
 public const SOURCE_SHA256='56639c9122985ac17b46685bb8b91e5d29f387f16fff715b3488e9b4c2380f6d';
 public const MASTER_SHEET='Master_500_v2';
 public const MIGRATION_SHEET='Source_Migration_500';
 public const ROW_COUNT=500;
 public static function metadata():array{return ['catalog_key'=>self::CATALOG_KEY,'source_file'=>self::SOURCE_FILE,'source_sha256'=>self::SOURCE_SHA256,'master_sheet'=>self::MASTER_SHEET,'migration_sheet'=>self::MIGRATION_SHEET,'row_count'=>self::ROW_COUNT,'source_state'=>'MIGRATION_CANDIDATE','production_authority'=>false,'promotion_authorized'=>false];}
}
