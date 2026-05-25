<?php

namespace oihana\arango\clients\graph ;

/**
 * Immutable value object describing one edge definition inside a named graph.
 *
 * An edge definition tells ArangoDB that the edge collection
 * {@see $collection} connects documents stored in any of the
 * {@see $from} vertex collections to documents stored in any of the
 * {@see $to} vertex collections. The server uses this constraint to
 * reject inconsistent `_from` / `_to` values when an edge is created
 * or updated through the gharial endpoints (`/_api/gharial/{graph}/edge/...`).
 *
 * `$from` and `$to` are typed as lists of collection names; either
 * can be empty (the gharial endpoint will reject an empty list at
 * `addEdgeDefinition()` time — we don't pre-validate to keep the VO
 * dumb on purpose).
 *
 * Construct directly or via the static {@see fromArray()} factory
 * when consuming a `GET /_api/gharial/{name}` response.
 *
 * Example:
 * ```php
 * $employs = new EdgeDefinition
 * (
 *     collection : 'employs' ,
 *     from       : [ 'companies' ] ,
 *     to         : [ 'people' ] ,
 * ) ;
 *
 * $db->createGraph( 'workplaces' , [ $employs ] ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/graphs/named-graphs/#manage-edge-definitions
 *
 * @package oihana\arango\clients\graph
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class EdgeDefinition
{
    /**
     * @param string             $collection Name of the edge collection.
     * @param array<int, string> $from       Vertex collections allowed as `_from` source.
     * @param array<int, string> $to         Vertex collections allowed as `_to` target.
     */
    public function __construct( public string $collection , public array $from , public array $to ) {}

    /**
     * Wire field carrying the edge collection name.
     */
    public const string COLLECTION = 'collection' ;

    /**
     * Wire field carrying the list of allowed `_from` vertex collections.
     */
    public const string FROM = 'from' ;

    /**
     * Wire field carrying the list of allowed `_to` vertex collections.
     */
    public const string TO = 'to' ;

    /**
     * Builds an {@see EdgeDefinition} from a wire object as returned by
     * `GET /_api/gharial/{name}` under `edgeDefinitions`.
     *
     * Missing or non-array `from` / `to` fields fall back to an empty
     * list; a missing collection name yields an empty string (server
     * always emits it, the fallback is purely defensive).
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray( array $data ) : self
    {
        $rawFrom = $data[ self::FROM ] ?? [] ;
        $rawTo   = $data[ self::TO   ] ?? [] ;

        return new self
        (
            collection : is_string( $data[ self::COLLECTION ] ?? null ) ? $data[ self::COLLECTION ] : '' ,
            from       : is_array( $rawFrom ) ? array_values( array_filter( $rawFrom , 'is_string' ) ) : [] ,
            to         : is_array( $rawTo )   ? array_values( array_filter( $rawTo   , 'is_string' ) ) : [] ,
        ) ;
    }

    /**
     * Returns the definition as a plain associative array — the shape
     * the gharial endpoint expects on the request side.
     *
     * @return array{collection: string, from: array<int, string>, to: array<int, string>}
     */
    public function toArray() : array
    {
        return
        [
            self::COLLECTION => $this->collection ,
            self::FROM       => $this->from       ,
            self::TO         => $this->to         ,
        ] ;
    }
}
