<?php

namespace oihana\arango\models\traits\queries;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\ActiveTrait;
use oihana\arango\models\traits\aql\FacetTrait;
use oihana\arango\models\traits\aql\FieldsTrait;
use oihana\arango\models\traits\aql\FilterTrait;
use oihana\arango\models\traits\aql\SearchTrait;
use oihana\arango\models\traits\aql\SortTrait;
use oihana\models\traits\ConditionsTrait;
use oihana\exceptions\BindException;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLimit;
use function oihana\arango\db\operations\aqlSort;
use function oihana\core\strings\compile;

/**
 * Provides an ArangoDB query to list retrieval capabilities for document collections.
 *
 * This trait combines multiple AQL-building sub-traits to construct flexible,
 * feature-rich queries for retrieving documents from ArangoDB collections.
 * It supports filtering, searching, sorting, pagination, and field selection.
 *
 * @author Marc Alcaraz (eKameleon)
 * @since 1.0.0
 * @package oihana\arango\models\traits\queries
 */
trait ListQueryTrait
{
    use ActiveTrait ,
        ConditionsTrait ,
        FacetTrait ,
        FieldsTrait ,
        FilterTrait ,
        SearchTrait ,
        SortTrait ;

    /**
     * Builds an AQL query for listing documents with comprehensive filtering, sorting, and pagination.
     *
     * This method orchestrates the construction of a complete AQL query by combining
     * various query components (FOR, FILTER, SORT, LIMIT, RETURN) based on the
     * provided initialization parameters. It delegates to specialized methods for
     * each query aspect (filtering, sorting, etc.) and compiles them into a single
     * executable AQL statement.
     *
     * **Generated Query Structure:**
     * ```aql
     * FOR doc IN @@collection
     *   [LET variables...]
     *   FILTER doc.active == [1|0] [&& facets] [&& filter] [&& search] [&& conditions]
     *   SORT field1 ASC, field2 DESC
     *   LIMIT offset, limit
     *   RETURN { ...fields }
     * ```
     *
     * **Query Building Process:**
     * 1. Extract configuration parameters (limit, offset, variables, debug)
     * 2. Build FOR clause with collection binding
     * 3. Construct FILTER clause combining active, facets, filter, and search
     * 4. Generate SORT clause from sort criteria
     * 5. Add LIMIT/OFFSET for pagination
     * 6. Define RETURN clause with field selection
     * 7. Compile all components into final query
     * 8. Optionally debug the generated query
     *
     * **Usage Example:**
     * ```php
     * $bindVars = [];
     * $query = $model->buildListQuery([
     *     'active'  => true,
     *     'filter'  => ['status' => 'published'],
     *     'sort'    => ['createdAt' => 'DESC'],
     *     'limit'   => 50,
     *     'offset'  => 0,
     *     'fields'  => ['_key', 'title', 'author'],
     *     'debug'   => true
     * ], $bindVars);
     * // Returns: "FOR doc IN @@collection FILTER doc.active == 1 && ..."
     * // $bindVars now contains: ['@collection' => 'myCollection', ...]
     * ```
     *
     * @param array $init Configuration array with optional parameters:
     *
     * **Query Variables:**
     * - **`variables`** (`array`, optional)
     *   Additional AQL LET statements to declare variables in the query.
     *   Example: `['total = LENGTH(doc.items)', 'avg = SUM(doc.prices) / total']`
     *   Default: `[]`
     *
     * **Pagination:**
     * - **`limit`** (`int`, optional)
     *   Maximum number of documents to return. Set to `0` for no limit.
     *   Example: `50`
     *   Default: `0`
     *
     * - **`offset`** (`int`, optional)
     *   Number of documents to skip before returning results.
     *   Useful for pagination when combined with `limit`.
     *   Example: `100` (skip first 100 documents)
     *   Default: `0`
     *
     * **Filtering:**
     * - **`active`** (`?bool`, optional)
     *   Filter by document active status. `true` for active only,
     *   `false` for inactive only, `null` to ignore this filter.
     *   Processed by `prepareActive()`.
     *   Default: `null`
     *
     * - **`facets`** (`?array`, optional)
     *   Array of facet-based filter conditions. Facets are typically used
     *   for categorical filtering (categories, tags, types, etc.).
     *   Processed by `prepareFacets()`.
     *   Example: `['category' => 'electronics', 'brand' => 'Apple']`
     *   Default: `null`
     *
     * - **`conditions`** (`?array`, optional)
     *   Array of custom AQL filter conditions. When provided, this completely
     *   overrides the automatic combination of active/facets/filter/search.
     *   Use this for complex custom filtering logic.
     *   Example: `['doc.price > 100', 'doc.stock > 0']`
     *   Default: `null`
     *
     * - **`filter`** (`?array`, optional)
     *   Array of general filter conditions applied as key-value pairs.
     *   Processed by `prepareFilter()`.
     *   Example: `['status' => 'published', 'author.verified' => true]`
     *   Default: `null`
     *
     * - **`search`** (`?array`, optional)
     *   Array of search conditions for text-based filtering.
     *   Typically used for full-text or partial string matching.
     *   Processed by `prepareSearch()`.
     *   Example: `['title' => 'laptop', 'description' => 'gaming']`
     *   Default: `null`
     *
     * **Sorting:**
     * - **`sort`** (`?array`, optional)
     *   Array defining sort criteria. Keys are field names (support dot notation),
     *   values are 'ASC' or 'DESC'. Multiple fields create compound sorting.
     *   Processed by `prepareSort()`.
     *   Example: `['priority' => 'DESC', 'createdAt' => 'ASC']`
     *   Default: `null`
     *
     * **Field Selection:**
     * - **`fields`** (`?array<string>`, optional)
     *   Array of field names to include in returned documents.
     *   Supports dot notation for nested fields. If not provided,
     *   all document fields are returned.
     *   Processed by `returnFields()`.
     *   Example: `['_key', 'title', 'author.name', 'metadata.tags']`
     *   Default: `null` (returns all fields)
     *
     * **Output Transformation:**
     * - **`skin`** (`?string`, optional)
     *   Name of the skin/transformation to apply to result documents.
     *   Applied during the `alter()` phase after query execution.
     *   Example: `'summary'`, `'detailed'`, `'api'`
     *   Default: `null`
     *
     * **Query Binding:**
     * - **`binds`** (`array<string, mixed>`, optional)
     *   Additional AQL bind variables to include in the query.
     *   Merged with auto-generated bind variables.
     *   Example: `['minPrice' => 100, 'category' => 'books']`
     *   Default: `[]`
     *
     * **Debugging:**
     * - **`debug`** (`bool`, optional)
     *   Enable query debugging. When `true`, the generated AQL query
     *   and bind variables are logged via `debugQuery()` before returning.
     *   Default: `false`
     *
     * @param array $bindVars
     * Reference to an array where bind variables will be collected.
     * This array is populated during query construction with all necessary
     * bind variables (collection name, filter values, search terms, etc.).
     * After the method returns, this array contains all variables needed
     * to execute the query.
     *
     * **Example of populated bindVars:**
     * ```php
     * [
     *     '@collection' => 'products',
     *     'active' => 1,
     *     'status' => 'published',
     *     'minPrice' => 100
     * ]
     * ```
     *
     * @return string
     * The compiled AQL query string ready for execution.
     * The query is a complete, executable AQL statement that can be
     * passed to ArangoDB along with the populated `$bindVars`.
     *
     * **Example Output:**
     * ```aql
     * FOR doc IN @@collection
     *   FILTER doc.active == @active && doc.status == @status
     *   SORT doc.createdAt DESC
     *   LIMIT 0, 50
     *   RETURN { _key: doc._key, title: doc.title, price: doc.price }
     * ```
     *
     * @throws BindException If there's an error during bind variable processing, such as:
     * - Invalid bind variable names (reserved keywords, invalid characters)
     * - Type conversion failures
     * - Collection binding errors
     * @throws ConstantException
     * @throws ContainerExceptionInterface If there's an error accessing the dependency injection container
     * while resolving services needed during query construction
     * @throws NotFoundExceptionInterface If a required service (like a filter handler) is not found
     * in the dependency injection container
     * @throws ReflectionException If a reflection error occurs during internal processing, such as:
     * - Analyzing filter or sort field structures
     * - Inspecting schema classes for field validation
     * - Dynamic method invocation failures
     * @throws UnsupportedOperationException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ValidationException
     * 
     * @see list() For executing the built query and retrieving results
     * @see prepareActive() For active status filter preparation
     * @see prepareFacets() For facet filter preparation
     * @see prepareFilter() For general filter preparation
     * @see prepareSearch() For search condition preparation
     * @see prepareSort() For sort criteria preparation
     * @see returnFields() For field selection preparation
     */
    public function buildListQuery( array $init = [] , array &$bindVars = [] ) :string
    {
        $debug     = $init[ Arango::DEBUG     ] ?? $this->debug ;
        $limit     = $init[ Arango::LIMIT     ] ?? 0  ;
        $offset    = $init[ Arango::OFFSET    ] ?? 0  ;
        $variables = $init[ Arango::VARIABLES ] ?? [] ;

        $for   = aqlFor( [ AQL::IN => $this->bindCollection($bindVars ) ] ) ;
        $limit = aqlLimit  ( $limit , $offset ) ;
        $sort  = aqlSort( $this->prepareSort( $init ) ) ;

        $filter = aqlFilter
        ([
            ...$this->conditions ,
            $this->prepareActive( $init , $bindVars ) ,
            $this->prepareFacets( $init , $bindVars ) ,
            $this->prepareFilter( $init , $bindVars ) ,
            $this->prepareSearch( $init , $bindVars ) ,
            ...( $init[ AQL::CONDITIONS ] ?? [] )
        ]);

        $return = $this->returnFields( $init , $variables ) ;

        $query = compile
        ([
            $for ,
            $filter ,
            $variables ,
            $sort ,
            $limit ,
            $return
        ]) ;

        if( $debug === true )
        {
            $this->debugQuery( __METHOD__ , $query , $bindVars ) ;
        }

        // $this->debugQuery( __METHOD__ , $query , $bindVars ) ;

        return $query ;
    }
}