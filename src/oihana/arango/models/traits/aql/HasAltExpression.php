<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\models\enums\filters\FilterFunction;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\core\arrays\isCallableWithParams;

/**
 * Side-agnostic `alt` transformation engine shared by the filter and facet
 * builders.
 *
 * Both `?filter=` ({@see FilterTrait}) and `?facets=` ({@see FacetTrait}) expose
 * the same `alt` vocabulary to wrap a comparison operand with AQL functions
 * (`lower`, `trim`, `abs`, `dateDay`, …). This trait holds the two primitives
 * they share so there is a single implementation:
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
     * This is the side-agnostic core shared by the key (left) and value (right)
     * sides of a comparison: it wraps `$expr` — whatever it is (a field reference
     * `doc.name`, a bind placeholder `@value`, or the loop variable `CURRENT`) —
     * with the function(s) described by `$chain`.
     *
     * Supports multiple syntax formats for `$chain`:
     * 1. Single function: "lower"
     * → LOWER(expr)
     *
     * 2. Function with params (simplified): ["substring", 0, 3]
     * → SUBSTRING(expr, 0, 3)
     *
     * 3. Function chain: ["trim","lower"]
     * → LOWER(TRIM(expr))
     *
     * 4. Mixed chain: ["trim",["substring",0,3],"lower"]
     * → LOWER(SUBSTRING(TRIM(expr), 0, 3))
     *
     * @param string $expr  The expression to transform.
     * @param mixed  $chain The transformation chain (string, list of functions, or null for a no-op).
     * @param array  $init  Filter initialization array (forwarded to FilterFunction for boolean-return checks).
     *
     * @return string The transformed expression.
     *
     * @throws UnsupportedOperationException
     *
     * @example
     * ```php
     * // Single function
     * alterExpression('doc.name', 'lower')
     * // Returns: "LOWER(doc.name)"
     *
     * // Function chain
     * alterExpression('doc.name', ['trim', 'lower'])
     * // Returns: "LOWER(TRIM(doc.name))"
     *
     * // With parameters
     * alterExpression('doc.code', [ 'substring', 0, 3 ] )
     * // Returns: "SUBSTRING(doc.code, 0, 3)"
     *
     * alterExpression('doc.code', [ 'trim' , ['substring', 0, 3] ] )
     * // Returns: "SUBSTRING(TRIM(doc.code), 0, 3)"
     * ```
     */
    protected function alterExpression( string $expr , mixed $chain , array $init = [] ): string
    {
        if ( $chain === null )
        {
            return $expr ;
        }

        // Case 1: Single function without params → "lower"
        if ( is_string( $chain ) )
        {
            return FilterFunction::apply( $chain , $expr , [] , $init );
        }

        // Case 2-4: Array format
        if ( is_array( $chain ) )
        {
            // Detect if it's a single function with params (simplified syntax)
            // Example: ['substring', 0, 3]
            if ( isCallableWithParams( $chain , FilterFunction::enums() ) )
            {
                // Extract function name and params
                $funcName = $chain[0];
                $params   = array_slice( $chain , 1 ) ;

                return FilterFunction::apply( $funcName , $expr , $params , $init );
            }

            // Otherwise, it's a function chain
            // Examples: ['trim', 'lower'] or ['trim', ['substring', 0, 3], 'lower']
            foreach ( $chain as $func )
            {
                if ( is_array( $func ) )
                {
                    // Function with explicit params: ['substring', 0, 3]
                    $funcName = $func[0];
                    $params   = array_slice( $func , 1 );
                }
                else
                {
                    // Function without params: 'lower'
                    $funcName = $func;
                    $params   = [];
                }

                $expr = FilterFunction::apply( $funcName , $expr , $params , $init );
            }

            return $expr;
        }

        // Fallback: return expression unchanged
        return $expr ;
    }

    /**
     * Resolve the `alt` parameter into its key-side and value-side chains.
     *
     * Three backward-compatible forms are supported:
     * - `"lower"` / `["trim","lower"]` (string or list) → key side only, the value is left untouched.
     * - `{ "key":<chain>, "val":<chain> }` (object) → explicit chain per side.
     * - `{ "key":<chain>, "val":true }` → `val:true` mirrors the key-side chain onto the value side.
     *
     * The object form is told apart from a plain function chain by being an
     * associative array (a list is a function chain, an associative array is the
     * per-side object).
     *
     * @param mixed $alt The raw `alt` parameter.
     *
     * @return array{0:mixed,1:mixed} A `[ keyChain , valChain ]` pair; either entry is null for a no-op on that side.
     */
    protected function resolveAltSides( mixed $alt ): array
    {
        if ( $alt === null )
        {
            return [ null , null ] ;
        }

        // Object form { key:<chain>, val:<chain|true> } — an associative array, as
        // opposed to a plain function chain (a list).
        if ( is_array( $alt ) && !array_is_list( $alt ) )
        {
            $keyChain = $alt[ FilterParam::KEY ] ?? null ;
            $valChain = $alt[ FilterParam::VAL ] ?? null ;

            // val:true → mirror the key-side chain onto the value side.
            if ( $valChain === true )
            {
                $valChain = $keyChain ;
            }

            return [ $keyChain , $valChain ] ;
        }

        // String or list form → key side only, value untouched.
        return [ $alt , null ] ;
    }
}
