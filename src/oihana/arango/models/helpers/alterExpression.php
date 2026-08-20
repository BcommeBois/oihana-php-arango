<?php

namespace oihana\arango\db\helpers;

use oihana\arango\enums\Arango;
use oihana\arango\models\enums\filters\FilterFunction;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\core\arrays\isCallableWithParams;

/**
 * Apply an `alt` transformation chain to an arbitrary AQL expression.
 *
 * Side-agnostic core shared by the key (left) and value (right) sides of a
 * comparison: it wraps `$expr` — whatever it is (a field reference `doc.name`, a
 * bind placeholder `@value`, or the loop variable `CURRENT`) — with the
 * function(s) described by `$chain`. Used directly by the filter and facet
 * builders ({@see \oihana\arango\models\traits\aql\FilterTrait},
 * {@see \oihana\arango\models\traits\aql\FacetTrait}) and by the inline-condition
 * helpers ({@see buildInlineFilterCondition()}), so there is a single implementation.
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
 * @throws ValidationException When a `pluck` sub-field name is unsafe, or when a
 *                              request-supplied chain reaches the engine with no binder.
 *
 * @example
 * ```php
 * alterExpression('doc.name', 'lower')                  // "LOWER(doc.name)"
 * alterExpression('doc.name', ['trim', 'lower'])        // "LOWER(TRIM(doc.name))"
 * alterExpression('doc.code', [ 'substring', 0, 3 ] )   // "SUBSTRING(doc.code, 0, 3)"
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function alterExpression( string $expr , mixed $chain , array $init = [] ): string
{
    // Who wrote this chain decides how its parameters reach the query. A chain marked
    // as request-supplied gets the binder handed down, so `apply()` binds its values
    // instead of pasting them into the AQL; anything else is a declaration from the
    // consumer's own code and keeps the historical passthrough.
    if ( $chain instanceof AltChain )
    {
        if ( !$chain->trusted && !is_callable( $init[ Arango::BINDER ] ?? null ) )
        {
            // Fail loud rather than fall back to interpolation: a reading point that
            // forgot to supply the binder would otherwise reopen the hole in silence.
            throw new ValidationException( 'A request-supplied alt chain reached the engine with no binder: its parameters cannot be bound.' ) ;
        }

        if ( $chain->trusted )
        {
            unset( $init[ Arango::BINDER ] ) ;
        }

        $chain = $chain->chain ;
    }
    else
    {
        unset( $init[ Arango::BINDER ] ) ; // a bare chain is a declaration — never bound
    }

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
