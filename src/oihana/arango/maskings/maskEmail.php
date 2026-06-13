<?php

namespace oihana\arango\maskings;

use Random\RandomException;

/**
 * Replaces a value with a random, non-routable email address.
 *
 * Mirrors the `arangodump` `email` masker: the result has the shape
 * `AAAA.BBBB@CCCC.invalid` with random parts. The original value is never
 * reflected in the output (the content is replaced wholesale, not hashed
 * reversibly), so it is safe for anonymization. The `.invalid` TLD is reserved
 * (RFC 2606) and never resolves.
 *
 * @param mixed $value The original value (ignored — replaced wholesale).
 * @return string The anonymized email address.
 *
 * @throws RandomException
 *
 * @example
 * ```php
 * use function oihana\arango\maskings\maskEmail;
 *
 * maskEmail( 'real.person@example.com' ); // e.g. "x7Bq.9aMz@Kp3R.invalid"
 * ```
 *
 * @package oihana\arango\maskings
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function maskEmail( mixed $value = null ) :string
{
    return sprintf( '%s.%s@%s.invalid' , randomAlphaNumeric( 4 ) , randomAlphaNumeric( 4 ) , randomAlphaNumeric( 4 ) ) ;
}
