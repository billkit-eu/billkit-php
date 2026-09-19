<?php

declare(strict_types=1);

namespace BillKit;

/**
 * Keep a sub-minor-unit rate out of a float.
 *
 * ``price.unit_amount_decimal`` and a tier's ``unit_amount_decimal`` are per-unit
 * rates in **minor units** to twelve decimal places: ``'0.02'`` is 0.02 cents,
 * i.e. EUR 0.0002 per unit. The API takes them as **strings** and returns them
 * as strings, because a value like 0.0002 has no exact binary form — the moment
 * it becomes a float it is a different number, and JSON has only floats.
 *
 * PHP has no decimal type, which makes this the SDK where the mistake is
 * easiest to make: ``'unit_amount_decimal' => 0.0002`` is valid PHP, and
 * ``json_encode`` would put a JSON number on the wire. So a float is refused
 * outright rather than coerced. Coercing would work for the rates that happen
 * to round-trip and silently mis-price the ones that do not, which is the worst
 * of the three available behaviours.
 *
 * An ``int`` is accepted and stringified, because an integer is exact and
 * ``'unit_amount_decimal' => 1`` is an honest way to write one cent. Only the
 * float is a lie.
 */
final class DecimalRate
{
    /**
     * Normalise the rate fields in a price-create body, or throw.
     *
     * Returns the body with every rate rendered as a string.
     *
     * The caller's own array is never touched, and in PHP that is free rather
     * than deliberate: arrays are value types, so ``$params`` is already a copy
     * by the time this sees it. The node and python ports have to copy the tier
     * list explicitly, because there a price definition held as a module
     * constant would be rewritten in place and change what the *next* call
     * sends.
     *
     * @param array<string, mixed> $params
     *
     * @throws \InvalidArgumentException when a rate is a float
     *
     * @return array<string, mixed>
     */
    public static function normalizePriceParams(array $params): array
    {
        if (array_key_exists('unit_amount_decimal', $params)) {
            $params['unit_amount_decimal'] = self::normalize(
                $params['unit_amount_decimal'],
                'unit_amount_decimal',
            );
        }

        if (isset($params['tiers']) && is_array($params['tiers'])) {
            $tiers = [];
            foreach ($params['tiers'] as $index => $tier) {
                if (is_array($tier) && array_key_exists('unit_amount_decimal', $tier)) {
                    // Inside a band is where a rate is most likely to be typed
                    // as a bare literal, so the guard has to reach in here too.
                    $tier['unit_amount_decimal'] = self::normalize(
                        $tier['unit_amount_decimal'],
                        "tiers[{$index}].unit_amount_decimal",
                    );
                }
                $tiers[] = $tier;
            }
            $params['tiers'] = $tiers;
        }

        return $params;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function normalize(mixed $value, string $field): mixed
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be a string, not a float. A PHP float cannot hold a rate like '
                . '0.0002 exactly, so it would be corrupted before it was ever multiplied '
                . 'by a quantity. Pass it as a string: "%s".',
                $field,
                var_export($value, true),
            ));
        }

        throw new \InvalidArgumentException(sprintf(
            '%s must be a string (or an int for a whole minor unit), got %s.',
            $field,
            get_debug_type($value),
        ));
    }
}
