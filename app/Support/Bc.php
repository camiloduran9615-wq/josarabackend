<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Helpers de aritmética decimal exacta (bcmath wrapper).
 *
 * El sistema usa DECIMAL(18,4) interno y COP enteros en presentación. Toda operación
 * que toque dinero (saldos, impuestos, totales) DEBE pasar por aquí — nunca usar
 * operadores `+`, `-`, `*`, `/` directos sobre montos para evitar float drift.
 *
 * Esta clase resuelve además el tipado strict de phpstan nivel 8: las funciones
 * `bc*()` exigen `numeric-string`, y los modelos Eloquent castean DECIMAL como
 * `string` genérico. Centralizar conversiones aquí mantiene los call sites limpios.
 */
final class Bc
{
    /** Escala interna estándar para cálculos intermedios (4 decimales). */
    public const SCALE_INTERNAL = 4;

    /** Escala de presentación al usuario (COP enteros). */
    public const SCALE_DISPLAY = 0;

    /** Tolerancia para validación de partida doble (0.01 COP). */
    public const TOLERANCIA_COP = '0.01';

    /**
     * Normaliza cualquier valor numérico a `numeric-string`, listo para bcmath.
     *
     * @return numeric-string
     *
     * @throws InvalidArgumentException si el valor no es interpretable como número.
     */
    public static function n(string|int|float $valor): string
    {
        $s = is_string($valor) ? trim($valor) : (string) $valor;

        if ($s === '' || ! is_numeric($s)) {
            throw new InvalidArgumentException("Valor no numérico para bcmath: {$s}");
        }

        return $s;
    }

    /** Suma de a + b con escala 4. */
    public static function add(string|int|float $a, string|int|float $b, int $scale = self::SCALE_INTERNAL): string
    {
        return bcadd(self::n($a), self::n($b), $scale);
    }

    /** Resta a - b con escala 4. */
    public static function sub(string|int|float $a, string|int|float $b, int $scale = self::SCALE_INTERNAL): string
    {
        return bcsub(self::n($a), self::n($b), $scale);
    }

    /** Multiplicación a × b con escala 4. */
    public static function mul(string|int|float $a, string|int|float $b, int $scale = self::SCALE_INTERNAL): string
    {
        return bcmul(self::n($a), self::n($b), $scale);
    }

    /** División a ÷ b con escala 8 por default (precisión intermedia). */
    public static function div(string|int|float $a, string|int|float $b, int $scale = 8): string
    {
        return bcdiv(self::n($a), self::n($b), $scale);
    }

    /**
     * Comparación: devuelve -1, 0, 1 según a < =, > b.
     */
    public static function cmp(string|int|float $a, string|int|float $b, int $scale = self::SCALE_INTERNAL): int
    {
        return bccomp(self::n($a), self::n($b), $scale);
    }

    /** Valor absoluto. */
    public static function abs(string|int|float $a, int $scale = self::SCALE_INTERNAL): string
    {
        $n = self::n($a);
        return self::cmp($n, '0', $scale) < 0 ? bcsub('0', $n, $scale) : $n;
    }

    /** True si a >= 0. */
    public static function gte0(string|int|float $a, int $scale = self::SCALE_INTERNAL): bool
    {
        return self::cmp($a, '0', $scale) >= 0;
    }

    /** True si a > b. */
    public static function gt(string|int|float $a, string|int|float $b, int $scale = self::SCALE_INTERNAL): bool
    {
        return self::cmp($a, $b, $scale) > 0;
    }

    /** True si a <= b. */
    public static function lte(string|int|float $a, string|int|float $b, int $scale = self::SCALE_INTERNAL): bool
    {
        return self::cmp($a, $b, $scale) <= 0;
    }

    /** Aplica un porcentaje (p ej 19.0000 = 19%) a una base. */
    public static function porcentaje(string|int|float $base, string|int|float $porcentajeTarifa, int $scale = self::SCALE_INTERNAL): string
    {
        return self::mul($base, self::div($porcentajeTarifa, '100', 8), $scale);
    }
}
