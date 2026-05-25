<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Represents the possible traversal directions for graph edges in ArangoDB.
 *
 * This enum defines three constants that indicate the direction to follow
 * during a graph traversal:
 *
 * - `OUTBOUND` : Follow edges from the starting vertex to the connected vertices.
 * - `INBOUND`  : Follow edges from connected vertices to the starting vertex.
 * - `ANY`      : Follow edges in both directions.
 *
 * These values are used when defining edge traversal rules in graph queries.
 * Note: These constants cannot be substituted by bind parameters in AQL queries.
 *
 * @package oihana\arango\db\enums
 */
class Traversal
{
    use ConstantsTrait ;

    /**
     * Follow edges in both directions.
     */
    public const string ANY = 'ANY' ;

    /**
     * Follow edges from connected vertices to the starting vertex.
     */
    public const string INBOUND = 'INBOUND' ;

    /**
     * Follow edges from the starting vertex to the connected vertices.
     */
    public const string OUTBOUND = 'OUTBOUND' ;
}