<?php

namespace oihana\arango\db\results;

use oihana\arango\db\enums\ExplainField;

/**
 * A typed view over the `POST /_api/explain` response returned by
 * {@see \oihana\arango\clients\Database::explain()}.
 *
 * The raw plan tree is deep and version-dependent, so it is kept available through
 * {@see self::raw()} / {@see self::plan()}; the accessors here surface the parts you
 * usually need when tuning a query: the optimizer rules that fired, the collections
 * touched, the estimated cost, and — most importantly — the indexes the query
 * actually uses ({@see self::indexesUsed()}).
 *
 * @package oihana\arango\db\results
 * @since   1.1.0
 * @author  Marc Alcaraz
 */
readonly class ExplainResult
{
    /**
     * @param array<string,mixed> $data The raw `/_api/explain` response body.
     */
    public function __construct( public array $data )
    {
    }

    /**
     * The raw execution plan (`nodes`, `rules`, `collections`, `estimatedCost`, …),
     * or an empty array when the server returned none.
     *
     * @return array<string,mixed>
     */
    public function plan() : array
    {
        $plan = $this->data[ ExplainField::PLAN ] ?? null ;
        return is_array( $plan ) ? $plan : [] ;
    }

    /**
     * The names of the optimizer rules that were applied to the query.
     *
     * @return array<int,string>
     */
    public function rules() : array
    {
        return array_values( (array) ( $this->plan()[ ExplainField::RULES ] ?? [] ) ) ;
    }

    /**
     * The names of the collections accessed by the query.
     *
     * @return array<int,string>
     */
    public function collections() : array
    {
        $collections = (array) ( $this->plan()[ ExplainField::COLLECTIONS ] ?? [] ) ;
        return array_values( array_map( fn( $c ) => (string) ( $c[ ExplainField::NAME ] ?? '' ) , $collections ) ) ;
    }

    /**
     * The ordered list of execution node types (`SingletonNode`, `IndexNode`, …).
     *
     * @return array<int,string>
     */
    public function nodeTypes() : array
    {
        $nodes = (array) ( $this->plan()[ ExplainField::NODES ] ?? [] ) ;
        return array_values( array_map( fn( $n ) => (string) ( $n[ ExplainField::TYPE ] ?? '' ) , $nodes ) ) ;
    }

    /**
     * The indexes the query actually uses, gathered from every `IndexNode` of the plan.
     *
     * @return array<int,IndexUse>
     */
    public function indexesUsed() : array
    {
        $used  = [] ;
        $nodes = (array) ( $this->plan()[ ExplainField::NODES ] ?? [] ) ;

        foreach ( $nodes as $node )
        {
            if ( ( $node[ ExplainField::TYPE ] ?? null ) !== ExplainField::INDEX_NODE )
            {
                continue ;
            }

            $collection = isset( $node[ ExplainField::COLLECTION ] ) ? (string) $node[ ExplainField::COLLECTION ] : null ;

            foreach ( (array) ( $node[ ExplainField::INDEXES ] ?? [] ) as $index )
            {
                $used[] = IndexUse::fromArray( (array) $index , $collection ) ;
            }
        }

        return $used ;
    }

    /**
     * Whether the query uses at least one index (i.e. it is not a full collection scan).
     */
    public function usesIndex() : bool
    {
        return $this->indexesUsed() !== [] ;
    }

    /**
     * The optimizer's estimated total cost of the plan.
     */
    public function estimatedCost() : float
    {
        return (float) ( $this->plan()[ ExplainField::ESTIMATED_COST ] ?? 0.0 ) ;
    }

    /**
     * The optimizer's estimated number of result items.
     */
    public function estimatedNrItems() : int
    {
        return (int) ( $this->plan()[ ExplainField::ESTIMATED_NR_ITEMS ] ?? 0 ) ;
    }

    /**
     * Whether the query writes data (INSERT / UPDATE / REPLACE / REMOVE / UPSERT).
     */
    public function isModificationQuery() : bool
    {
        return (bool) ( $this->plan()[ ExplainField::IS_MODIFICATION_QUERY ] ?? false ) ;
    }

    /**
     * The optimizer warnings raised while planning the query.
     *
     * @return array<int,mixed>
     */
    public function warnings() : array
    {
        return array_values( (array) ( $this->data[ ExplainField::WARNINGS ] ?? [] ) ) ;
    }

    /**
     * Whether the query result could be served from the query results cache.
     */
    public function isCacheable() : bool
    {
        return (bool) ( $this->data[ ExplainField::CACHEABLE ] ?? false ) ;
    }

    /**
     * The raw, unmodified `/_api/explain` response body.
     *
     * @return array<string,mixed>
     */
    public function raw() : array
    {
        return $this->data ;
    }
}
