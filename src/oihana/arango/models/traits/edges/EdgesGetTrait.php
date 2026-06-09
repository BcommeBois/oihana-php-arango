<?php

namespace oihana\arango\models\traits\edges;

use InvalidArgumentException;
use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\edges\helpers\PrepareTraversalTrait;
use oihana\arango\models\traits\VerticesTrait;
use oihana\exceptions\BindException;
use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlLimit;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlSort;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\core\strings\compile;

/**
 * Provides edge traversal and vertex retrieval utilities for ArangoDB edge collections.
 *
 * This trait defines methods to query and retrieve vertices connected through edges
 * in an ArangoDB graph. It allows fetching all outbound vertices from a given vertex,
 * leveraging AQL traversal patterns (`OUTBOUND`) to navigate relationships efficiently.
 *
 * Designed to be mixed into model classes extending {@see Documents}, this trait integrates
 * seamlessly with schema-aware document models and supports AQL bind variables,
 * schema reflection, and container-based dependency injection.
 *
 * ### Features
 * - Retrieve outbound vertices (targets) connected from a given `_from` vertex.
 * - Support for AQL bind variables and dynamic query compilation.
 * - Integration with `Documents` models for schema-based field mapping.
 * - Optional first-result retrieval via `AQL::FIRST` flag.
 * - Safe query execution with exception handling for DI and AQL errors.
 *
 * ### Usage
 *
 * ```php
 * use oihana\arango\models\traits\edges\EdgesGetTrait;
 *
 * class Edges extends Documents
 * {
 *     use EdgesGetTrait;
 * }
 *
 * $edges = new Edges($container, ['collection' => 'user_follows']);
 *
 * // Retrieve all outbound vertices connected from a given user
 * $vertices = $edges->getOutboundVertices('users/1');
 *
 * // Retrieve only the first outbound vertex
 * $firstVertex = $edges->getOutboundVertices('users/1', [AQL::FIRST => true]);
 *
 * // Example of resulting AQL query:
 * // FOR v IN OUTBOUND @from @@collection
 * // RETURN v
 * ```
 *
 * ### Notes
 * - The `_from` and `_to` vertex identifiers must be fully qualified (e.g. `'users/1'`).
 * - If `$from` is `null`, the method uses the current `$this->from` vertex reference.
 * - Requires an existing edge collection and a valid ArangoDB connection in the container.
 * - Compatible with {@see VerticesTrait} and {@see ArangoTrait} for extended AQL utilities.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
trait EdgesGetTrait
{
    use ArangoTrait ,
        VerticesTrait;

    /**
     * Retrieves all vertices connected in any direction from the given vertex.
     *
     * This method executes an AQL query to fetch vertices that are connected
     * via incoming or outgoing edges to the specified vertex in the current edge collection.
     * It is a convenience wrapper for {@see getVertices()} with the direction
     * set to `Traversal::ANY`.
     *
     * Note: By default, this method returns raw data unless an `AQL::TARGET` model
     * is provided in the `$init` options.
     *
     * @param string|null $vertex Optional '_key' or '_id' of the vertex to start from.
     * If null, defaults to the current `$this->from` vertex reference.
     * @param array $init Optional array of query options:
     * - AQL::ANY_REF (string)     : Context for `ANY` traversal when using a `_key` (`AQL::FROM` or `AQL::TO`). Defaults to `AQL::FROM`.
     * - AQL::DOC_REF (string)     : AQL variable name for the vertex (default: 'vertex').
     * - AQL::FIRST (bool)         : Return only the first matched vertex.
     * - AQL::FROM (Documents)     : Override the default `_from` model instance.
     * - AQL::GRAPH (string)       : The name of a graph to use for traversal.
     * - AQL::LIMIT (int)          : Maximum number of vertices to return.
     * - AQL::MAX_DEPTH (int)      : Maximum traversal depth (requires `AQL::GRAPH`).
     * - AQL::MIN_DEPTH (int)      : Minimum traversal depth (requires `AQL::GRAPH`).
     * - AQL::OFFSET (int)         : Number of vertices to skip.
     * - AQL::PRUNE (string|array) : AQL `PRUNE` condition for graph traversals (requires `AQL::GRAPH`).
     * - AQL::RAW (bool)           : Return raw array data. Defaults to `true` unless `AQL::TARGET` is specified.
     * - AQL::RETURN (string)      : Manually specify the AQL `RETURN` expression.
     * - AQL::SORT (string|array)  : Sorting criteria.
     * - AQL::TARGET (Documents)   : Manually specify a Document model for schema mapping.
     * - AQL::TO (Documents)       : Override the default `_to` model instance.
     * - Additional bind variables can also be passed.
     *
     * @return object|array|null Returns an array of vertex documents, a single vertex object
     * if `AQL::FIRST` is true, or null if no vertices found.
     *
     * @throws ArangoException               If there is an error executing the AQL query.
     * @throws BindException                 If a bind variable is missing or invalid.
     * @throws ConstantException             If the $direction argument is not a valid Traversal constant.
     * @throws InvalidArgumentException      If the vertex ID is null or empty after processing.
     * @throws ContainerExceptionInterface   If the DI container fails.
     * @throws DependencyException           If a dependency is missing in the container.
     * @throws NotFoundException             If the DI container cannot find a required service.
     * @throws NotFoundExceptionInterface    Same as above, PSR-11 interface.
     * @throws ReflectionException           If schema reflection fails.
     *
     * ### Example
     *
     * ```php
     * // $edges = Edges for 'user_follows_user'
     * $edges = new Edges($container, ['collection' => 'user_follows_user']);
     *
     * // Get all followers and all followed users for 'users/1'
     * $allNeighbors = $edges->getAnyVertices('1');
     * ```
     */
    public function getAnyVertices( ?string $vertex = null , array $init = [] ) :object|array|null
    {
        return $this->getVertices( Traversal::ANY , $vertex , $init ) ;
    }

    /**
     * Retrieves the first vertex connected in any direction from the given vertex.
     *
     * This is a convenience method that wraps {@see getAnyVertices()} with the
     * `AQL::FIRST` flag set to `true`, returning only the first vertex found in the
     * `ANY` traversal of the current edge collection.
     *
     * It executes an AQL query equivalent to:
     *
     * ```aql
     * FOR v IN ANY @vertex @@collection RETURN v
     * ```
     * but only returns the first resulting vertex document (if any).
     *
     * @param string|null $vertex Optional '_key' or '_id' of the vertex to start from.
     * If null, defaults to the current `$this->from` vertex reference.
     * @param array $init Optional query initialization array, merged with `[AQL::FIRST => true]`.
     * - AQL::ANY_REF (string)     : Context for `ANY` traversal when using a `_key` (`AQL::FROM` or `AQL::TO`). Defaults to `AQL::FROM`.
     * - AQL::DOC_REF (string)     : AQL variable name for the vertex (default: 'vertex').
     * - AQL::FROM (Documents)     : Override the default `_from` model instance.
     * - AQL::GRAPH (string)       : The name of a graph to use for traversal.
     * - AQL::LIMIT (int)          : Maximum number of vertices to return.
     * - AQL::MAX_DEPTH (int)      : Maximum traversal depth (requires `AQL::GRAPH`).
     * - AQL::MIN_DEPTH (int)      : Minimum traversal depth (requires `AQL::GRAPH`).
     * - AQL::OFFSET (int)         : Number of vertices to skip.
     * - AQL::PRUNE (string|array) : AQL `PRUNE` condition for graph traversals (requires `AQL::GRAPH`).
     * - AQL::RAW (bool)           : Return raw array data. Defaults to `true` unless `AQL::TARGET` is specified.
     * - AQL::RETURN (string)      : Manually specify the AQL `RETURN` expression.
     * - AQL::SORT (string|array)  : Sorting criteria.
     * - AQL::TARGET (Documents)   : Manually specify a Document model for schema mapping.
     * - AQL::TO (Documents)       : Override the default `_to` model instance.
     * - Additional bind variables can also be passed.
     *
     * @return object|array|null The first vertex document connected to the given vertex,
     * or `null` if no edge exists.
     *
     * @throws ArangoException               If there is an error executing the AQL query.
     * @throws BindException                 If a bind variable is missing or invalid.
     * @throws ConstantException             If the $direction argument is not a valid Traversal constant.
     * @throws InvalidArgumentException      If the vertex ID is null or empty after processing.
     * @throws ContainerExceptionInterface   If the DI container fails.
     * @throws DependencyException           If a dependency is missing in the container.
     * @throws NotFoundException             If the DI container cannot find a required service.
     * @throws NotFoundExceptionInterface    Same as above, PSR-11 interface.
     * @throws ReflectionException           If schema reflection fails.
     */
    public function getFirstAnyVertex( ?string $vertex = null , array $init = [] ) :object|array|null
    {
        return $this->getAnyVertices( $vertex ,  [ AQL::FIRST => true , ...$init ] ) ;
    }

    /**
     * Retrieves the first inbound vertex connected to the given 'to' vertex.
     *
     * This is a convenience method that wraps {@see getInboundVertices()} with the
     * `AQL::FIRST` flag set to `true`, returning only the first vertex found in the
     * inbound traversal of the current edge collection.
     *
     * It executes an AQL query equivalent to:
     *
     * ```aql
     * FOR v IN INBOUND @to @@collection RETURN v
     * ```
     * but only returns the first resulting vertex document (if any).
     *
     * @param string|null $to Optional '_key' or '_id' of the vertex to traverse to.
     * If null, defaults to the current `$this->to` vertex reference.
     * @param array $init Optional query initialization array, merged with `[AQL::FIRST => true]`.
     * - AQL::DOC_REF (string)     : AQL variable name for the vertex (default: 'vertex').
     * - AQL::FROM (Documents)     : Override the default `_from` model instance.
     * - AQL::GRAPH (string)       : The name of a graph to use for traversal.
     * - AQL::LIMIT (int)          : Maximum number of vertices to return.
     * - AQL::MAX_DEPTH (int)      : Maximum traversal depth (requires `AQL::GRAPH`).
     * - AQL::MIN_DEPTH (int)      : Minimum traversal depth (requires `AQL::GRAPH`).
     * - AQL::OFFSET (int)         : Number of vertices to skip.
     * - AQL::PRUNE (string|array) : AQL `PRUNE` condition for graph traversals (requires `AQL::GRAPH`).
     * - AQL::RAW (bool)           : Return raw array data instead of mapped objects.
     * - AQL::RETURN (string)      : Manually specify the AQL `RETURN` expression.
     * - AQL::SORT (string|array)  : Sorting criteria.
     * - AQL::TARGET (Documents)   : Manually specify a Document model for schema mapping.
     * - AQL::TO (Documents)       : Override the default `_to` model instance.
     * - Additional bind variables can also be passed.
     *
     * @return object|array|null The first inbound vertex document connected to the given vertex,
     * or `null` if no inbound edge exists.
     *
     * @throws ArangoException               If there is an error executing the AQL query.
     * @throws BindException                 If a bind variable is missing or invalid.
     * @throws ConstantException             If the $direction argument is not a valid Traversal constant.
     * @throws InvalidArgumentException      If the vertex ID is null or empty after processing.
     * @throws ContainerExceptionInterface   If the DI container fails.
     * @throws DependencyException           If a dependency is missing in the container.
     * @throws NotFoundException             If the DI container cannot find a required service.
     * @throws NotFoundExceptionInterface    Same as above, PSR-11 interface.
     * @throws ReflectionException           If schema reflection fails.
     *
     * ### Example
     *
     * ```php
     * // $edges = Edges for 'user_follows_user'
     * $edges = new Edges($container, ['collection' => 'user_follows_user']);
     *
     * // Retrieve the first vertex that follows 'users/1' (their first follower)
     * $firstFollower = $edges->getFirstInboundVertex('users/1');
     * ```
     */
    public function getFirstInboundVertex( ?string $to = null , array $init = [] ) :object|array|null
    {
        return $this->getInboundVertices( $to ,  [ AQL::FIRST => true , ...$init ] ) ;
    }

    /**
     * Retrieves the first outbound vertex connected from the given 'from' vertex.
     *
     * This is a convenience method that wraps {@see getOutboundVertices()} with the
     * `AQL::FIRST` flag set to `true`, returning only the first vertex found in the
     * outbound traversal of the current edge collection.
     *
     * It executes an AQL query equivalent to:
     *
     * ```aql
     * FOR v IN OUTBOUND @from @@collection RETURN v
     * ```
     * but only returns the first resulting vertex document (if any).
     *
     * @param string|null $from Optional '_key' or '_id' of the vertex to start from.
     * If null, defaults to the current `$this->from` vertex reference.
     * @param array $init Optional query initialization array, merged with `[AQL::FIRST => true]`.
     * - AQL::DOC_REF (string)     : AQL variable name for the vertex (default: 'vertex').
     * - AQL::FROM (Documents)     : Override the default `_from` model instance.
     * - AQL::GRAPH (string)       : The name of a graph to use for traversal.
     * - AQL::LIMIT (int)          : Maximum number of vertices to return.
     * - AQL::MAX_DEPTH (int)      : Maximum traversal depth (requires `AQL::GRAPH`).
     * - AQL::MIN_DEPTH (int)      : Minimum traversal depth (requires `AQL::GRAPH`).
     * - AQL::OFFSET (int)         : Number of vertices to skip.
     * - AQL::PRUNE (string|array) : AQL `PRUNE` condition for graph traversals (requires `AQL::GRAPH`).
     * - AQL::RAW (bool)           : Return raw array data instead of mapped objects.
     * - AQL::RETURN (string)      : Manually specify the AQL `RETURN` expression.
     * - AQL::SORT (string|array)  : Sorting criteria.
     * - AQL::TARGET (Documents)   : Manually specify a Document model for schema mapping.
     * - AQL::TO (Documents)       : Override the default `_to` model instance.
     * - Additional bind variables can also be passed.
     *
     * @return object|array|null The first outbound vertex document connected from the given vertex,
     * or `null` if no outbound edge exists.
     *
     * @throws ArangoException               If there is an error executing the AQL query.
     * @throws BindException                 If a bind variable is missing or invalid.
     * @throws ConstantException             If the $direction argument is not a valid Traversal constant.
     * @throws InvalidArgumentException      If the vertex ID is null or empty after processing.
     * @throws ContainerExceptionInterface   If the DI container fails.
     * @throws DependencyException           If a dependency is missing in the container.
     * @throws NotFoundException             If the DI container cannot find a required service.
     * @throws NotFoundExceptionInterface    Same as above, PSR-11 interface.
     * @throws ReflectionException           If schema reflection fails.
     *
     * ### Example
     *
     * ```php
     * $edges = new Edges($container, ['collection' => 'user_follows']);
     *
     * // Retrieve the first vertex that 'users/1' follows
     * $firstVertex = $edges->getFirstOutboundVertex('users/1');
     *
     * if ($firstVertex) {
     * echo "First followed vertex: " . $firstVertex->_id . PHP_EOL;
     * }
     * ```
     */
    public function getFirstOutboundVertex( ?string $from = null , array $init = [] ) :object|array|null
    {
        return $this->getOutboundVertices( $from , [ AQL::FIRST => true , ...$init ] ) ;
    }

    /**
     * Retrieves all inbound vertices connected to the given 'to' vertex.
     *
     * This method executes an AQL query to fetch vertices that are connected
     * via incoming edges to the specified vertex in the current edge collection.
     * It is a convenience wrapper for {@see getVertices()} with the direction
     * set to `Traversal::INBOUND`.
     *
     * @param string|null $to Optional '_key' or '_id' of the vertex to traverse to.
     * If null, defaults to the current `$this->to` vertex reference.
     * @param array $init Optional array of query options:
     * - AQL::DOC_REF (string)     : AQL variable name for the vertex (default: 'vertex').
     * - AQL::FIRST (bool)         : Return only the first matched vertex.
     * - AQL::FROM (Documents)     : Override the default `_from` model instance.
     * - AQL::GRAPH (string)       : The name of a graph to use for traversal.
     * - AQL::LIMIT (int)          : Maximum number of vertices to return.
     * - AQL::MAX_DEPTH (int)      : Maximum traversal depth (requires `AQL::GRAPH`).
     * - AQL::MIN_DEPTH (int)      : Minimum traversal depth (requires `AQL::GRAPH`).
     * - AQL::OFFSET (int)         : Number of vertices to skip.
     * - AQL::PRUNE (string|array) : AQL `PRUNE` condition for graph traversals (requires `AQL::GRAPH`).
     * - AQL::RAW (bool)           : Return raw array data instead of mapped objects.
     * - AQL::RETURN (string)      : Manually specify the AQL `RETURN` expression.
     * - AQL::SORT (string|array)  : Sorting criteria.
     * - AQL::TARGET (Documents)   : Manually specify a Document model for schema mapping (overrides default `$from` model).
     * - AQL::TO (Documents)       : Override the default `_to` model instance.
     * - Additional bind variables can also be passed.
     *
     * @return object|array|null Returns an array of vertex documents, a single vertex object
     * if `AQL::FIRST` is true, or null if no vertices found.
     *
     * @throws ArangoException               If there is an error executing the AQL query.
     * @throws BindException                 If a bind variable is missing or invalid.
     * @throws ConstantException             If the $direction argument is not a valid Traversal constant.
     * @throws InvalidArgumentException      If the vertex ID is null or empty after processing.
     * @throws ContainerExceptionInterface   If the DI container fails.
     * @throws DependencyException           If a dependency is missing in the container.
     * @throws NotFoundException             If the DI container cannot find a required service.
     * @throws NotFoundExceptionInterface    Same as above, PSR-11 interface.
     * @throws ReflectionException           If schema reflection fails.
     *
     * ### Example
     *
     * ```php
     * // $edges = Edges for 'user_follows_user'
     * $edges = new Edges($container, ['collection' => 'user_follows_user']);
     *
     * // Get all vertices that follow 'users/1' (all followers)
     * $followers = $edges->getInboundVertices('1');
     * ```
     */
    public function getInboundVertices( ?string $to = null , array $init = [] ) :object|array|null
    {
        return $this->getVertices( Traversal::INBOUND , $to , $init ) ;
    }

    /**
     * Retrieves all outbound vertices connected from the given 'from' vertex.
     *
     * This method executes an AQL query to fetch vertices that are connected
     * via outgoing edges from the specified vertex in the current edge collection.
     * It is a convenience wrapper for {@see getVertices()} with the direction
     * set to `Traversal::OUTBOUND`.
     *
     * @param string|null $from Optional '_key' or '_id' of the vertex to start from.
     * If null, defaults to the current `$this->from` vertex reference.
     * @param array $init Optional array of query options:
     * - AQL::DOC_REF (string)     : AQL variable name for the vertex (default: 'vertex').
     * - AQL::FIRST (bool)         : Return only the first matched vertex.
     * - AQL::FROM (Documents)     : Override the default `_from` model instance.
     * - AQL::GRAPH (string)       : The name of a graph to use for traversal.
     * - AQL::LIMIT (int)          : Maximum number of vertices to return.
     * - AQL::MAX_DEPTH (int)      : Maximum traversal depth (requires `AQL::GRAPH`).
     * - AQL::MIN_DEPTH (int)      : Minimum traversal depth (requires `AQL::GRAPH`).
     * - AQL::OFFSET (int)         : Number of vertices to skip.
     * - AQL::PRUNE (string|array) : AQL `PRUNE` condition for graph traversals (requires `AQL::GRAPH`).
     * - AQL::RAW (bool)           : Return raw array data instead of mapped objects.
     * - AQL::RETURN (string)      : Manually specify the AQL `RETURN` expression.
     * - AQL::SORT (string|array)  : Sorting criteria.
     * - AQL::TARGET (Documents)   : Manually specify a Document model for schema mapping (overrides default `$to` model).
     * - AQL::TO (Documents)       : Override the default `_to` model instance.
     * - Additional bind variables can also be passed.
     *
     * @return object|array|null Returns an array of vertex documents, a single vertex object
     * if `AQL::FIRST` is true, or null if no vertices found.
     *
     * @throws ArangoException               If there is an error executing the AQL query.
     * @throws BindException                 If a bind variable is missing or invalid.
     * @throws ConstantException             If the $direction argument is not a valid Traversal constant.
     * @throws InvalidArgumentException      If the vertex ID is null or empty after processing.
     * @throws ContainerExceptionInterface   If the DI container fails.
     * @throws DependencyException           If a dependency is missing in the container.
     * @throws NotFoundException             If the DI container cannot find a required service.
     * @throws NotFoundExceptionInterface    Same as above, PSR-11 interface.
     * @throws ReflectionException           If schema reflection fails.
     *
     * ### Example
     *
     * ```php
     * $edges = new Edges($container, ['collection' => 'user_follows']);
     *
     * // Get all outbound vertices from 'users/1'
     * $vertices = $edges->getOutboundVertices('1');
     *
     * // Get only the first outbound vertex
     * $firstVertex = $edges->getOutboundVertices('1', [AQL::FIRST => true]);
     * ```
     */
    public function getOutboundVertices( ?string $from = null , array $init = [] ) :object|array|null
    {
        return $this->getVertices( Traversal::OUTBOUND , $from , $init ) ;
    }

    /**
     * Retrieves all vertices connected with a specific direction.
     *
     * This method executes an AQL query to fetch vertices that are connected
     * via edges from the specified vertex in the current edge collection.
     * It supports standard collection traversal (FOR v IN ... @@collection)
     * and named graph traversal (FOR v IN ... GRAPH 'graph_name').
     *
     * It can optionally return only the first result, or a set of results as an array.
     *
     * @param string      $direction The direction of the relation: {@see Traversal::OUTBOUND}, {@see Traversal::INBOUND}, or {@see Traversal::ANY}.
     * @param string|null $vertex    Optional '_key' or '_id' of the vertex to start the relation from.
     * If a full '_id' (e.g., "users/123") is provided, it is used directly (assumes vertexID handles this).
     * If only a '_key' (e.g., "123") is provided, it will be prefixed based on context:
     * - `OUTBOUND`: Uses the `$from` collection.
     * - `INBOUND`: Uses the `$to` collection.
     * - `ANY`: Uses the `$from` collection by default (configurable via `AQL::ANY_REF`).
     * @param array $init Optional array of query options:
     * - AQL::ANY_REF (string)     : Context for `ANY` traversal when using a `_key` (`AQL::FROM` or `AQL::TO`). Defaults to `AQL::FROM`.
     * - AQL::DOC_REF (string)     : AQL variable name for the vertex (default: 'vertex').
     * - AQL::FIRST (bool)         : Return only the first matched vertex.
     * - AQL::FROM (Documents)     : Override the default `_from` model instance.
     * - AQL::GRAPH (string)       : The name of a graph to use for traversal (enables graph-specific options).
     * - AQL::LIMIT (int)          : Maximum number of vertices to return.
     * - AQL::MAX_DEPTH (int)      : Maximum traversal depth (requires `AQL::GRAPH`).
     * - AQL::MIN_DEPTH (int)      : Minimum traversal depth (requires `AQL::GRAPH`).
     * - AQL::OFFSET (int)         : Number of vertices to skip (for pagination).
     * - AQL::PRUNE (string|array) : AQL `PRUNE` condition for graph traversals (requires `AQL::GRAPH`).
     * - AQL::RAW (bool)           : Return raw array data. Defaults to `true` for `ANY` traversals unless `AQL::TARGET` is specified.
     * - AQL::RETURN (string)      : Manually specify the AQL `RETURN` expression (e.g., `"v.name"`). Overrides model-defined `returnFields`.
     * - AQL::SORT (string|array)  : Sorting criteria (e.g., `"vertex.name ASC"`). Overrides model-defined `prepareSort`.
     * - AQL::TARGET (Documents)   : Manually specify a Document model for schema mapping, especially useful for `ANY` traversals.
     * - AQL::TO (Documents)       : Override the default `_to` model instance.
     * - Additional bind variables can also be passed.
     *
     * @return object|array|null Returns an array of vertex documents or a single vertex object if `AQL::FIRST` is true.
     *
     * @throws ArangoException               If there is an error executing the AQL query.
     * @throws BindException                 If a bind variable is missing or invalid.
     * @throws ConstantException             If the $direction argument is not a valid Traversal constant.
     * @throws InvalidArgumentException      If the vertex ID is null or empty after processing.
     * @throws ContainerExceptionInterface   If the DI container fails.
     * @throws DependencyException           If a dependency is missing in the container.
     * @throws NotFoundException             If the DI container cannot find a required service.
     * @throws NotFoundExceptionInterface    Same as above, PSR-11 interface.
     * @throws ReflectionException           If schema reflection fails.
     *
     * @example
     *
     * Assumes $edges collection connects 'users' ($from) to 'groups' ($to)
     * ```php
     * $edges = new Edges($container, ['collection' => 'user_joins_group']);
     * ```
     *
     * Get all outbound vertices (groups) from 'users/1'
     * ```php
     * $vertices = $edges->getVertices( Traversal::OUTBOUND , '1' ) ;
     * ```
     *
     * Get only the first outbound vertex
     * ```php
     * $firstVertex = $edges->getVertices( Traversal::OUTBOUND , '1' , [AQL::FIRST => true] ) ;
     * ```
     *
     * Get all inbound vertices (users) from 'groups/1'
     * ```php
     * $vertices = $edges->getVertices( Traversal::INBOUND , '1' ) ;
     * ```
     *
     * Get all 'any' vertices from 'users/1' (works with a full _id)
     * ```php
     * $vertices = $edges->getVertices( Traversal::ANY , 'users/1' ) ;
     * ```
     *
     * Get 'any' vertices using _key '1'.
     * ```php
     * $vertices = $edges->getVertices( Traversal::ANY , '1' ) ;
     * ```
     * Note : By default, it assumes '1' is a 'user' (AQL::FROM) -> 'users/1'
     *
     * Force 'any' traversal to use '1' as a 'group' (AQL::TO) -> 'groups/1'
     * ```php
     * $vertices = $edges->getVertices
     * (
     *     Traversal::ANY ,
     *     '1' ,
     *     [ AQL::ANY_REF => AQL::TO ]
     * );
     * ```
     *
     * Get outbound vertices using a named graph 'my_graph'
     * ```php
     * $vertices = $edges->getVertices
     * (
     *     Traversal::OUTBOUND ,
     *     'users/1' ,
     *     [ AQL::GRAPH => 'my_graph' ]
     * );
     * ```
     *
     * Get raw outbound vertices sorted by name
     * ```php
     * $vertices = $edges->getVertices
     * (
     *     Traversal::OUTBOUND ,
     *     'users/1' ,
     *     [ AQL::RAW => true, AQL::SORT => 'vertex.name ASC' ]
     * );
     * ```
     *
     * @see PrepareTraversalTrait
     */
    public function getVertices
    (
         string $direction ,
        ?string $vertex    = null ,
         array  $init      = []
    )
    :object|array|null
    {
        [ $bindVars , $filter, $from , $to ] = $this->prepareTraversal($direction, $vertex, $init);

        $target = $init[ AQL::TARGET  ] ?? null ;

        $target = $target instanceof Documents ? $target : null ;
        if ( $direction === Traversal::OUTBOUND )
        {
            $target = $target ?? $to ;
        }
        else if ( $direction === Traversal::INBOUND )
        {
            $target = $target ?? $from ;
        }

        $raw       = $init[ Arango::RAW       ] ?? false ;
        $return    = $init[ Arango::RETURN    ] ?? null  ;
        $schema    = $init[ Arango::SCHEMA    ] ?? null  ;
        $sort      = $init[ Arango::SORT      ] ?? null  ;
        $variables = $init[ Arango::VARIABLES ] ?? [] ;
        $vertexRef = $init[ Arango::DOC_REF   ] ?? AQL::VERTEX ;

        $with   = $this->prepareTraversalWith( $direction , $from , $to , $init ) ;
        $for    = aqlTraversal ( $init , $bindVars ) ;
        $filter = aqlFilter    ( $filter ) ;
        $limit  = aqlLimit     ( $init[ AQL::LIMIT ] ?? 0 , $init[ AQL::OFFSET ] ?? 0 )  ;
        $sort   = aqlSort      (  $sort ?? $target?->prepareSort( $init , docRef : $vertexRef ) ) ;

        if( !$raw && ( $target instanceof Documents ) )
        {
            $schema = $target->schema ;
            $return = $return
                    ? aqlReturn( $return )
                    : $target->returnFields( [ Arango::DOC_REF => $vertexRef ] , $variables ) ;
        }
        else
        {
            $return = aqlReturn( $return ?? $vertexRef ) ; // RETURN vertex (by default)
        }

        // FOR vertex[, edge[, path]]
        //   IN [min[..max]]
        //   OUTBOUND|INBOUND|ANY startVertex
        //   GRAPH graphName
        //   [PRUNE [pruneVariable = ]pruneCondition]
        //   [OPTIONS options]
        // SORT
        // LIMIT
        // RETURN

        $query = compile( [ $with , $for , $variables , $filter , $sort , $limit , $return ] ) ;

        // echo 'getOutboundVertices query: ' . $query . PHP_EOL;
        // echo 'getOutboundVertices binds: ' . json_encode( $bindVars , JSON_UNESCAPED_SLASHES ) . PHP_EOL;

        $documents = $this->getDocuments( $query , $bindVars , schema:$schema ) ;

        return ( $init[ AQL::FIRST ] ?? false ) ? ( $documents[0] ?? null ) : $documents ;
    }
}