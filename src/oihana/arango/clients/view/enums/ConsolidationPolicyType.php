<?php

namespace oihana\arango\clients\view\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Discriminator (`type` field) of the `consolidationPolicy` object that
 * drives how an ArangoSearch view merges its index segments.
 *
 * @see https://docs.arangodb.com/stable/index-and-search/arangosearch/arangosearch-views-reference/#view-properties
 *
 * @package oihana\arango\clients\view\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class ConsolidationPolicyType
{
    use ConstantsTrait ;

    /**
     * Consolidate by accumulated byte size — merges segments once the
     * combined size of consolidation candidates crosses a threshold.
     */
    public const string BYTES_ACCUM = 'bytes_accum' ;

    /**
     * Tiered policy — groups segments into size tiers and merges within
     * a tier. Server default.
     */
    public const string TIER = 'tier' ;
}
