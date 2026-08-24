<?php

namespace oihana\arango\db\helpers;

use Exception;

use oihana\arango\enums\Arango;
use oihana\arango\exceptions\RequestValidationException;
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
 * **A name the catalogue does not carry is refused, not ignored.** Every link is
 * checked against {@see FilterFunction} before it is applied, and the refusal names
 * the offending code — never a fragment of the query. Who wrote the chain decides
 * which refusal: a request can fix its own URL, so it gets a `ValidationException`
 * carrying `400`; a model declaration cannot be fixed from the wire, so it gets an
 * {@see UnsupportedOperationException}, which surfaces as a `500` — the consumer's
 * code is what has to change.
 *
 * ⚠ **One position cannot be checked**, by construction. In `["trim","lowr","lower"]`
 * the second element is read as a *parameter* of `trim` — exactly as it is in the
 * legitimate `["trim","-"]` ("strip dashes"). The two notations are indistinguishable
 * there, so a name mistyped in that position stays silent: it becomes a parameter and
 * the rest of the chain is dropped. Write such a chain with each link nested —
 * `["trim",["substring",0,3],"lower"]` — and every link is checked again.
 *
 * @param string $expr The expression to transform.
 * @param mixed $chain The transformation chain (string, list of functions, or null for a no-op).
 * @param array $init Filter initialization array (forwarded to FilterFunction for boolean-return checks).
 *
 * @return string The transformed expression.
 *
 * @throws UnsupportedOperationException When a **declared** chain names a function the catalogue does not carry.
 * @throws ValidationException           When a `pluck` sub-field name is unsafe, when a request-supplied chain reaches the engine with no binder, or when a **request** chain names a function the catalogue does not carry.
 * @throws Exception
 *
 *
 * @example
 * ```php
 * alterExpression('doc.name', 'lower')                  // "LOWER(doc.name)"
 * alterExpression('doc.name', ['trim', 'lower'])        // "LOWER(TRIM(doc.name))"
 * alterExpression('doc.code', [ 'substring', 0, 3 ] )   // "SUBSTRING(doc.code, 0, 3)"
 * alterExpression('doc.name', 'lowr')                   // throws — "lowr" is not a function
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function alterExpression( string $expr , mixed $chain , array $init = [] ): string
{
    // Who wrote this chain decides two things: how its parameters reach the query, and
    // who is told when a name is wrong. A chain marked as request-supplied gets the
    // binder handed down, so `apply()` binds its values instead of pasting them into
    // the AQL; anything else is a declaration from the consumer's own code and keeps
    // the historical passthrough.
    $trusted = true ;

    if ( $chain instanceof AltChain )
    {
        $trusted = $chain->trusted ;

        if ( !$trusted && !is_callable( $init[ Arango::BINDER ] ?? null ) )
        {
            // Fail loud rather than fall back to interpolation: a reading point that
            // forgot to supply the binder would otherwise reopen the hole in silence.
            throw new ValidationException( 'A request-supplied alt chain reached the engine with no binder: its parameters cannot be bound.' ) ;
        }

        if ( $trusted )
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

    // The same fault, told to whoever can act on it. The message is handed back to the
    // caller verbatim by the reading layer, so it carries the refused code and nothing
    // else — no query fragment, no field name it did not already send.
    $refuse = static fn( string $message ) :Exception => $trusted
            ? new UnsupportedOperationException( 'alterExpression failed, ' . $message )
            : new RequestValidationException( ucfirst( $message ) ) ;

    $catalogue = FilterFunction::enums() ;

    $assertFunction = static function( mixed $name ) use ( $catalogue , $refuse ) :string
    {
        if ( !is_string( $name ) || !in_array( $name , $catalogue , true ) )
        {
            throw $refuse( sprintf( 'the alt function "%s" is not supported.' , is_string( $name ) ? $name : get_debug_type( $name ) ) ) ;
        }

        return $name ;
    } ;

    if ( !is_string( $chain ) && !is_array( $chain ) )
    {
        throw $refuse( sprintf( 'an alt chain must be a function name or a list of function names, "%s" given.' , get_debug_type( $chain ) ) ) ;
    }

    // Case 1: Single function without params → "lower"
    if ( is_string( $chain ) )
    {
        return FilterFunction::apply( $assertFunction( $chain ) , $expr , [] , $init );
    }

    // An empty list asks for a transformation and names none. Sending no `alt` at all
    // is how a caller asks for nothing — an empty one is a mistake upstream.
    if ( $chain === [] )
    {
        throw $refuse( 'an alt chain must name at least one function.' ) ;
    }

    // Case 2: single function with params (simplified syntax) → ['substring', 0, 3]
    // `isCallableWithParams()` only answers true when the head is a known name, so the
    // head needs no further check here — and the tail is parameters, not links.
    if ( isCallableWithParams( $chain , $catalogue ) )
    {
        return FilterFunction::apply( $chain[ 0 ] , $expr , array_slice( $chain , 1 ) , $init ) ;
    }

    // Case 3-4: a chain, plain or mixing parameterized links.
    // Examples: ['trim', 'lower'] or ['trim', ['substring', 0, 3], 'lower']
    foreach ( $chain as $func )
    {
        if ( is_array( $func ) )
        {
            // Function with explicit params: ['substring', 0, 3]
            $funcName = $assertFunction( $func[ 0 ] ?? null ) ;
            $params   = array_slice( $func , 1 ) ;
        }
        else
        {
            // Function without params: 'lower'
            $funcName = $assertFunction( $func ) ;
            $params   = [] ;
        }

        $expr = FilterFunction::apply( $funcName , $expr , $params , $init ) ;
    }

    return $expr ;
}
