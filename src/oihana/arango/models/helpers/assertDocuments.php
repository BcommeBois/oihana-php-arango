<?php

namespace oihana\arango\models\helpers;

use oihana\arango\models\Documents;

use UnexpectedValueException;

/**
 * Ensures that a given value is an instance of {@see Documents}.
 *
 * This runtime assertion validates type safety for `Documents`.
 * If the provided value is not an instance of {@see Documents}, an
 * {@see UnexpectedValueException} is thrown with a descriptive message.
 *
 * This is especially useful when handling dynamically typed data or container-resolved
 * dependencies, where you want to enforce strict model integrity.
 *
 * @param mixed $value The value to assert as an {@see Documents} instance.
 *
 * @throws UnexpectedValueException If the provided value is not an instance of {@see Documents}.
 *
 * @example
 * ```php
 * use oihana\arango\models\helpers\assertDocuments;
 * use oihana\arango\models\Documents;
 *
 * $documents = new Documents();
 *
 * // ✅ Valid: no exception thrown
 * assertDocuments( $documents );
 *
 * // ❌ Invalid: throws UnexpectedValueException
 * assertDocuments( 'not an edges instance' );
 * // → UnexpectedValueException: The value property must be an instance of Documents (arango).
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
function assertDocuments( mixed $value ) :void
{
    if( !( $value instanceof Documents ) )
    {
        throw new UnexpectedValueException( 'The value property must be an instance of Documents (arango).' ) ;
    }
}