<?php

namespace oihana\arango\search\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * The init keys of the {@see \oihana\arango\search\FederatedSearch} service —
 * the federated multi-collection search engine configuration.
 *
 * @package oihana\arango\search\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
class FederatedSearchParam
{
    use ConstantsTrait ;

    /**
     * The collection → model-service-id registry: a map telling the engine
     * which model rebuilds the documents of which collection
     * (`[ 'customers' => 'model.customers', … ]`).
     */
    public const string MODELS = 'models' ;

    /**
     * The federated search specification — what to search across the
     * heterogeneous collections (the fields and the analyzer), reusing the
     * {@see \oihana\arango\models\enums\Search} vocabulary.
     */
    public const string SEARCHABLE = 'searchable' ;

    /**
     * The name of the `search-alias` view to query (the view aggregating one
     * inverted index per collection — see {@see \oihana\arango\db\options\views\SearchAliasView}).
     */
    public const string VIEW = 'view' ;
}
