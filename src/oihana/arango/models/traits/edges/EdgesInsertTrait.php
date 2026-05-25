<?php

namespace oihana\arango\models\traits\edges;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use InvalidArgumentException;
use ReflectionException;
use Throwable;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\documents\DocumentsInsertTrait;
use oihana\arango\models\traits\VerticesTrait;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;

use org\schema\constants\Schema;

use function oihana\arango\models\helpers\vertexID;

/**
 * Provides edge insertion utilities for ArangoDB edge collections.
 *
 * This trait simplifies the process of inserting edge documents,
 * handling vertex ID resolution and optional uniqueness checks before insertion.
 *
 * It is designed to be mixed into model classes extending {@see Documents}
 * that represent an edge collection and also utilize:
 * - {@see DocumentsInsertTrait} (for the base `insert()` method)
 * - {@see EdgesExistTrait} (for the `existEdge()` method used in uniqueness checks)
 * - {@see VerticesTrait} (for `vertexID()` helper and `from`/`to` model contexts)
 * - {@see ArangoTrait} (for query execution)
 *
 * ### Features
 * - Resolves `_from` and `_to` vertex keys/IDs using context models.
 * - Optionally checks for pre-existing edges between the same vertices before inserting.
 * - Throws a specific exception (`Error409`) if a duplicate is found and uniqueness is enforced.
 * - Delegates the actual insertion to the robust `insert()` method.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
trait EdgesInsertTrait
{
    use ArangoTrait ,
        DocumentsInsertTrait ,
        EdgesExistTrait ,
        VerticesTrait ;

    /**
     * Inserts a new edge document connecting two vertices, optionally checking for uniqueness.
     *
     * Constructs the edge document (`_from`, `_to`, attributes) and delegates
     * to the generic `insert()` method. By default, it first checks if an edge
     * with the same `_from` and `_to` already exists using `existEdge()` and throws
     * an `Error409` if found. This behavior can be disabled via the `AQL::UNIQUE` option.
     *
     * @param string $from The `_key` or full `_id` of the source vertex. Resolved using `$this->from` context if only a key is given.
     * @param string $to   The `_key` or full `_id` of the target vertex. Resolved using `$this->to` context if only a key is given.
     * @param array  $doc  Optional additional document to complete the edge document (e.g., `['createdAt' => time()]`).
     * @param array  $init Optional array for query options:
     * - AQL::UNIQUE (bool) : If `true` (default), checks for an existing edge between `$from` and `$to` before inserting. Throws `Error409` if found. If `false`, attempts direct insert (faster, relies on DB index or allows duplicates).
     * - AQL::OPTIONS (array): Options passed to the underlying ArangoDB `INSERT` operation (see {@see \oihana\arango\db\options\InsertOptions}).
     * - Other options accepted by the underlying `insert()` method (e.g., `AQL::EXCLUDES`).
     *
     * @return object|null The newly inserted edge document object , or `null` on failure.
     *
     * @throws ArangoException If the underlying AQL query execution fails.
     * @throws BindException If binding variables fails during the `insert()` process.
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws Error409 If `AQL::UNIQUE` is true and an edge already exists between the resolved `$from` and `$to`.
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws Throwable
     *
     * @example
     * ```php
     * $edges = new Edges($container, ['collection' => 'user_follows']);
     *
     * // Insert, will throw Error409 if edge already exists
     * try
     * {
     *     $newEdge = $edges->insertEdge('users/1', 'users/2', ['since' => date('Y-m-d')]);
     *     if ($newEdge)
     *     {
     *         echo "Edge created with _id: " . $newEdge->_id;
     *     }
     * } catch ( Error409 $e )
     * {
     *      echo "$e->getMessage() ;
     * }
     *
     * // Insert without checking uniqueness
     * $newEdgeUnchecked = $edges->insertEdge('users/1', 'users/3', [], [ AQL::UNIQUE => false ]);
     * ```
     */
    public function insertEdge
    (
        string $from ,
        string $to ,
        array  $doc  = [] ,
        array  $init = []
    )
    : ?object
    {
        $fromId = vertexID( $from , $this->from ) ;
        $toId   = vertexID( $to   , $this->to   ) ;

        if ( empty( $fromId ) || !str_contains( $fromId , Char::SLASH ) )
        {
            throw new InvalidArgumentException(sprintf( 'Invalid or empty "%s" vertex ID provided. Must be a full _id (e.g., "collection/key").' , $fromId )  ) ;
        }

        if ( empty( $toId ) || !str_contains( $toId , Char::SLASH ) )
        {
            throw new InvalidArgumentException(sprintf( 'Invalid or empty "%s" vertex ID provided. Must be a full _id (e.g., "collection/key").' , $toId ) ) ;
        }

        $unique = $init[ AQL::UNIQUE ] ?? true ;
        if( $unique && $this->existEdge( $fromId , $toId , $init ) )
        {
            throw new Error409( sprintf( 'An edge already exists between vertices "%s" and "%s".' , $fromId , $toId ) ) ;
        }

        return $this->insert
        ([
            ...$init,
            AQL::DOC => [ ...$doc , Schema::_FROM => $fromId , Schema::_TO   => $toId ]
        ] ) ;
    }
}