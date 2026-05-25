<?php

namespace oihana\arango\clients\aql ;

/**
 * Marker value for AQL fragments that must be inlined verbatim into a
 * query string instead of being bound as parameters.
 *
 * Use this for AQL keywords / function names that cannot be parameterised
 * (`ASC`, `DESC`, function identifiers, attribute names received from a
 * server-side whitelist, …). The value MUST come from a trusted source —
 * never from raw user input — since it bypasses the safe binding layer
 * and is interpolated as-is into the final query string.
 *
 * Example:
 * ```php
 * $direction = $userInput === 'desc' ? 'DESC' : 'ASC' ; // whitelisted upstream
 *
 * $cursor = $db->query
 * (
 *     aql( 'FOR u IN users SORT u.name ? RETURN u' , aqlLiteral( $direction ) )
 * ) ;
 * ```
 *
 * @see aqlLiteral() for the function-style helper.
 *
 * @package oihana\arango\clients\aql
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class AqlLiteral
{
    /**
     * @param string $value Raw AQL fragment to inline verbatim into a query.
     */
    public function __construct( public string $value ) {}

    /**
     * Returns the literal value, so a `AqlLiteral` may be safely interpolated
     * in a string context (e.g. within a `sprintf()` call).
     *
     * @return string
     */
    public function __toString() : string
    {
        return $this->value ;
    }
}
