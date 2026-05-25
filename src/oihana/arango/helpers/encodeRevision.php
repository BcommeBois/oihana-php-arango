<?php

namespace oihana\arango\helpers;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Encodes a date into an ArangoDB `_rev` (revision) string.
 *
 * This function is the inverse of `decodeRevision()`. It generates a 10–11 character `_rev`
 * string from a given date and an optional counter (`count`). The `_rev` string encodes
 * a 64-bit integer composed of:
 *
 * 1. A 44-bit timestamp in milliseconds since epoch (UTC).
 * 2. A 20-bit counter (`count`) to ensure uniqueness for multiple revisions at the same millisecond.
 *
 * If `$count` is `null`, the function automatically increments the counter for multiple
 * calls within the same millisecond, mimicking ArangoDB's internal behavior.
 *
 * @param string $date The date to encode, in any format accepted by `DateTimeImmutable`.
 *                     Must be UTC or will be converted to UTC.
 * @param int|null $count Optional counter for revisions within the same millisecond.
 *                        If null, automatically incremented for multiple calls in the same millisecond.
 *                        Must be between 0 and 1048575 (2^20 - 1).
 * @param bool $throwable If true, throws exceptions on error; if false, returns an empty string.
 *
 * @return string Returns the encoded `_rev` string (10–11 characters).
 * Returns an empty string on error if `$throwable` is false.
 *
 * @throws RuntimeException         If the GMP extension is not loaded and `$throwable` is true.
 * @throws InvalidArgumentException If the date is invalid or `$count` is out of range and `$throwable` is true.
 *
 * @example
 * Example usage with explicit count:
 * ```php
 * $date = '2025-01-20T15:28:40.830Z';
 * $count = 0;
 * $rev = encodeRevision($date, $count);
 * echo $rev; // e.g. "_jGPSg12---"
 * ```
 *
 * Example usage with automatic count:
 * ```php
 * $date = '2025-10-25T12:00:00.000Z';
 * $rev1 = encodeRevision($date);
 * $rev2 = encodeRevision($date); // automatically increments count
 * $rev3 = encodeRevision($date); // increments again
 * ```
 *
 * @see https://docs.arangodb.com/3.11/aql/functions/miscellaneous/#decode_rev
 * @see decodeRevision() For decoding a `_rev` string back to date and count.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
function encodeRevision( string $date , ?int $count = null, bool $throwable = false ): string
{
    if (!extension_loaded('gmp'))
    {
        if ( $throwable )
        {
            throw new RuntimeException('encodeRevision() requires the "gmp" PHP extension.');
        }
        return '' ;
    }

    // 1. Convert date to milliseconds
    try
    {
        $dt = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        $timestamp_ms = ((int) $dt->format('U')) * 1000 + (int) ( $dt->format('v' ) ?? 0 ) ;
    }
    catch ( Exception )
    {
        if ($throwable)
        {
            throw new InvalidArgumentException( sprintf('encodeRevision() failed to parse date "%s".' , $date ) );
        }
        return '';
    }

    // 2. Handle count with automatic increment
    static $lastTimestamp = null ;
    static $lastCount     = -1   ;

    if ( $count === null )
    {
        if ( $lastTimestamp === $timestamp_ms )
        {
            $lastCount++ ;
            if ( $lastCount > 1048575 ) // 2^20 - 1
            {
                $lastCount = 0 ; // wrap around
            }
        }
        else
        {
            $lastTimestamp = $timestamp_ms ;
            $lastCount     = 0 ;
        }
        $count = $lastCount ;
    }

    // Validate count
    if ( $count < 0 || $count > 1048575 )
    {
        if ($throwable)
        {
            throw new InvalidArgumentException('encodeRevision() count must be between 0 and 1048575.') ;
        }
        return '' ;
    }

    // 3. Combine timestamp and count into 64-bit integer
    $hlc = gmp_add( gmp_mul( gmp_init( $timestamp_ms ) , gmp_init(1048576 ) ), gmp_init($count) ) ;

    // 4. Build the ArangoDB encode table
    static $encodeTable = null;
    if ( $encodeTable === null )
    {
        $encodeTable = array_merge(['-', '_'], range('A', 'Z'), range('a', 'z'), range('0', '9')) ;
    }

    // 5. Encode integer to 11-character string (big-endian base64)
    $rev = '' ;
    for ( $i = 0; $i < 11; $i++ )
    {
        $rem = gmp_mod($hlc, gmp_init(64)) ;
        $rev = $encodeTable[ gmp_intval($rem) ] . $rev ;
        $hlc = gmp_div_q( $hlc , gmp_init(64) ) ;
    }

    return $rev;
}