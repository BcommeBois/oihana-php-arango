<?php

namespace oihana\arango\models\helpers\edges;

use oihana\arango\models\Edges;

use UnexpectedValueException;

/**
 * Ensures that a given value is an instance of {@see Edges}.
 *
 * This helper function acts as a runtime assertion to validate type safety.
 * If the provided value is not an instance of `Edges`, an {@see UnexpectedValueException}
 * is thrown with a descriptive message.
 *
 * This is especially useful when handling dynamically typed data or container-resolved
 * dependencies, where you want to enforce strict model integrity.
 *
 * @param mixed $value  The value to assert as an {@see Edges} instance.
 *
 * @throws UnexpectedValueException If the provided value is not an instance of {@see Edges}.
 *
 * @example
 * ```php
 * use oihana\arango\models\helpers\assertEdges;
 * use oihana\arango\models\Edges;
 *
 * $edges = new Edges();
 *
 * // ✅ Valid: no exception thrown
 * assertEdges( $edges );
 *
 * // ❌ Invalid: throws UnexpectedValueException
 * assertEdges( 'not an edges instance' );
 * // → UnexpectedValueException: The value property must be an instance of Edges.
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
function assertEdges( mixed $value ) :void
{
    if( !( $value instanceof Edges ) )
    {
        throw new UnexpectedValueException( 'The value property must be an instance of Edges.' ) ;
    }
}