<?php

namespace oihana\arango\helpers;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Decompose the specified revision string into its components.
 * The resulting object has a date and a count attribute.
 * This function is supposed to be called with the _rev attribute value of a database document as argument.
 *
 * Decodes an ArangoDB revision string (_rev) into a timestamp and a counter.
 *
 * This function implements the C++ algorithm used by ArangoDB to decode the `_rev` string
 * into a 64-bit integer, then splits it into a timestamp (milliseconds since epoch) and a counter.
 * The result is returned as an associative array with 'date' (ISO 8601 format) and 'count' (integer).
 *
 * @param string|null $revision The ArangoDB revision string (e.g., "_jGPSg12---").
 *                              Must be 10 or 11 characters long, start with '_', and contain at least one letter.
 * @param bool $throwable If true, throws exceptions on error. If false, returns null on error.
 *
 * @return array|null Returns an associative array with 'date' and 'count' keys on success, or null on failure.
 *                    Example return value: `['date' => '2025-01-20T15:28:40.830Z', 'count' => 0]`.
 *                    Returns null if the input is invalid or if the GMP extension is not loaded.
 *
 * @throws InvalidArgumentException If $revision is null or empty and $throwable is true.
 * @throws RuntimeException If $revision is invalid (wrong length, format, or content) and $throwable is true,
 *                          or if the GMP extension is not loaded and $throwable is true.
 *
 * @example
 * Example usage:
 * ```php
 * $revision = '_jGPSg12---';
 * $result = decodeRevision($revision);
 * // $result = ['date' => '2025-01-20T15:28:40.830Z', 'count' => 0]
 * ```
 *
 * Example with invalid revision (returns null or throws exception):
 * ```php
 * $result = decodeRevision('_1234567890', true); // Throws RuntimeException
 * $result = decodeRevision('_1234567890');      // Returns null
 * ```
 *
 * @see https://docs.arangodb.com/3.11/aql/functions/miscellaneous/#decode_rev
 * @see encodeRevision() For encoding a `_rev` string with a specific date and an optional count value.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
function decodeRevision( ?string $revision , bool $throwable = false ) :?array
{
    // 0. Input validation

    if ( empty( $revision ) )
    {
        if ( $throwable )
        {
            throw new InvalidArgumentException('decodeRevision() failed, the input expression is not a string.');
        }
        return null;
    }

    $len = strlen( $revision ) ;
    if ($len < 10 || $len > 11)
    {
        if ($throwable)
        {
            throw new RuntimeException(sprintf('decodeRevision("%s") failed, invalid revision length.', $revision));
        }
        return null;
    }

    if ( $revision[0] !== '_' )
    {
        if ( $throwable )
        {
            throw new RuntimeException(sprintf('decodeRevision("%s") failed, invalid revision format.', $revision));
        }
        return null;
    }

    if ( preg_match('/^[_0-9-]+$/', $revision) && !preg_match('/[A-Za-z]/', $revision ) )
    {
        if ($throwable)
        {
            throw new RuntimeException(sprintf('decodeRevision("%s") failed, invalid revision content.', $revision));
        }
        return null;
    }

    // 1. GMP extension check
    // gmp is a hard requirement loaded in every supported runtime; this guard cannot be exercised in tests.
    // @codeCoverageIgnoreStart
    if ( !extension_loaded('gmp' ) )
    {
        if ($throwable)
        {
            throw new RuntimeException('decodeRevision() requires the "gmp" PHP extension.') ;
        }
        return null ;
    }
    // @codeCoverageIgnoreEnd

    // 2. Build the custom ArangoDB decode table (static for performance)
    static $decodeTable = null ;
    if ( $decodeTable === null )
    {
        $decodeTable = array_fill(0, 256, -1) ;
        $decodeTable[ ord('-') ] = 0 ;
        $decodeTable[ ord('_') ] = 1 ;
        foreach (range('A', 'Z') as $i => $char) { $decodeTable[ord($char)] = $i + 2; }
        foreach (range('a', 'z') as $i => $char) { $decodeTable[ord($char)] = $i + 28; }
        foreach (range('0', '9') as $i => $char) { $decodeTable[ord($char)] = $i + 54; }
    }

    // 3. Decode the string into a 64-bit unsigned integer using GMP
    // This implements the C++ loop: r = (r << 6) | c;
    $hlc_gmp = gmp_init(0  ) ;
    $gmp_64  = gmp_init(64 ) ; // pour (r << 6)

    for ( $i = 0 ; $i < $len; $i++ )
    {
        $charVal = $decodeTable[ ord( $revision[$i] ) ] ;

        if ($charVal === -1)
        {
            if ($throwable)
            {
                throw new RuntimeException(sprintf('decodeRevision("%s") failed, invalid character.', $revision ) ) ;
            }
            return null ; // Invalid character
        }

        // $hlc_gmp = ($hlc_gmp * 64) + $charVal;
        $hlc_gmp = gmp_add(gmp_mul($hlc_gmp, $gmp_64), $charVal);
    }

    // 4. Extract timestamp and counter using 44/20 split (from C++ source)
    // 'J' = unsigned long long (64-bit), big-endian byte order

    // We must use GMP for bitwise operations to prevent signed 64-bit overflow
    $gmp_pow_20  = gmp_init('1048576' ) ; // 2^20
    $gmp_mask_20 = gmp_init('1048575' ) ; // 0xfffff

    // $timestamp_ms = $hlc >> 20
    $timestamp_gmp = gmp_div_q($hlc_gmp, $gmp_pow_20);

    // $count = $hlc & 0xfffff
    $count_gmp = gmp_and($hlc_gmp, $gmp_mask_20);

    // Values are now small enough to safely convert to standard PHP int
    $timestamp_ms = (int) gmp_strval( $timestamp_gmp ) ;
    $count        = (int) gmp_strval( $count_gmp     ) ;

    // 5. Format the date as ISO 8601
    try
    {
        $seconds      = (int) floor($timestamp_ms / 1000) ;
        $milliseconds = $timestamp_ms % 1000 ;

        $dt = new DateTimeImmutable("@" . $seconds) ;
        $dt = $dt->setTimezone( new DateTimeZone('UTC') ) ;

        $dateString = sprintf
        (
            '%s.%03dZ',
            $dt->format('Y-m-d\TH:i:s'),
            $milliseconds
        );
    }
    // the timestamp comes from a bounded 44-bit GMP decode, so DateTimeImmutable never throws for a valid 10–11 char revision; this catch is unreachable.
    // @codeCoverageIgnoreStart
    catch ( Exception )
    {
        if( $throwable )
        {
            throw new RuntimeException(sprintf('decodeRevision("%s") failed to create date.', $revision));
        }
        return null ;
    }
    // @codeCoverageIgnoreEnd

    // 6. Return in the same format as AQL
    return
    [
        'date'  => $dateString ,
        'count' => $count ,
    ];
}