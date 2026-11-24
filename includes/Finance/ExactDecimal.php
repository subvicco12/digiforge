<?php
declare(strict_types=1);

namespace DigiForge\Finance;

use InvalidArgumentException;

/** Declaration-v1 uses four-place money, ten-place rates and half-away-from-zero conversion. */
final class ExactDecimal
{
    public static function money(mixed $value): string { return self::normalize($value, 4, 10); }
    public static function rate(mixed $value): string
    {
        $rate = self::normalize($value, 10, 9);
        if ($rate[0] === '-' || $rate === '0.0000000000') throw new InvalidArgumentException('FX rate must be positive.');
        return $rate;
    }

    public static function negate(string $value): string
    {
        $value = self::normalize($value, 4, 60);
        return $value === '0.0000' ? $value : ($value[0] === '-' ? substr($value, 1) : '-' . $value);
    }

    public static function add(string $left, string $right): string
    {
        $left = self::normalize($left, 4, 60); $right = self::normalize($right, 4, 60);
        $negativeLeft = $left[0] === '-'; $negativeRight = $right[0] === '-';
        $a = str_replace(['-', '.'], '', $left); $b = str_replace(['-', '.'], '', $right);
        if ($negativeLeft === $negativeRight) return self::decimal(self::unsignedAdd($a, $b), 4, $negativeLeft);
        $compare = self::compareUnsigned($a, $b);
        return $compare >= 0 ? self::decimal(self::unsignedSubtract($a, $b), 4, $negativeLeft)
            : self::decimal(self::unsignedSubtract($b, $a), 4, $negativeRight);
    }

    public static function convert(string $amount, string $rate): string
    {
        $amount = self::money($amount); $rate = self::rate($rate);
        $a = str_replace(['-', '.'], '', $amount); $b = str_replace('.', '', $rate);
        $digits = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; --$i) for ($j = strlen($b) - 1; $j >= 0; --$j) {
            $digits[$i + $j + 1] += (int) $a[$i] * (int) $b[$j];
        }
        for ($i = count($digits) - 1; $i > 0; --$i) { $digits[$i - 1] += intdiv($digits[$i], 10); $digits[$i] %= 10; }
        $product = str_pad(ltrim(implode('', $digits), '0'), 11, '0', STR_PAD_LEFT);
        $units = substr($product, 0, -10);
        if ((int) substr($product, -10, 1) >= 5) $units = self::unsignedAdd($units, '1');
        return self::money(self::decimal($units, 4, $amount[0] === '-'));
    }

    /** Existing v2 margin: net operating profit / gross revenue * 100, rounded half away. */
    public static function percentage(string $part,string $total):?string
    {
        $part=self::normalize($part,4,60);$total=self::normalize($total,4,60);
        if($total==='0.0000')return null;
        $negative=($part[0]==='-')!==($total[0]==='-');
        $numerator=str_replace(['-','.'],'',$part).'000000';$denominator=ltrim(str_replace(['-','.'],'',$total),'0');
        $quotient='';$remainder='0';
        foreach(str_split($numerator)as$digit){
            $remainder=ltrim($remainder.$digit,'0')?:'0';$count=0;
            while(self::compareUnsigned($remainder,$denominator)>=0){$remainder=self::unsignedSubtract($remainder,$denominator);++$count;}
            $quotient.=(string)$count;
        }
        if(self::compareUnsigned(self::unsignedAdd($remainder,$remainder),$denominator)>=0)$quotient=self::unsignedAdd($quotient,'1');
        return self::decimal($quotient,4,$negative);
    }

    private static function normalize(mixed $value, int $scale, int $integerDigits): string
    {
        if (!is_string($value) || strlen($value) > 80 || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,' . $scale . '})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('A plain bounded decimal string is required.');
        }
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        if (strlen($integer) > $integerDigits) throw new InvalidArgumentException('Decimal exceeds storage bounds.');
        return self::decimal($integer . str_pad($fraction, $scale, '0'), $scale, $negative);
    }

    private static function decimal(string $units, int $scale, bool $negative): string
    {
        $units = ltrim($units, '0');
        if ($units === '') return '0.' . str_repeat('0', $scale);
        $units = str_pad($units, $scale + 1, '0', STR_PAD_LEFT);
        return ($negative ? '-' : '') . substr($units, 0, -$scale) . '.' . substr($units, -$scale);
    }

    private static function compareUnsigned(string $a, string $b): int
    {
        $a = ltrim($a, '0'); $b = ltrim($b, '0');
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    private static function unsignedAdd(string $a, string $b): string
    {
        $result = ''; $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry > 0; --$i, --$j) {
            $sum = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $result = (string) ($sum % 10) . $result; $carry = intdiv($sum, 10);
        }
        return ltrim($result, '0') ?: '0';
    }

    private static function unsignedSubtract(string $a, string $b): string
    {
        $result = ''; $borrow = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; --$i, --$j) {
            $difference = (int) $a[$i] - ($j >= 0 ? (int) $b[$j] : 0) - $borrow;
            $borrow = $difference < 0 ? 1 : 0;
            $result = (string) ($difference + ($borrow ? 10 : 0)) . $result;
        }
        return ltrim($result, '0') ?: '0';
    }
}
