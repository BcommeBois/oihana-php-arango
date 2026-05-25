<?php

namespace oihana\arango\models\traits\edges;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Edge;
use oihana\exceptions\BindException;
use oihana\reflect\exceptions\ConstantException;
use org\schema\constants\Schema;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use function oihana\arango\db\operators\equal;
use function oihana\arango\models\helpers\vertexID;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * Provides edge existence checking utilities for ArangoDB edge collections.
 *
 * This trait extends {@see EdgesCountTrait} to efficiently determine
 * whether edges exist between vertices using AQL queries.
 *
 * Features:
 * - Check if an edge exists between specific `_from` and `_to` vertices.
 * - Check if an edge exists from a specific `_from` vertex.
 * - Check if an edge exists to a specific `_to` vertex.
 * - Supports bind variables and query customization via the `$init` array.
 *
 * ### Usage
 *
 * ```php
 * class Edges extends Documents
 * {
 *    use EdgesExistTrait;
 * }
 *
 * $edges = new Edges($container, ['collection' => 'user_follows']);
 *
 * // Check if an edge exists between two vertices
 * $exists = $edges->existEdge('1', '5');
 *
 * // Check if an edge exists from a specific vertex
 * $existsFrom = $edges->existEdgeFrom('1');
 *
 * // Check if an edge exists to a specific vertex
 * $existsTo = $edges->existEdgeTo('5');
 * ```
 *
 * ### Notes
 * - Relies on `countEdge()` from {@see EdgesCountTrait} internally.
 * - Returns `true` if at least one matching edge exists, `false` otherwise.
 * - Throws exceptions if the query cannot be executed or if bind variables fail.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
trait EdgesExistTrait
{
    use EdgesCountTrait;

    /**
     * Checks if an edge exists between the given '_from' and '_to' vertices.
     *
     * @param string|null $from Optional 'from' vertex unique key identifier.
     * @param string|null $to   Optional 'to'   vertex unique key identifier.
     * @param array       $init Optional query options, e.g., bind variables.
     *
     * @return bool True if at least one edge exists, false otherwise.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     *
     * @example
     * ```php
     * $edges = new Edges($container, ['collection' => 'user_follows']);
     * $exists = $edges->existEdge('1', '5');
     * if ($exists) {
     *     echo "Edge exists between users/1 and posts/5";
     * }
     * ```
     */
    public function existEdge( ?string $from = null , ?string $to = null , array $init = [] ) :bool
    {
        $binds = $init[ AQL::BINDS ] ?? [] ;
        $query = $this->countEdgesQuery( $from , $to , $binds, $init );
        return $this->getFirstResult( $query , $binds ) > 0 ;
    }

    /**
     * Checks if at least one edge exists from the specified 'from' vertex.
     *
     * @param string $from The 'from' vertex unique key identifier.
     * @param array  $init Optional query options.
     *
     * @return bool True if at least one edge exists, false otherwise.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     *
     * @example
     * ```php
     * $edges = new Edges($container, ['collection' => 'user_follows']);
     * $existsFrom = $edges->existEdgeFrom('1');
     * if ($existsFrom)
     * {
     *     echo "There are edges from users/1";
     * }
     */
    public function existEdgeFrom( string $from , array $init = [] ) :bool
    {
        return $this->existEdge( from: $from , init: $init ) ;
    }

    /**
     * Checks if at least one edge exists to the specified 'to' vertex.
     *
     * @param string $to   The 'to' vertex unique key identifier.
     * @param array  $init Optional query options.
     *
     * @return bool True if at least one edge exists, false otherwise.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     *
     * @example
     * ```php
     * $edges = new Edges($container, ['collection' => 'user_follows']);
     * $existsTo = $edges->existEdgeTo('5');
     * if ($existsTo)
     * {
     *     echo "There are edges to posts/5";
     * }
     */
    public function existEdgeTo( string $to , array $init = [] ) :bool
    {
        return $this->existEdge( to: $to , init: $init ) ;
    }

