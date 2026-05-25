<?php

namespace oihana\arango\models\traits\edges;

use oihana\arango\models\traits\aql\BindTrait;
use ReflectionException;
use InvalidArgumentException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\Documents;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\documents\DocumentsDeleteTrait;
use oihana\arango\models\traits\VerticesTrait;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;

use oihana\models\notices\AfterDelete;
use oihana\models\notices\BeforeDelete;

use org\schema\constants\Schema;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlRemove;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\in;
use function oihana\arango\models\helpers\vertexID;
use function oihana\core\arrays\toArray;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * Provides deletion utilities for ArangoDB edge collections.
 *
 * This trait simplifies deleting edge documents by:
 * - Handling vertex ID resolution (`_from` and `_to`) automatically.
 * - Constructing dynamic AQL queries with optional filter conditions.
 * - Supporting deletion of single edges, edges from a specific vertex, edges to a specific vertex,
 * or all edges connected to a vertex.
 *
 * It is intended to be used with models extending {@see Documents} and leveraging:
 * - {@see DocumentsDeleteTrait} for generic document deletion logic.
 * - {@see EdgesExistTrait} to optionally check for existing edges (e.g., uniqueness enforcement).
 * - {@see VerticesTrait} for `vertexID()` helper and `$from` / `$to` model contexts.
 * - {@see ArangoTrait} for executing ArangoDB queries.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
trait EdgesDeleteTrait
{
    use ArangoTrait ,
        BindTrait ,
        VerticesTrait ;

    /**
     * Delete an edge document connecting two vertices.
     *
     * Builds an AQL query to remove the edge between `$from` and `$to`.
     * By default, checks for existing edges to enforce uniqueness (throws `Error409`) unless disabled via `AQL::UNIQUE`.
     *
     * @param string|null $from The `_key` or full `_id` of the source vertex.
     *                          If only a key is given, it will be resolved using `$this->from` context.
     * @param string|null $to   The `_key` or full `_id` of the target vertex.
     *                          If only a key is given, it will be resolved using `$this->to` context.
     * @param array       $init Optional array for query options:
     *                          - `AQL::UNIQUE` (bool) : enforce uniqueness check (default `true`).
     *                          - `AQL::OPTIONS` (array): additional options for the underlying ArangoDB `INSERT` operation.
     *                          - `AQL::BINDS`, `AQL::FILTER`, `AQL::FIRST`, and other options accepted by `insert()`.
     *
     * @return array|object|null The removed edge document, the first removed edge if multiple exist, or null if none.
     *
     * @throws ArangoException                On query execution failure.
     * @throws BindException                  On binding failure.
     * @throws ContainerExceptionInterface    On container-related errors.
     * @throws DependencyException            On dependency resolution failure.
     * @throws NotFoundException              On missing dependencies.
     * @throws NotFoundExceptionInterface     On missing container entries.
     * @throws ReflectionException            On reflection errors.
     * @throws UnsupportedOperationException  If operation is not supported.
     */
    public function deleteEdge
    (
        ?string $from = null ,
        ?string $to   = null ,
          array $init = []
    )
    : null|array|object
    {
        $binds  = $init[ AQL::BINDS  ] ?? [] ;
        $filter = $init[ AQL::FILTER ] ?? [] ;
        $first  = $init[ AQL::FIRST  ] ?? true ;

        $this->beforeDelete?->emit( new BeforeDelete
        (
            target  : $this ,
            context : $init
        )) ;

        $collection = $this->bindCollection( $binds ) ;

        $query = compile
        ([
            aqlFor    ( [ ...$init   , AQL::IN => $collection ] ) ,
            aqlFilter ( [ ...$filter , $this->prepareVertices( $from , $to , $binds ) ] ),
            aqlRemove ( [ AQL::COLLECTION => $collection ] ) ,
            aqlReturn ( Clause::OLD )
        ]);

        $documents = $this->getDocuments( $query , $binds ) ;

        $result = $first ? ( $documents[0] ?? null ) : $documents ;

        $this->afterDelete?->emit( new AfterDelete
        (
            data    : $result ,
            target  : $this   ,
            context : $init
        )) ;

        return $result ;
    }

