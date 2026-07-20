<?php

namespace oihana\arango\models\traits\documents;

use oihana\arango\models\traits\queries\CountQueryTrait;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\ArangoTrait;
use oihana\exceptions\BindException;

trait DocumentsCountTrait
{
    use ArangoTrait ,
        CountQueryTrait ;

    /**
     * Count the number of documents in a collection.
     *
     * Supports both optimized (unfiltered) and filtered modes.
     *
     * This method generates and executes an AQL query to return the total number of documents.
     *
     * It supports two primary modes:
     *
     * 1. **Filtered Count** (default) :
     * Counts documents based on various criteria such as 'active' status, facets, general filters, and search terms.
     * This mode constructs a more complex AQL query, example :
     * <code>
     * RETURN LENGTH( FOR doc IN collection FILTER doc.active == [1|0] [ && facets ] [ && search ] [ && conditions ] RETURN 1 )
     * </code>
     *
     * 2. **Optimized Count** (Unfiltered)  :
     * Provides a fast count of all documents in the collection, bypassing any filtering. Useful for quick total counts.
     * <code>
     * RETURN LENGTH( collection )
     * </code>
     *
     * @param array{ optimized?:array , binds?:array , active?:bool , facets?:array , search?:array , conditions?:array } $init
     * An associative array of optional settings to define the counting behavior:
     * - **`optimized`** (`bool`, optional): If `true`, a faster, unfiltered count of the entire collection
     * is performed. If `false` (default), the method applies all specified filters.
     * - **`binds`** (`array<string, mixed>`, optional): An array of AQL bind variables to be directly included
     * in the query. Primarily used for filtered counts.
     * - **`active`** (`?bool`, optional): Filters documents based on their 'active' status. Only applies if
     * `optimized` is `false`. If `true`, only active documents are counted. If `false`, only inactive documents.
     * If `null` (or not set), this filter is not applied.
     * - **`facets`** (`?array`, optional): An array of conditions to apply as 'facets' for filtering. Only
     * applies if `optimized` is `false`. Handled by `prepareFacets()`.
     * - **`filter`** (`?array`, optional): An array of general filter conditions to apply. Only applies if
     * `optimized` is `false`. Handled by `prepareFilter()`.
     * - **`search`** (`?array`, optional): An array of search conditions to apply. Only applies if
     * `optimized` is `false`. Handled by `prepareSearch()`.
     * - **`conditions`** (`?array`, optional): An array of additional AQL filter conditions to append to
     * the query. Only applies if `optimized` is `false`.
     *
     * @return int The number of documents matching the criteria. Returns `0` if in mock mode.
     *
     * @throws ArangoException If there's an issue with the ArangoDB query execution.
     * @throws BindException If there's an error binding parameters to the AQL query.
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException If a reflection error occurs (e.g., during internal AQL building).
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ConstantException
     *
     * @example
     * ```php
     * $model->count() ;
     * $model->count( [ Arango::OPTIMIZED => true ] ) ;
     * $model->count( [ Arango::FACETS => [ ... ] , Arango::FILTER = [ ... ] ] ] ) ;
     * ```
     */
    public function count( array $init = [] ):int
    {
        $bindVars = $init[ Arango::BINDS ] ?? [] ;
        $debug    = $init[ Arango::DEBUG ] ?? $this->debug ;
        $query    = $this->buildCountQuery( $init , $bindVars ) ;

        if( $debug === true )
        {
            $this->debugQuery( __METHOD__ , $query , $bindVars ) ;
            if( $this->isMock( $init ) )
            {
                return 0 ;
            }
        }

        return $this->getFirstResult( $query , $bindVars ) ;
    }
}