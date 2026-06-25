<?php

namespace oihana\arango\models\traits\documents;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\db\results\ExplainResult;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;
use oihana\exceptions\BindException;

/**
 * Provides list retrieval capabilities for ArangoDB document collections.
 *
 * This trait combines multiple AQL-building sub-traits to construct flexible,
 * feature-rich queries for retrieving documents from ArangoDB collections.
 * It supports filtering, searching, sorting, pagination, and field selection.
 *
 * **Composed Traits:**
 * - `ArangoTrait`: Core ArangoDB database operations
 * - `ActiveTrait`: Active/inactive status filtering
 * - `BindTrait`: Collection binding and bind variable management
 * - `FacetTrait`: Faceted filtering capabilities
 * - `FieldsTrait`: Field selection and projection
 * - `FilterTrait`: General filtering conditions
 * - `SearchTrait`: Text-based search functionality
 * - `SortTrait`: Result sorting capabilities
 *
 * @package oihana\arango\models\traits\documents
 *
 * @see ArangoTrait For core database operations
 * @see DocumentsStreamTrait For memory-efficient streaming alternative
 */
trait DocumentsListTrait
{
    use ArangoTrait ,
        ListQueryTrait ;

    /**
     * Explains the query that {@see list()} would run for the same `$init`, **without
     * executing it**. Use it to check which indexes the list query actually uses, the
     * optimizer rules that fire, and the estimated cost — straight from the same
     * filter / facet / search / sort / pagination input.
     *
     * @param array $init The same input array accepted by {@see list()}.
     *
     * @return ExplainResult The typed execution plan.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     *
     * @see list() For the executed counterpart.
     */
    public function explainList( array $init = [] ) :ExplainResult
    {
        $bindVars = $init[ Arango::BINDS ] ?? [] ;
        $query    = $this->buildListQuery( $init , $bindVars ) ;
        return $this->explain( $query , $bindVars ) ;
    }

