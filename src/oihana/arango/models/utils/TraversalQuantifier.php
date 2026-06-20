<?php

namespace oihana\arango\models\utils;

/**
 * The resolved form of a `quant` quantifier applied to an edge/join traversal.
 *
 * Where the array surface resolves `quant` into an AQL quantifier keyword
 * (`ANY` / `ALL` / `NONE` / `AT LEAST (n)`), a relation traversal is an existence
 * check shaped as `LENGTH( FOR … RETURN 1 ) <comparator> <threshold>`. This value
 * object carries the four decisions that shape that predicate:
 *
 * - `comparator` — the comparison applied to the `LENGTH(...)` count (`>`, `==`, `>=`);
 * - `threshold`  — the right-hand operand (`0` for existence/absence, `n` for « at least n »);
 * - `useLimit`   — whether a `LIMIT 1` short-circuit can be emitted (existence/absence)
 *   or must be dropped because the rows have to be counted (« at least n »);
 * - `negate`     — whether the leaf condition must be negated (`all` → « no linked
 *   vertex violates the condition »).
 *
 * @package oihana\arango\models\utils
 * @since   1.4.0
 * @author  Marc Alcaraz
 */
final readonly class TraversalQuantifier
{
    /**
     * Creates a new TraversalQuantifier instance.
     *
     * @param string $comparator The comparison applied to the `LENGTH(...)` count.
     * @param int    $threshold  The right-hand operand of the comparison.
     * @param bool   $useLimit   Whether a `LIMIT 1` short-circuit may be emitted.
     * @param bool   $negate     Whether the leaf condition must be negated.
     */
    public function __construct
    (
        public string $comparator ,
        public int    $threshold  ,
        public bool   $useLimit   ,
        public bool   $negate     ,
    ) {}
}