    /**
     * Checks if a specific target vertex is a neighbor in any direction.
     *
     * @param string $source The '_key' or '_id' of the vertex to start from.
     * @param string $target The '_key' or '_id' of the target vertex to check for.
     * @param array  $init   Optional array of query options (e.g., `AQL::GRAPH`).
     *
     * @return bool True if the target vertex is a neighbor, false otherwise.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ConstantException
     */
    public function hasAnyVertex( string $source , string $target , array $init = [] ): bool
    {
        return $this->hasVertex( Traversal::ANY , $source , $target , $init ) ;
    }

    /**
     * Checks if a specific target vertex is an inbound neighbor of the start vertex.
     *
     * @param string $to   The '_key' or '_id' of the vertex to traverse to (start vertex).
     * @param string $from The '_key' or '_id' of the target vertex to check for.
     * @param array  $init Optional array of query options (e.g., `AQL::GRAPH`).
     *
     * @return bool True if the target vertex is an inbound neighbor, false otherwise.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ConstantException
     */
    public function hasInboundVertex( string $to, string $from , array $init = [] ): bool
    {
        return $this->hasVertex( Traversal::INBOUND , $to , $from , $init );
    }

    /**
     * Checks if a specific target vertex is an outbound neighbor of the start vertex.
     *
     * @param string $from  The '_key' or '_id' of the vertex to start from.
     * @param string $to    The '_key' or '_id' of the target vertex to check for.
     * @param array  $init  Optional array of query options (e.g., `AQL::GRAPH`).
     *
     * @return bool True if the target vertex is an outbound neighbor, false otherwise.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ConstantException
     */
    public function hasOutboundVertex( string $from , string $to , array $init = [] ): bool
    {
        return $this->hasVertex( Traversal::OUTBOUND , $from , $to , $init );
    }

    /**
     * Private helper to check for vertex existence in a specific direction.
     *
     * This method builds the filter query to check if a $targetVertex
     * exists in the results of a traversal from $startVertex.
     *
     * @param string $direction    Traversal::OUTBOUND, Traversal::INBOUND, or Traversal::ANY
     * @param string $startVertex  The _key or _id of the vertex to start from.
     * @param string $targetVertex The _key or _id of the vertex to find.
     * @param array  $init         Optional query options.
     *
     * @return bool
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function hasVertex
    (
        string $direction    ,
        string $startVertex  ,
        string $targetVertex ,
        array  $init = []
    )
    : bool
    {
        Traversal::validate( $direction );

        $binds  = $init[ AQL::BINDS   ] ?? [] ;
        $docRef = $init[ AQL::DOC_REF ] ?? AQL::VERTEX ;
        $filter = $init[ AQL::FILTER  ] ?? [] ;

        if ( $direction === Traversal::OUTBOUND || $direction === Traversal::INBOUND )
        {
            $targetContext = $direction === Traversal::INBOUND ? $this->from : $this->to ;
            $targetId      = vertexID( $targetVertex , $targetContext );
            $filter[]      = equal( key( Schema::_ID , $docRef ) , $this->bind( $targetId , $binds , AQL::ID ) ) ;
        }
        else // Traversal::ANY
        {
            $targetIdFrom = vertexID( $targetVertex , $this->from ) ;
            $targetIdTo   = vertexID( $targetVertex , $this->to   ) ;

            // Filtre : vertex._id == @id_from OR vertex._id == @id_to
            $bindFrom = $this->bind( $targetIdFrom , $binds , AQL::ID . Edge::ENTRY_FROM ) ;
            $bindTo   = $this->bind( $targetIdTo   , $binds , AQL::ID . Edge::ENTRY_TO   ) ;

            $filter[] = predicates
            (
                conditions :
                [
                    equal( key(Schema::_ID, $docRef) , $bindFrom ) ,
                    equal( key(Schema::_ID, $docRef) , $bindTo   )
                ] ,
                logicalOperator : Logic::OR ,
                useParentheses  : true
            ) ;
        }

        $init[ AQL::BINDS  ] =  $binds ;
        $init[ AQL::FILTER ] =  $filter ;

        return $this->countVertices( $direction , $startVertex , $init ) > 0 ;
    }
}