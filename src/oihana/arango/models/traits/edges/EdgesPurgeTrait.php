<?php

namespace oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Purge;

/**
 * Trait to manage automatic purge rules for edges in ArangoDB.
 *
 * This trait allows an edge collection to define which connected documents
 * should be automatically deleted when a vertex is removed. The purge
 * direction is controlled using the {@see Purge} enum:
 *
 * - `Purge::OUTBOUND` : Purge the target ('to') documents when a source ('from') vertex is deleted.
 * - `Purge::INBOUND`  : Purge the source ('from') documents when a target ('to') vertex is deleted.
 * - `Purge::BOTH`     : Purge both 'from' and 'to' documents when either vertex is deleted.
 *
 * Example usage:
 * ```php
 * $edges->initializePurge([AQL::PURGE => Purge::OUTBOUND]);
 * ```
 *
 * @package oihana\arango\models\traits\edges
 * @version 1.0.0
 */
trait EdgesPurgeTrait
{
    /**
     * The purge mode for this edge collection.
     * @var string|null One of the constants defined in {@see Purge} or null if no purge is configured.
     */
    public string|null $purge
    {
        get => $this->_purge ;
        set
        {
            $this->_purge = match( $value )
            {
                Purge::OUTBOUND ,
                Purge::INBOUND  ,
                Purge::BOTH      => $value ,
                default          => null
            };
        }
    }

    /**
     * Initialize the purge property from an array or string.
     *
     * @param array|string|null $init If array, looks for the key AQL::PURGE; if string, directly sets the purge mode.
     *
     * @return static
     */
    public function initializePurge( array|string|null $init = null ):static
    {
        if( is_array( $init ) )
        {
            $init = $init[ AQL::PURGE ] ?? null ;
        }

        $this->purge = is_string( $init ) ? $init : null ;

        return $this ;
    }

    /**
     * @var string|null
     */
    private string|null $_purge = null ;
}