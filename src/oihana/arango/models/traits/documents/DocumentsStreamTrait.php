<?php

namespace oihana\arango\models\traits\documents;

use DI\DependencyException;
use DI\NotFoundException;
use Generator;
use oihana\exceptions\UnsupportedOperationException;
use oihana\reflect\exceptions\ConstantException;
use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\enums\Arango;
use oihana\exceptions\BindException;
use oihana\arango\models\traits\queries\ListQueryTrait;

/**
 * Provides streaming capabilities for document retrieval from ArangoDB collections.
 *
 * This trait extends DocumentsListTrait to add memory-efficient streaming functionality
 * using PHP generators. Instead of loading all documents into memory at once, documents
 * are yielded one by one, making it ideal for processing large result sets.
 *
 * @package oihana\arango\models\traits\documents
 *
 * @see DocumentsListTrait For the base query building and listing functionality
 */
trait DocumentsStreamTrait
{
    use ListQueryTrait ;

    /**
     * Retrieves documents as a generator stream from the collection with filtering,
     * sorting, and pagination support.
     *
     * This method provides memory-efficient iteration over large result sets by yielding
     * documents one at a time instead of loading them all into memory. Each document is
     * fully processed (schema mapping and alter() transformation) before being yielded.
     *
     * **Key Benefits:**
     * - **Memory Efficient**: Only one document in memory at a time
     * - **Early Processing**: Start processing results before the query completes
     * - **Large Datasets**: Handle millions of documents without memory issues
     * - **Full Feature Set**: Same filtering, sorting, and pagination as list()
     *
     * **Generated AQL Query Structure:**
     * ```aql
     * FOR doc IN @@collection
     *   FILTER doc.active == [1|0] [&& facets] [&& search] [&& conditions]
     *   SORT field1 ASC, field2 DESC
     *   LIMIT offset, limit
     *   RETURN { ...fields }
     * ```
     *
     * **Usage Example:**
     * ```php
     * // Process large dataset efficiently
     * foreach ($model->stream(['limit' => 10000]) as $document) {
     *     // Process one document at a time
     *     processDocument($document);
     * }
     *
     * // With complex filtering
     * $generator = $model->stream([
     *     'active' => true,
     *     'filter' => ['status' => 'published'],
     *     'search' => ['title' => 'PHP'],
     *     'sort'   => ['createdAt' => 'DESC'],
     *     'limit'  => 1000,
     *     'offset' => 0
     * ]);
     * ```
     *
     * @param array $init Configuration array with optional parameters:
     *
     * **Query Binding:**
     * - **`binds`** (`array<string, mixed>`, optional)
     *   Additional AQL bind variables to include in the query.
     *   Default: `[]`
     *
     * **Pagination:**
     * - **`limit`** (`int`, optional)
     *   Maximum number of documents to return. Set to `0` for no limit.
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
     *   Array of facet conditions for filtering. Processed by `prepareFacets()`.
     *   Example: `['category' => 'books', 'year' => 2024]`
     *   Default: `null`
     *
     * - **`conditions`** (`?array`, optional)
     *   Array of custom filter conditions. When provided, this overrides
     *   the automatic combination of active/facets/filter/search conditions.
     *   Default: `null`
     *
     * - **`filter`** (`?array`, optional)
     *   Array of general filter conditions. Processed by `prepareFilter()`.
     *   Example: `['status' => 'published', 'author.verified' => true]`
     *   Default: `null`
     *
     * - **`search`** (`?array`, optional)
     *   Array of search conditions for text-based filtering.
     *   Processed by `prepareSearch()`.
     *   Example: `['title' => 'search term', 'description' => 'keyword']`
     *   Default: `null`
     *
     * **Sorting:**
     * - **`sort`** (`?array`, optional)
     *   Array defining sort criteria. Keys are field names, values are
     *   'ASC' or 'DESC'. Processed by `prepareSort()`.
     *   Example: `['createdAt' => 'DESC', 'title' => 'ASC']`
     *   Default: `null`
     *
     * **Field Selection:**
     * - **`fields`** (`?array<string>`, optional)
     *   Array of specific field names to return for each document.
     *   If not provided, all document fields are returned.
     *   Processed by `returnFields()`.
     *   Example: `['_key', 'title', 'author.name', 'publishedAt']`
     *   Default: `null` (all fields)
     *
     * **Output Transformation:**
     * - **`skin`** (`?string`, optional)
     *   Name of the skin/transformation to apply to result documents.
     *   The skin is applied during the `alter()` phase.
     *   Default: `null`
     *
     * **Query Variables:**
     * - **`variables`** (`?array`, optional)
     *   Additional AQL variables to declare in the query (LET statements).
     *   Default: `[]`
     *
     * **Debugging:**
     * - **`debug`** (`?bool`, optional)
     *   Enable query debugging. When `true`, logs the generated AQL query
     *   and bind variables via `debugQuery()`.
     *   Default: `false`
     *
     * @return Generator<mixed>
     * A PHP generator that yields documents one by one. Each yielded document
     * has already been processed through schema mapping (if configured) and
     * the `alter()` transformation method.
     *
     * @throws ArangoException If there's an error during ArangoDB query execution, such as:
     *                         - Invalid AQL syntax
     *                         - Collection not found
     *                         - Connection issues
     *                         - Query timeout
     * @throws BindException If there's an error binding parameters to the AQL query, such as:
     *                       - Invalid bind variable names
     *                       - Type mismatch in bind values
     *                       - Reserved keyword usage
     * @throws ConstantException
     * @throws ContainerExceptionInterface If there's an error accessing the dependency injection container
     * @throws DependencyException
     * @throws NotFoundExceptionInterface If a required service is not found in the dependency injection container
     * @throws NotFoundException
     * @throws UnsupportedOperationException
     * @throws ReflectionException If a reflection error occurs during internal processing, such as:
     *                            - Schema class not found
     *                            - Invalid schema structure
     *                            - Property access errors during hydration
     *
     * @see list() For loading all documents into memory at once
     * @see buildListQuery() For the query construction logic
     * @see streamDocuments() For the underlying generator implementation
     */
    public function stream( array $init = [] ) :Generator
    {
        $bindVars = $init[ Arango::BINDS ] ?? [] ;
        $limit    = $init[ Arango::LIMIT ] ?? 0 ;
        $query    = $this->buildListQuery( $init , $bindVars ) ;
        yield from $this->streamDocuments
        (
            $query ,
            $bindVars ,
            [ CursorField::FULL_COUNT => (bool) $limit ] ,
            context: $init
        ) ;
    }
}