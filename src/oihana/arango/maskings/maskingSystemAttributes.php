<?php

namespace oihana\arango\maskings;

/**
 * The top-level ArangoDB system attributes that are never masked.
 *
 * Mirrors the `arangodump` rule: `_key`, `_id`, `_rev`, `_from` and `_to` carry
 * identity / edge references and must survive masking untouched.
 *
 * @return array<int,string>
 *
 * @example
 * ```php
 * use function oihana\arango\maskings\maskingSystemAttributes;
 *
 * in_array( '_key' , maskingSystemAttributes() , true ); // true
 * ```
 *
 * @package oihana\arango\maskings
 * @since 1.2.0
 * @author Marc Alcaraz
 */
function maskingSystemAttributes() :array
{
    return [ '_key' , '_id' , '_rev' , '_from' , '_to' ] ;
}
