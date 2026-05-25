<?php

namespace oihana\arango\db\helpers;

/**
 * Checks if a value is a string matching the ArangoDB Document ID format (e.g., "collection/key").
 *
 * This function validates the format, not whether the document actually exists.
 * It specifically checks for a string containing exactly one '/' separator,
 * with characters on both sides.
 *
 * @example
 * ```php
 * var_dump( isAQLId( 'users/12345'          ) ); // bool(true)
 * var_dump( isAQLId( 'my_collection/my-key' ) ); // bool(true)
 * var_dump( isAQLId( 'users'                ) ); // bool(false)
 * var_dump( isAQLId( 'users/'               ) ); // bool(false)
 * var_dump( isAQLId( '/12345'               ) ); // bool(false)
 * var_dump( isAQLId( 'users/123/abc'        ) ); // bool(false)
 * var_dump( isAQLId( '@startVertex'         ) ); // bool(false)
 * var_dump( isAQLId( 12345                  ) ); // bool(false)
 * var_dump( isAQLId( null                   ) ); // bool(false)
 * ```
 *
 * @param mixed $value The value to check.
 *
 * @return bool True if the value is a string in "collection/key" format, false otherwise.
 */
function isAQLId( mixed $value ): bool
{
    if ( ! is_string( $value ) )
    {
        return false ;
    }

    // Regex: Must start (^) with one or more non-slash chars [^/]+
    // followed by exactly one slash \/
    // followed by one or more non-slash chars [^/]+
    // and then end ($).
    return (bool) preg_match( '/^[^\\/]+\\/[^\\/]+$/', $value );
}