<?php

namespace oihana\arango\models\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Defines the possible purge directions for graph edges in ArangoDB.
 *
 * These constants indicate which documents should be automatically removed
 * when an edge vertex is deleted:
 *
 * - `OUTBOUND` : Purge the target (`to`) documents when the source (`from`) vertex is removed.
 * - `INBOUND`  : Purge the source (`from`) documents when the target (`to`) vertex is removed.
 * - `BOTH`     : Purge both `from` and `to` documents when either vertex is removed.
 *
 * These values are intended for use with edge traversal rules in graph queries
 * and edge deletion callbacks.
 *
 * @package oihana\arango\models\enums
 */
class Purge
{
    use ConstantsTrait ;

    /**
     * Purge the edges vertex documents in both directions.
     */
    public const string BOTH = 'BOTH' ;

    /**
     * Purge the source ('from') documents when a target ('to') vertex is deleted.
     */
    public const string INBOUND = 'INBOUND' ;

    /**
     * Purge the target ('to') documents when a source ('from') vertex is deleted.
     */
    public const string OUTBOUND = 'OUTBOUND' ;
}