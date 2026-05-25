<?php

namespace oihana\arango\models\traits;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\edges\EdgesFromTrait;
use oihana\arango\models\traits\edges\EdgesToTrait;
use oihana\exceptions\BindException;

use org\schema\constants\Schema;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\db\operators\equal;
use function oihana\arango\models\helpers\vertexID;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * Provides utilities for handling edge vertex references in ArangoDB edge queries.
 *
 * This trait manages the source ('from') and target ('to') vertex models for
 * edge collections. It allows initializing these references from arrays or
 * PSR-11 containers and provides a convenient method to generate AQL filter
 * expressions for edges based on '_from' and '_to' vertex identifiers.
 *
 * Features:
 * - Initialize 'from' and 'to' vertices via arrays or DI containers.
 * - Generate vertex filter expressions for AQL queries using `prepareVertices()`.
 * - Supports custom logical operators (AND/OR) and document variable names.
 * - Integrates with the `BindTrait` to safely bind variables in queries.
 *
 * Example usage:
 * ```php
 * class Edges extends Documents {
 *     use VerticesTrait;
 * }
 *
 * $edges = new Edges($container, ['collection' => 'user_follows']);
 *
 * // Initialize vertices
 * $edges->initializeVertices
 * ([
 *     AQL::FROM => 'users_from',
 *     AQL::TO   => 'users_to'
 * ] , $container);
 *
 * // Prepare filter for AQL query
 * $filter = $edges->prepareVertices('123', '456');
 * // → returns a string like "doc._from == @from && doc._to == @to"
 * ```
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\models\traits\aql
 * @version 1.0.0
 */
trait VerticesTrait
{
    use BindTrait ,

        EdgesFromTrait  ,
        EdgesToTrait    ;

    /**
     * Initialize the `from` and `to` vertices references.
     *
     * Note: initialize too the `purge` reference to use in the `onDeleteVertex()` method.
     *
     * @param array                   $init
     * @param ContainerInterface|null $container
     *
     * @return static
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function initializeVertices
    (
        array               $init      = [] ,
        ?ContainerInterface $container = null
    )
    :static
    {
        return $this->initializeFrom ( $init , $container )
                    ->initializeTo   ( $init , $container )
                    ->initializePurge( $init ) ;
    }

    /**
     * Release the 'from' and 'to' vertices references.
     * @return static
     */
    public function releaseVertices() :static
    {
        return $this->releaseFrom()->releaseTo() ;
    }

    /**
     * Prepares the vertices "_from=xxx && _to=xxx" filter expression.
     *
     * @param string|null $from
     * @param string|null $to
     * @param array       $binds
     * @param array       $init The option of the method.
     *     - 'operator'     : Indicates if the filter of the vertices use a {@see Logic::AND} or {@see Logic::OR} operator (default {@see Logic::AND})
     *     - 'variableName' : The name of the document in the query (default 'doc') {@see AQL::DOC_REF}
     *
     * @return ?string
     *
     * @throws BindException
     */
    public function prepareVertices
    (
        ?string $from   = null ,
        ?string $to     = null ,
        array   &$binds = []   ,
        array   $init   = []   ,
    )
    :?string
    {
        $docRef          = $init[ AQL::DOC_REF ] ?? AQL::DOC ;
        $logicalOperator = Logic::normalize( $init[ AQL::OPERATOR ] ?? null ) ;
        $fromId          = vertexID( $from , $this->from ) ;
        $toId            = vertexID( $to   , $this->to   ) ;

        $conditions = [] ;

        if( !empty( $fromId ) )
        {
            $conditions[] = equal
            (
                key( Schema::_FROM , $docRef ) ,
                $this->bind( $fromId , $binds , AQL::FROM )
            ) ;
        }

        if( !empty( $toId ) )
        {
            $conditions[] = equal
            (
                key( Schema::_TO , $docRef ) ,
                $this->bind( $toId , $binds , AQL::TO )
            ) ;
        }

        return count( $conditions ) > 0 ? predicates( $conditions , $logicalOperator ) : null ;
    }
}
