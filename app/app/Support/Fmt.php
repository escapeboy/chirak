<?php

namespace App\Support;

class Fmt
{
    public static function pct(?float $value, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format($value * 100, $decimals).'%';
    }

    public static function delta(?float $value): string
    {
        return $value === null ? '—' : sprintf('%+.1f pts', $value * 100);
    }

    public static function statusLabel(string $status): string
    {
        return str_replace('_', ' ', $status);
    }
}