    /**
     * Retrieves a list of documents from the collection with filtering, sorting, and pagination.
     *
     * This is the main entry point for fetching multiple documents from an ArangoDB collection.
     * It constructs an AQL query using `buildListQuery()`, executes it, and returns all
     * matching documents loaded into memory. Each document is processed through schema mapping
     * (if configured) and transformation via the `alter()` method.
     *
     * **When to Use:**
     * - When you need all results in memory for further processing
     * - For small to medium result sets (< 10,000 documents)
     * - When you need to count, sort, or filter results in PHP
     * - For API responses that return complete datasets
     *
     * **Performance Considerations:**
     * - All documents are loaded into memory at once
     * - For large datasets (> 10,000 documents), consider using `stream()` instead
     * - Use `limit` and `offset` for pagination to control memory usage
     * - Use `fields` to reduce data transfer by selecting only needed fields
     *
     * **Generated AQL Query Structure:**
     * ```aql
     * FOR doc IN @@collection
     *   FILTER doc.active == [1|0] [&& facets] [&& filter] [&& search] [&& conditions]
     *   SORT field1 ASC, field2 DESC
     *   LIMIT offset, limit
     *   RETURN { ...fields }
     * ```
     *
     * **Usage Examples:**
     *
     * *Basic usage:*
     * ```php
     * $documents = $model->list();
     * // Returns all documents from the collection
     * ```
     *
     * *With filtering and pagination:*
     * ```php
     * $documents = $model->list([
     *     'active' => true,
     *     'filter' => ['status' => 'published'],
     *     'limit'  => 50,
     *     'offset' => 0
     * ]);
     * // Returns first 50 active, published documents
     * ```
     *
     * *Complex query with all features:*
     * ```php
     * $documents = $model->list([
     *     'active'  => true,
     *     'facets'  => ['category' => 'electronics'],
     *     'filter'  => ['price' => ['$gte' => 100]],
     *     'search'  => ['title' => 'laptop'],
     *     'sort'    => ['priority' => 'DESC', 'createdAt' => 'ASC'],
     *     'limit'   => 100,
     *     'offset'  => 0,
     *     'fields'  => ['_key', 'title', 'price', 'stock'],
     *     'skin'    => 'api'
     * ]);
     * ```
     *
     * *With custom binds:*
     * ```php
     * $documents = $model->list([
     *     'conditions' => ['doc.price >= @minPrice', 'doc.stock > 0'],
     *     'binds'      => ['minPrice' => 500]
     * ]);
     * ```
     *
     * @param array $init Configuration array with optional parameters:
     *
     * **Query Binding:**
     * - **`binds`** (`array<string, mixed>`, optional)
     *   Additional AQL bind variables to include in the query.
     *   Merged with auto-generated bind variables.
     *   Default: `[]`
     *
     * **Pagination:**
     * - **`limit`** (`int`, optional)
     *   Maximum number of documents to return. Set to `0` for no limit.
     *   When > 0, enables full count calculation for pagination metadata.
     *   Default: `0`
     *
     * - **`offset`** (`int`, optional)
     *   Number of documents to skip before returning results.
     *   Useful for pagination when combined with `limit`.
     *   Default: `0`
     *
     * **Filtering:**
     * - **`active`** (`?bool`, optional)
     *   Filter by active status. `true` returns only active documents,
     *   `false` returns only inactive documents, `null` ignores this filter.
     *   Default: `null`
     *
     * - **`facets`** (`?array`, optional)
     *   Array of facet conditions for categorical filtering.
     *   Processed by `prepareFacets()`.
     *   Example: `['category' => 'books', 'language' => 'en']`
     *   Default: `null`
     *
     * - **`conditions`** (`?array`, optional)
     *   Array of custom filter conditions. When provided, this overrides
     *   the automatic combination of active/facets/filter/search.
     *   Example: `['doc.price > 100', 'doc.stock > 0']`
     *   Default: `null`
     *
     * - **`filter`** (`?array`, optional)
     *   Array of general filter conditions. Processed by `prepareFilter()`.
     *   Example: `['status' => 'published', 'featured' => true]`
     *   Default: `null`
     *
     * - **`search`** (`?array`, optional)
     *   Array of search conditions for text-based filtering.
     *   Processed by `prepareSearch()`.
     *   Example: `['title' => 'search term', 'tags' => 'important']`
     *   Default: `null`
     *
     * **Sorting:**
     * - **`sort`** (`?array`, optional)
     *   Array defining sort criteria. Keys are field names, values are 'ASC' or 'DESC'.
     *   Processed by `prepareSort()`.
     *   Example: `['createdAt' => 'DESC', 'title' => 'ASC']`
     *   Default: `null`
     *
     * **Field Selection:**
     * - **`fields`** (`?array<string>`, optional)
     *   Array of specific field names to return. Supports dot notation.
     *   If not provided, all document fields are returned.
     *   Processed by `returnFields()`.
     *   Example: `['_key', 'title', 'author.name', 'publishedAt']`
     *   Default: `null` (all fields)
     *
     * **Output Transformation:**
     * - **`skin`** (`?string`, optional)
     *   Name of the skin/transformation to apply to result documents.
     *   Applied during the `alter()` phase.
     *   Example: `'summary'`, `'detailed'`
     *   Default: `null`
     *
     * **Query Variables:**
     * - **`variables`** (`?array`, optional)
     *   Additional AQL LET statements to declare in the query.
     *   Default: `[]`
     *
     * **Debugging:**
     * - **`debug`** (`?bool`, optional)
     *   Enable query debugging. When `true`, logs the AQL query and bind variables.
     *   Default: `false`
     *
     * @return array
     * An array of matching documents. Each document is:
     * - Retrieved from ArangoDB based on the query
     * - Mapped to the configured schema class (if set via `$this->schema`)
     * - Transformed via the `alter()` method (applies skins, conversions, etc.)
     *
     * Returns an empty array if:
     * - No documents match the query criteria
     * - The collection is empty
     * - All matching documents are filtered out by conditions
     *
     * **Example Return Structure:**
     * ```php
     * [
     *     0 => ProductSchema {
     *         _key: 'product_001',
     *         title: 'Gaming Laptop',
     *         price: 1299.99,
     *         stock: 5
     *     },
     *     1 => ProductSchema {
     *         _key: 'product_002',
     *         title: 'Office Laptop',
     *         price: 899.99,
     *         stock: 12
     *     }
     * ]
     * ```
     *
     * @throws ArangoException If there's an error during ArangoDB query execution, such as:
     * - Invalid AQL syntax in the generated query
     * - Collection not found or not accessible
     * - Connection timeout or network issues
     * - Insufficient permissions
     * - Query timeout (exceeded max execution time)
     * @throws BindException If there's an error binding parameters to the AQL query, such as:
     * - Invalid bind variable names (reserved keywords, special characters)
     * - Type mismatch in bind values
     * - Collection binding errors
     * @throws ConstantException
     * @throws ContainerExceptionInterface If there's an error accessing the dependency injection container
     * while resolving services needed for query execution or result processing
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface If a required service is not found in the dependency injection container
     * @throws ReflectionException If a reflection error occurs during processing, such as:
     * - Schema class not found or not accessible
     * - Invalid schema structure
     * - Property hydration errors
     * - Method invocation failures during alter()
     * @throws UnsupportedOperationException
     * @throws ValidationException
     *
     * @see buildListQuery() For the query construction logic
     * @see getDocuments() For the underlying execution and transformation
     * @see stream() For memory-efficient streaming alternative
     * @see foundRows() For getting total count without limit (when limit is set)
     */
    public function list( array $init = [] ) :array
    {
        $bindVars = $init[ Arango::BINDS ] ?? [] ;
        $limit    = $init[ Arango::LIMIT ] ?? 0 ;
        $query    = $this->buildListQuery( $init , $bindVars ) ;

        return $this->getDocuments( $query , $bindVars , $this->profileOptions( $init , [ CursorField::FULL_COUNT => (bool) $limit ] ) , context: $init ) ;
    }

}