<?php

namespace oihana\arango\search ;

use DI\Container ;

use oihana\arango\search\enums\FederatedSearchParam ;

use oihana\enums\Char ;
use oihana\traits\ContainerTrait ;

/**
 * The federated multi-collection search engine.
 *
 * One search bar over several collections at once (customers, products,
 * sellers, places, …), returning a single list ranked by relevance. The hard
 * part is **not** finding the matches — the `search-alias` view substrate
 * already searches every collection in one go — but rebuilding heterogeneous
 * results: a customer, a product and a place have different shapes (fields,
 * joins, skins, permissions). The engine therefore works in two stages, like
 * a librarian who first hands you a ranked list of call numbers, then fetches
 * each book at its own shelf:
 *
 * 1. **Find** — one SEARCH over the `search-alias` view returns, for every
 *    match, only its source collection, its `_key` and its relevance score
 *    (BM25), ranked and paginated (Lot C2).
 * 2. **Rebuild** — the matches are grouped by collection and each group is
 *    re-hydrated by the model that owns it (resolved through the
 *    collection → model registry), reusing that model's own projection
 *    pipeline; the results are then merged back in score order (Lot C3).
 *
 * This is the read-only orchestrator. It is **not** a {@see \oihana\arango\models\Documents}
 * subclass — it owns no single collection — but a standalone, container-aware
 * service: the container resolves the per-collection models at rebuild time.
 *
 * Lot C1 lays the skeleton and the registry; the two stages, the per-source
 * permission gate and the HTTP triplet land in the later lots.
 *
 * @package oihana\arango\search
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
class FederatedSearch
{
    /**
     * Creates a new FederatedSearch engine.
     *
     * @param Container            $container The DI container, used to resolve the per-collection models.
     * @param array<string, mixed> $init      The engine options:
     * <ul>
     *   <li>{@see FederatedSearchParam::VIEW}       — the `search-alias` view name to query.</li>
     *   <li>{@see FederatedSearchParam::SEARCHABLE} — the federated search spec (fields + analyzer).</li>
     *   <li>{@see FederatedSearchParam::MODELS}     — the `collection => model-service-id` registry.</li>
     * </ul>
     */
    public function __construct( Container $container , array $init = [] )
    {
        $this->container = $container ;

        $this->initializeView      ( $init )
             ->initializeSearchable( $init )
             ->initializeModels    ( $init ) ;
    }

    use ContainerTrait ;

    /**
     * The collection → model-service-id registry — the directory telling the
     * engine which model rebuilds the documents of which collection.
     *
     * @var array<string, string>
     */
    public array $models = [] ;

    /**
     * The federated search specification (fields + analyzer) applied uniformly
     * across the aggregated collections.
     *
     * @var array<string, mixed>
     */
    public array $searchable = [] ;

    /**
     * The name of the `search-alias` view to query, or null when none is set.
     *
     * @var string|null
     */
    public ?string $view = null ;

    /**
     * Returns the name of the `search-alias` view the engine queries.
     *
     * @return string|null
     */
    public function getViewName() : ?string
    {
        return $this->view ;
    }

    /**
     * Runs a federated search and returns the matching documents, rebuilt by
     * their own model and ranked by relevance.
     *
     * Skeleton (Lot C1): the two stages — *find* (Lot C2) and *rebuild*
     * (Lot C3) — are not wired yet, so an empty result set is returned.
     *
     * @param array<string, mixed> $init The request options (the query term, pagination, the authorizer, …).
     *
     * @return array<int, mixed> The flat, score-ranked result list.
     */
    public function search( array $init = [] ) : array
    {
        return [] ;
    }

    /**
     * Normalises the collection → model registry: only the entries whose
     * collection name and model-service-id are both non-empty strings are kept.
     *
     * @param array<string, mixed> $init
     *
     * @return static
     */
    protected function initializeModels( array $init ) : static
    {
        $models = $init[ FederatedSearchParam::MODELS ] ?? [] ;

        $registry = [] ;

        if ( is_array( $models ) )
        {
            foreach ( $models as $collection => $modelId )
            {
                if ( is_string( $collection ) && $collection !== Char::EMPTY
                  && is_string( $modelId )    && $modelId    !== Char::EMPTY )
                {
                    $registry[ $collection ] = $modelId ;
                }
            }
        }

        $this->models = $registry ;

        return $this ;
    }

    /**
     * Reads the federated search spec, ignoring a non-array declaration.
     *
     * @param array<string, mixed> $init
     *
     * @return static
     */
    protected function initializeSearchable( array $init ) : static
    {
        $searchable = $init[ FederatedSearchParam::SEARCHABLE ] ?? [] ;

        $this->searchable = is_array( $searchable ) ? $searchable : [] ;

        return $this ;
    }

    /**
     * Reads the `search-alias` view name, keeping only a non-empty string.
     *
     * @param array<string, mixed> $init
     *
     * @return static
     */
    protected function initializeView( array $init ) : static
    {
        $view = $init[ FederatedSearchParam::VIEW ] ?? null ;

        $this->view = is_string( $view ) && $view !== Char::EMPTY ? $view : null ;

        return $this ;
    }
}
