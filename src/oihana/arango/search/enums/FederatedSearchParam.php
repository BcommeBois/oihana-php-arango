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
     * The collection → required permission subject(s) registry: a map declaring
     * which permission a collection demands to be searchable
     * (`[ 'customers' => 'customers:list', 'users' => [ 'users:list', 'users:admin' ] ]`,
     * a string or an OR-list, mirroring {@see \oihana\arango\enums\Field::REQUIRES}).
     * A collection absent from this map is **public** (searchable by everyone);
     * the gate is evaluated by {@see \oihana\arango\models\helpers\isAuthorized()}.
     */
    public const string REQUIRES = 'requires' ;

    /**
     * The federated search specification — what to search across the
     * heterogeneous collections (the fields and the analyzer), reusing the
     * {@see \oihana\arango\models\enums\Search} vocabulary.
     */
    public const string SEARCHABLE = 'searchable' ;

    /**
     * The default skin (projection variant) applied when rebuilding the
     * matched documents — overridden per request by `?skin=`, and itself
     * defaulting to {@see \oihana\controllers\enums\Skin::DEFAULT} when unset.
     */
    public const string SKIN = 'skin' ;

    /**
     * The name of the `search-alias` view to query (the view aggregating one
     * inverted index per collection — see {@see \oihana\arango\db\options\views\SearchAliasView}).
     */
    public const string VIEW = 'view' ;
}