    /**
     * Delete all edges where the `_from` vertex matches the given identifier.
     *
     * @param string $from Vertex identifier for the `_from` field.
     * @param array  $init Optional query options (bind variables, filters, etc.).
     *
     * @return array|object|null Removed edge(s), or null if none found.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function deleteEdgeFrom( string $from , array  $init = [] ) :array|object|null
    {
        return $this->deleteEdge( from: $from , init: $init ) ;
    }

    /**
     * Delete all edges connected to a specific vertex, either `_from` or `_to`.
     *
     * Constructs a dynamic AQL query to remove any edge documents
     * where the given vertex is present in `_from` or `_to`.
     *
     * @param string|array $vertex The vertex key(s) or full `_id`(s) to remove from the edge collection.
     * @param array        $init   Optional query options:
     *                              - `AQL::CONTEXT` (Documents|string|AQL::FROM|AQL::TO) : base context for vertex resolution.
     *                              - `AQL::BINDS`, `AQL::FILTER`, `AQL::DOC_REF` etc.
     *
     * @return array|object|null List of removed edge documents, or null if none found.
     *
     * @throws InvalidArgumentException If the vertex ID is invalid or empty.
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function deleteEdges( string|array $vertex , array $init = [] ) :array|object|null
    {
        $bindVars = $init[ AQL::BINDS   ] ?? [] ;
        $context  = $init[ AQL::CONTEXT ] ?? AQL::FROM ;
        $docRef   = $init[ AQL::DOC_REF ] ?? AQL::DOC ;
        $filter   = $init[ AQL::FILTER  ] ?? [] ;

        $this->beforeDelete?->emit( new BeforeDelete
        (
            target  : $this ,
            context : $init
        )) ;

        $context = match ( true )
        {
            $context instanceof Documents ,
            is_string( $context ) && $context != AQL::TO && $context != AQL::FROM => $context,
            $context === AQL::TO                                                  => $this->to ,
            default                                                               => $this->from ,
        };

        $vertices = array_map( fn( $v ) => vertexID( $v , $context ) , toArray( $vertex ) ) ;

        $vertices = array_filter( $vertices , fn($v) => !empty($v) && str_contains( $v , Char::SLASH ) );

        if ( !$vertices )
        {
            throw new InvalidArgumentException('No valid vertex IDs provided. Must be full _id(s) like "collection/key".');
        }

        $in = [] ;
        foreach ( $vertices as $value )
        {
            $in[] = $this->bind( $value , $bindVars ) ;
        }

        $inList = Char::LEFT_BRACKET . implode(Char::COMMA , $in ) . Char::RIGHT_BRACKET ;

        $conditions = predicates
        ([
            in( key(Schema::_FROM , $docRef ) , $inList ) ,
            in( key(Schema::_TO   , $docRef ) , $inList )
        ]
        , Logic::OR , true ) ;

        $collection = $this->bindCollection( $bindVars ) ;

        $query = compile
        ([
            aqlFor    ( [ ...$init   , AQL::IN => $collection ] ) ,
            aqlFilter ( [ $conditions , ...$filter ] ),
            aqlRemove ( [ AQL::COLLECTION => $collection ] ) ,
            aqlReturn ( Clause::OLD )
        ]);

        $result = $this->getDocuments( $query , $bindVars ) ;

        $this->afterDelete?->emit( new AfterDelete
        (
            data    : $result ,
            target  : $this   ,
            context : $init
        )) ;

        return $result ;
    }

    /**
     * Delete all edges where the `_to` vertex matches the given identifier.
     *
     * @param string $to Vertex identifier for the `_to` field.
     * @param array  $init Optional query options (bind variables, filters, etc.).
     *
     * @return array|object|null Removed edge(s), or null if none found.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function deleteEdgeTo( string $to , array  $init = [] ) :array|object|null
    {
        return $this->deleteEdge( to: $to , init: $init ) ;
    }
}