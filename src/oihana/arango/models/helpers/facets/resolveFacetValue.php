<?php

namespace oihana\arango\models\helpers\facets;

use oihana\arango\models\enums\filters\FilterParam;

use function oihana\arango\db\helpers\requestAlt;

/**
 * Reads a facet value coming from the URL, letting the `{op, val, alt}` request
 * object override the operator and the `alt` chain declared in the facet
 * definition.
 *
 * A facet accepts two shapes on the wire — the bare value
 * (`?facets={"author":"alice"}`) and the object form
 * (`?facets={"author":{"op":"like","val":"al"}}`). Only the object form may
 * override the configuration, and only through the keys it actually carries:
 * the caller passes its already-resolved defaults in, and gets back the triplet
 * to keep working with.
 *
 * An **associative** array is the object form; a list is a multi-value bare
 * value (`["alice","bob"]`) and travels untouched.
 *
 * The presence of `val` is tested with `array_key_exists()`, not `isset()`, so
 * an explicit `{"op":"eq","val":null}` is honoured as a null value rather than
 * read as a missing one. An object form with **no** `val` at all cannot be
 * compared against anything: the helper returns `null` and the caller drops the
 * facet — which is why the abandon signal is a null *return*, and not a null
 * *value*.
 *
 * @param mixed $value The raw facet value from the request.
 * @param mixed $op    The operator declared by the facet definition, used unless overridden.
 * @param mixed $alt   The `alt` chain declared by the facet definition, used unless overridden.
 *
 * @return array{0:mixed,1:mixed,2:mixed}|null The `[ op , alt , value ]` triplet to
 *         carry on with, or `null` when the request object carries no `val` and
 *         the facet has nothing to compare.
 *
 * @example
 * ```php
 * use function oihana\arango\models\helpers\facets\resolveFacetValue;
 *
 * resolveFacetValue( 'alice' , 'eq' , null ) ;                              // [ 'eq' , null , 'alice' ]
 * resolveFacetValue( [ 'op' => 'like' , 'val' => 'al' ] , 'eq' , null ) ;   // [ 'like' , null , 'al' ]
 * resolveFacetValue( [ 'op' => 'like' ] , 'eq' , null ) ;                   // null — nothing to compare
 * resolveFacetValue( [ 'alice' , 'bob' ] , 'eq' , null ) ;                  // [ 'eq' , null , ['alice','bob'] ]
 * ```
 *
 * @package oihana\arango\models\helpers\facets
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
function resolveFacetValue( mixed $value , mixed $op , mixed $alt ) : ?array
{
    // {op, val, alt} request object overrides the configured operator / alt.
    if( is_array( $value ) && !array_is_list( $value ) )
    {
        $op  = $value[ FilterParam::OP  ] ?? $op ;

        // The request wins over the declaration — and its chain is marked as such, so
        // the engine binds its parameters instead of writing them into the query. The
        // declared chain is left bare: the consumer's own code may name an expression.
        if ( array_key_exists( FilterParam::ALT , $value ) )
        {
            $alt = requestAlt( $value[ FilterParam::ALT ] ) ;
        }

        if( !array_key_exists( FilterParam::VAL , $value ) )
        {
            return null ;
        }

        $value = $value[ FilterParam::VAL ] ;
    }

    return [ $op , $alt , $value ] ;
}
