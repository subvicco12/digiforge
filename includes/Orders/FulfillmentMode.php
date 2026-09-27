<?php
declare(strict_types=1);
namespace DigiForge\Orders;

final class FulfillmentMode {
 public const DIGITAL='digital';public const POD='pod';public const HYBRID='hybrid';
 public static function classify(bool $hasDigital,bool $hasPod):string {
  if($hasDigital&&$hasPod)return self::HYBRID;if($hasPod)return self::POD;if($hasDigital)return self::DIGITAL;throw new \InvalidArgumentException('Fulfillment mode requires at least one line type');
 }
 public static function requiresProviderMapping(string $mode):bool {return in_array($mode,[self::POD,self::HYBRID],true);}
}