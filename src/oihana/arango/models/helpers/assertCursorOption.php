<?php

namespace oihana\arango\models\helpers;

use oihana\arango\clients\cursor\enums\CursorField;
use oihana\exceptions\ValidationException;

/**
 * Refuses a cursor option a caller may not name through `Arango::CURSOR_OPTIONS`, or a
 * value of the wrong type.
 *
 * Five options are a caller's : `batchSize`, `memoryLimit` and `ttl` (positive integers),
 * `maxRuntime` (a positive number) and `stream` (a boolean). Every refusal is ours, since
 * the server says nothing — an option it does not know and a value of the wrong type both
 * run as if absent (measured on ArangoDB 3.12). A few names are refused with their reason :
 * `query` / `bindVars` would replace the query the model builds, guards included ;
 * `fullCount` is decided by the read from its `LIMIT` ; `profile` goes through
 * `Arango::PROFILE` ; `count` is handed back by no read.
 *
 * @param int|string $name  The option name.
 * @param mixed      $value The option value.
 *
 * @return void
 *
 * @throws ValidationException When the option is not one of the five, or its value is not
 *                             of the expected type.
 *
 * @example
 * ```php
 * use oihana\arango\clients\cursor\enums\CursorField;
 * use function oihana\arango\models\helpers\assertCursorOption;
 *
 * assertCursorOption( CursorField::BATCH_SIZE , 1000 ) ; // ✅ nothing thrown
 *
 * assertCursorOption( 'batchsize' , 1000 ) ;
 * // ❌ ValidationException: Unknown cursor option "batchsize" — expected one of: batchSize, maxRuntime, memoryLimit, stream, ttl.
 *
 * assertCursorOption( CursorField::TTL , -5 ) ;
 * // ❌ ValidationException: The cursor option "ttl" must be a positive integer, int given.
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models\helpers
 * @version 1.0.0
 */
function assertCursorOption( int|string $name , mixed $value ) :void
{
    $expected = match ( $name )
    {
        CursorField::BATCH_SIZE ,
        CursorField::MEMORY_LIMIT ,
        CursorField::TTL         => is_int( $value ) && $value > 0 ? null : 'a positive integer' ,
        CursorField::MAX_RUNTIME => ( is_int( $value ) || is_float( $value ) ) && $value > 0 ? null : 'a positive number' ,
        CursorField::STREAM      => is_bool( $value ) ? null : 'a boolean' ,
        CursorField::QUERY ,
        CursorField::BIND_VARS   => throw new ValidationException( 'The cursor option "' . $name . '" is refused: the query and its binds are the ones the model builds.' ) ,
        CursorField::FULL_COUNT  => throw new ValidationException( 'The cursor option "fullCount" is refused: the read decides it, from its LIMIT.' ) ,
        CursorField::PROFILE     => throw new ValidationException( 'The cursor option "profile" is refused: ask for a profile with Arango::PROFILE.' ) ,
        CursorField::COUNT       => throw new ValidationException( 'The cursor option "count" is refused: no read of the model hands the cursor count back.' ) ,
        default                  => throw new ValidationException
        (
            'Unknown cursor option "' . $name . '" — expected one of: batchSize, maxRuntime, memoryLimit, stream, ttl.'
        ) ,
    } ;

    if ( $expected !== null )
    {
        throw new ValidationException( 'The cursor option "' . $name . '" must be ' . $expected . ', ' . get_debug_type( $value ) . ' given.' ) ;
    }
}
