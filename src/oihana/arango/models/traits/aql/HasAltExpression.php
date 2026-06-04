<?php

namespace oihana\arango\models\traits\aql;

use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\resolveAltSides;

/**
 * Side-agnostic `alt` transformation engine shared by the filter and facet
 * builders.
 *
 * Both `?filter=` ({@see FilterTrait}) and `?facets=` ({@see FacetTrait}) expose
 * the same `alt` vocabulary to wrap a comparison operand with AQL functions
 * (`lower`, `trim`, `abs`, `dateDay`, …). This trait exposes, as instance
 * methods, the two primitives they share — both delegating to the free helpers
 * {@see \oihana\arango\db\helpers\alterExpression()} and
 * {@see \oihana\arango\db\helpers\resolveAltSides()}, which the inline-condition
 * helpers (array-expansion / `match`) also reuse so there is a single
 * implementation across traits and plain functions:
 *
 * - {@see static::alterExpression()} wraps any expression (a field reference, a
 *   bind placeholder, or the `CURRENT` loop variable) with a function chain;
 * - {@see static::resolveAltSides()} parses the `alt` parameter into its
 *   key-side (left) and value-side (right) chains.
 */
trait HasAltExpression
{
    /**
     * Apply an `alt` transformation chain to an arbitrary AQL expression.
     *
     * Thin instance-method wrapper over {@see \oihana\arango\db\helpers\alterExpression()}.
     *
     * @param string $expr  The expression to transform.
     * @param mixed  $chain The transformation chain (string, list of functions, or null for a no-op).
     * @param array  $init  Filter initialization array (forwarded to FilterFunction for boolean-return checks).
     *
     * @return string The transformed expression.
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException When a `pluck` sub-field name is unsafe.
     */
    protected function alterExpression( string $expr , mixed $chain , array $init = [] ): string
    {
        return alterExpression( $expr , $chain , $init ) ;
    }

    /**
     * Resolve the `alt` parameter into its key-side and value-side chains.
     *
     * Thin instance-method wrapper over {@see \oihana\arango\db\helpers\resolveAltSides()}.
     *
     * @param mixed $alt The raw `alt` parameter.
     *
     * @return array{0:mixed,1:mixed} A `[ keyChain , valChain ]` pair; either entry is null for a no-op on that side.
     */
    protected function resolveAltSides( mixed $alt ): array
    {
        return resolveAltSides( $alt ) ;
    }
}
