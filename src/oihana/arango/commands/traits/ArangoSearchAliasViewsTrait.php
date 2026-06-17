<?php

namespace oihana\arango\commands\traits;

use oihana\arango\db\options\views\SearchAliasView;

/**
 * The database-level registry of declared `search-alias` views, consumed by the
 * `arango:views` action of `command:arangodb` — the federated counterpart of
 * {@see ArangoAnalyzersTrait}.
 *
 * Unlike an `arangosearch` view (owned by a single model), a `search-alias` view
 * aggregates one `inverted` index across several collections, so it belongs to no
 * single model and is declared **once** here. Supplied via the `searchAliasViews`
 * init key ({@see \oihana\arango\commands\enums\ArangoCommandParam::SEARCH_ALIAS_VIEWS}).
 *
 * The registry is a flat list of {@see SearchAliasView}. As a convenience a
 * **single** `SearchAliasView` is tolerated in place of a one-element list —
 * {@see getSearchAliasViews()} normalizes it.
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
trait ArangoSearchAliasViewsTrait
{
    /**
     * The declared search-alias views — a list of {@see SearchAliasView}
     * (a single one is tolerated in place of a one-element list).
     *
     * @var array<int, SearchAliasView>|SearchAliasView
     */
    public array|SearchAliasView $searchAliasViews = [] ;

    /**
     * Returns the declared search-alias views normalized to a flat
     * {@see SearchAliasView} list: a lone `SearchAliasView` becomes a
     * one-element list, and any entry that is not a `SearchAliasView` is
     * dropped (defensive against a malformed declaration).
     *
     * @return array<int, SearchAliasView>
     */
    public function getSearchAliasViews() : array
    {
        $views = $this->searchAliasViews instanceof SearchAliasView ? [ $this->searchAliasViews ] : $this->searchAliasViews ;

        return array_values( array_filter( $views , static fn( mixed $view ) : bool => $view instanceof SearchAliasView ) ) ;
    }
}
