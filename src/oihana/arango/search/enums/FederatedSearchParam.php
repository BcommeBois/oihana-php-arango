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
     * The default discriminator field used by a composite {@see MODELS} entry
     * when its {@see DISCRIMINATOR} sub-key is omitted — the schema.org
     * `additionalType`.
     */
    public const string DEFAULT_DISCRIMINATOR = 'additionalType' ;

    /**
     * The composite {@see MODELS} sub-key naming the document field that
     * discriminates a polymorphic collection (defaults to
     * {@see DEFAULT_DISCRIMINATOR}).
     */
    public const string DISCRIMINATOR = 'key' ;

    /**
     * The composite {@see MODELS} sub-key holding the fallback model-service-id
     * used when no mapped type matches; absent/null means the hit is dropped.
     */
    public const string FALLBACK = 'default' ;

    /**
     * The composite {@see MODELS} sub-key holding the `type => model-service-id`
     * mapping. Declaration order is the resolution priority for a document that
     * carries several types.
     */
    public const string MAP = 'map' ;

    /**
     * The collection → model registry. Each value is either a **model-service-id**
     * string (`'customers' => 'model.customers'`) or, for a **polymorphic**
     * collection, a composite spec routing by a discriminator field:
     * `[ DISCRIMINATOR => 'additionalType', MAP => [ '<type>' => 'model.x', … ], FALLBACK => 'model.y' ]`.
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
