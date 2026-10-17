<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Separate human approval; no wildcard capability, mutation or implicit lock transition. */
final class PrintifyCatalogGetSafetyExceptionContract {
 public static function normalize(array $input,array $grant,int $now):array {
  $keys=['capability','expires_at','acknowledge_catalog_get_only','acknowledge_no_mutations','acknowledge_locks_remain_on'];
  if(count($input)!==count($keys)||array_diff(array_keys($input),$keys)||($input['capability']??null)!==PrintifyCatalogReadContract::CAPABILITY)throw new \InvalidArgumentException('Exact catalog-GET exception approval required.');
  foreach(['acknowledge_catalog_get_only','acknowledge_no_mutations','acknowledge_locks_remain_on']as $k)if(($input[$k]??null)!==true)throw new \InvalidArgumentException('Explicit true acknowledgements required.');
  PrintifyCatalogReadContract::normalize(array_replace($grant,['expires_at'=>$input['expires_at']]),$now);
  if(strtotime($input['expires_at'])>strtotime($grant['expires_at']))throw new \InvalidArgumentException('Exception cannot outlive its grant.');
  return $input;
 }
}
