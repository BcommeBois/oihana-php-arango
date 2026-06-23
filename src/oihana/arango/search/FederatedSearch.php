<?php

namespace oihana\arango\search ;

use DI\Container ;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;
use oihana\arango\clients\cursor\enums\CursorField ;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\Comparator ;
use oihana\arango\db\enums\Logic ;
use oihana\arango\enums\Arango ;
use oihana\arango\enums\Field ;
use oihana\arango\models\Documents ;
use oihana\arango\models\enums\Search ;
use oihana\arango\search\enums\FederatedSearchParam ;

use oihana\controllers\enums\Skin ;
use oihana\enums\Char ;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use oihana\traits\ContainerTrait ;

use org\schema\constants\Schema ;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use function oihana\arango\db\binds\aqlBind ;
use function oihana\arango\db\binds\aqlBindCollection ;
use function oihana\arango\db\functions\documents\merge ;
use function oihana\arango\db\functions\documents\parseIdentifier ;
use function oihana\arango\db\functions\strings\tokens ;
use function oihana\arango\db\helpers\aqlDocument ;
use function oihana\arango\db\helpers\assertAttributeName ;
use function oihana\arango\db\operations\aqlFilter ;
use function oihana\arango\db\operations\aqlFor ;
use function oihana\arango\db\operations\aqlReturn ;
use function oihana\arango\db\operations\aqlScoredSearch ;
use function oihana\arango\models\helpers\isAuthorized ;
use function oihana\core\strings\compile ;
use function oihana\core\strings\key ;

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
 * 1. **Find** — {@see find()} runs one scored SEARCH over the `search-alias`
 *    view and returns, for every match, only its source collection, its `_key`
 *    and its relevance score (BM25), globally ranked and **paginated** (the
 *    LIMIT is applied once, on the whole ranking).
 * 2. **Rebuild** — {@see rebuild()} groups the page by collection and re-hydrates
 *    each group **in one `list()` call per collection** (not per result) through
 *    the model that owns it, reusing that model's own projection pipeline
 *    (fields, joins, skin, permissions); the documents are then merged back in
 *    score order, each wrapped as `{ collection, score, document }`.
 *
 * Pagination is done once, in the cheap *find* stage, so *rebuild* only ever
 * touches one page of documents. The total number of matches (before the LIMIT)
 * is exposed by {@see foundRows()}, the federated counterpart of the model
 * `foundRows()`, so a UI can render "X results, page Y".
 *
 * This is the read-only orchestrator. It is **not** a {@see Documents} subclass
 * — it owns no single collection — but a standalone, container-aware service:
 * the container resolves the per-collection models at rebuild time.
 *
 * The per-source permission gate and the HTTP triplet land in the later lots.
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
     * @param Container $container The DI container, used to resolve the database and the per-collection models.
     * @param array<string, mixed> $init The engine options:
     * <ul>
     *   <li>{@see FederatedSearchParam::VIEW}       — the `search-alias` view name to query.</li>
     *   <li>{@see FederatedSearchParam::SEARCHABLE} — the federated search spec (`fields` + `analyzer`).</li>
     *   <li>{@see FederatedSearchParam::MODELS}     — the `collection => model-service-id` registry.</li>
     *   <li>{@see FederatedSearchParam::SKIN}       — the default skin used to rebuild documents (default {@see Skin::DEFAULT}).</li>
     *   <li>{@see Arango::DATABASE}                 — the {@see ArangoDB} façade (or its container id) used to run the search.</li>
     * </ul>
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function __construct( Container $container , array $init = [] )
    {
        $this->container = $container ;

        $this->initializeDatabase  ( $init )
             ->initializeView      ( $init )
             ->initializeSearchable( $init )
             ->initializeSkin      ( $init )
             ->initializeModels    ( $init )
             ->initializeRequires  ( $init ) ;
    }

    use ContainerTrait ;

    /**
     * The `SEARCH … OPTIONS { collections: [...] }` key restricting the search
     * to a subset of the view's source collections.
     */
    private const string COLLECTIONS_OPTION = 'collections' ;

    /**
     * The default page size of the federated SEARCH when none is supplied.
     */
    public const int DEFAULT_LIMIT = 25 ;

    /**
     * The RETURN alias under which the discriminator value is read back by the
     * lightweight type lookup of a composite (polymorphic) collection.
     */
    private const string DISCRIMINATOR_ALIAS = 'discriminator' ;

    /**
     * The rebuilt-document key carried by each {@see search()} result row.
     */
    public const string DOCUMENT = 'document' ;

    /**
     * The relevance-score key carried by each result row (and the AQL `LET`
     * score variable name).
     */
    public const string SCORE = 'score' ;

    /**
     * The {@see ArangoDB} façade used to run the federated SEARCH, or null when
     * none is configured.
     *
     * @var ArangoDB|null
     */
    public ?ArangoDB $arangodb = null ;

    /**
     * The total number of matches of the last {@see find()} — before the
     * LIMIT, exposed by {@see foundRows()}.
     *
     * @var int
     */
    private int $found = 0 ;

    /**
     * The collection → model registry — the directory telling the engine which
     * model rebuilds the documents of which collection. A value is either a
     * model-service-id string (direct), or, for a polymorphic collection, a
     * normalised composite spec `[ DISCRIMINATOR => field, MAP => [type => id], FALLBACK => id|null ]`
     * routing by a discriminator field (see {@see FederatedSearchParam::MODELS}).
     *
     * @var array<string, string|array<string, mixed>>
     */
    public array $models = [] ;

    /**
     * The collection → required permission subject(s) registry. A collection
     * absent from this map is public; each value is a subject string or an
     * OR-list, evaluated by {@see isAuthorized()} against the request authorizer.
     *
     * @var array<string, string|array<int, string>>
     */
    public array $requires = [] ;

    /**
     * The federated search specification (`fields` + `analyzer`) applied
     * uniformly across the aggregated collections.
     *
     * @var array<string, mixed>
     */
    public array $searchable = [] ;

    /**
     * The default skin (projection variant) used to rebuild the matched
     * documents, overridable per request by `Arango::SKIN`.
     *
     * @var string|null
     */
    public ?string $skin = Skin::DEFAULT ;

    /**
     * The name of the `search-alias` view to query, or null when none is set.
     *
     * @var string|null
     */
    public ?string $view = null ;

    /**
     * Stage 1 — *find*: runs one scored SEARCH over the `search-alias` view and
     * returns the matches as a flat list ranked by relevance, each row holding
     * only its provenance and score: `{ collection, key, score }`.
     *
     * The query term is **bound** (never inlined); the search-alias view, the
     * search spec, the database or the term being absent each yield an empty
     * result set (nothing to search). The query runs with `fullCount`, so
     * {@see foundRows()} returns the total number of matches before the LIMIT.
     * The full documents are not fetched here — that is {@see rebuild()}.
     *
     * @param array<string, mixed> $init The request options:
     * <ul>
     *   <li>{@see Arango::SEARCH} — the query term (a non-empty string).</li>
     *   <li>{@see Arango::LIMIT}  — the page size (default {@see DEFAULT_LIMIT}).</li>
     *   <li>{@see Arango::OFFSET} — the page offset (default 0).</li>
     * </ul>
     *
     * @return array<int, array<string, mixed>> The ranked `{ collection, key, score }` rows.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ReflectionException
     */
    public function find( array $init = [] ) : array
    {
        $this->found = 0 ;

        $term = $init[ Arango::SEARCH ] ?? null ;
        $view = $this->getViewName() ;

        if ( $this->arangodb === null || $view === null || !is_string( $term ) || $term === Char::EMPTY )
        {
            return [] ;
        }

        $binds      = [] ;
        $expression = $this->buildSearchExpression( $term , $binds ) ;

        if ( $expression === null )
        {
            return [] ;
        }

        // Permission gate : restrict the SEARCH to the registered collections the
        // request is authorized for (OPTIONS { collections }) — applied before the
        // LIMIT, so the page and the fullCount only ever cover allowed collections.
        $allowed = $this->allowedCollections( $init ) ;

        if ( $allowed === [] )
        {
            return [] ;
        }

        $aql = aqlScoredSearch
        (
            view     : $view ,
            search   : $expression ,
            limit    : (int) ( $init[ Arango::LIMIT  ] ?? self::DEFAULT_LIMIT ) ,
            analyzer : $this->analyzerName() ,
            options  : [ self::COLLECTIONS_OPTION => $allowed ] ,
            offset   : (int) ( $init[ Arango::OFFSET ] ?? 0 ) ,
            scoreRef : self::SCORE ,
            return   : $this->returnExpression() ,
        ) ;

        $cursor = $this->arangodb->database()->query( $aql , $binds , [ CursorField::OPTIONS => [ CursorField::FULL_COUNT => true ] ] ) ;
        $rows   = $cursor->all() ;

        $this->found = $cursor->getFullCount() ;

        return $rows ;
    }

    /**
     * Returns the total number of matches of the last {@see find()} / {@see search()}
     * before the LIMIT — the federated counterpart of the model `foundRows()`,
     * for "X results, page Y" pagination.
     *
     * @return int
     */
    public function foundRows() : int
    {
        return $this->found ;
    }

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
     * Stage 2 — *rebuild*: re-hydrates a *find* result page into full documents,
     * ranked by relevance. The matches are grouped by collection and each group
     * is rebuilt **in one `list()` call per collection** (a `_key IN […]` filter)
     * by the model that owns it — resolved through the collection → model
     * registry — applying the resolved skin (request `Arango::SKIN` → the engine
     * default → {@see Skin::DEFAULT}). The documents are then merged back in the
     * find order, each wrapped as `{ collection, score, document }`.
     *
     * A match whose collection is not in the registry (or whose model does not
     * resolve to a {@see Documents}, or whose document the model does not return
     * — filtered out by its own rules) is dropped: the model stays authoritative.
     *
     * @param array<int, array<string, mixed>> $matches The {@see find()} rows.
     * @param array<string, mixed> $init The request options (`Arango::SKIN` overrides the engine default).
     *
     * @return array<int, array<string, mixed>> The ranked `{ collection, score, document }` rows.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ConstantException
     */
    public function rebuild( array $matches , array $init = [] ) : array
    {
        if ( $matches === [] )
        {
            return [] ;
        }

        $skin    = $init[ Arango::SKIN ] ?? $this->skin ;
        $allowed = $this->allowedCollections( $init ) ; // defensive : honour the permission gate here too

        // group keys by collection : one batched list() per collection, not per result
        $keysByCollection = [] ;

        foreach ( $matches as $match )
        {
            $collection = $match[ Arango::COLLECTION ] ?? null ;
            $key        = $match[ Arango::KEY        ] ?? null ;

            if ( is_string( $collection ) && is_string( $key ) )
            {
                $keysByCollection[ $collection ][] = $key ;
            }
        }

        // rebuild each collection through its model(s), index the documents by key.
        // A direct registry entry uses one model for the whole collection; a
        // composite (polymorphic) entry routes each key to the model resolved from
        // its discriminator value — read in one lightweight lookup (approach b).
        $hydrated = [] ;

        foreach ( $keysByCollection as $collection => $keys )
        {
            if ( !in_array( $collection , $allowed , true ) )
            {
                continue ; // unregistered or unauthorized collection
            }

            $spec = $this->models[ $collection ] ; // present : $allowed is a subset of the registry keys

            $keysByModelId = is_string( $spec )
                ? [ $spec => $keys ]                                        // direct
                : $this->bucketKeysByModel( $collection , $spec , $keys ) ; // composite

            foreach ( $keysByModelId as $modelId => $modelKeys )
            {
                $model = $this->resolveModelInstance( $modelId ) ;

                if ( $model === null )
                {
                    continue ;
                }

                // Restrict to the matched keys through a trusted internal condition
                // (AQL::CONDITIONS), not the public ?filter= channel: the latter only
                // applies to a model's whitelisted filterable fields, so a `_key`
                // filter is silently dropped unless the model declares it — and the
                // model would then rebuild its whole collection. The internal
                // condition bypasses that whitelist and always restricts.
                $listBinds = [] ;
                $keysVar   = aqlBind( $modelKeys , $listBinds ) ;

                $documents = $model->list
                ([
                    AQL::CONDITIONS => [ compile( [ key( Schema::_KEY , AQL::DOC ) , Comparator::IN , $keysVar ] ) ] ,
                    AQL::BINDS      => $listBinds ,
                    Arango::SKIN    => $skin ,
                ]) ;

                foreach ( $documents as $document )
                {
                    $documentKey = $this->documentKey( $document ) ;

                    if ( $documentKey !== null )
                    {
                        $hydrated[ $collection ][ $documentKey ] = $document ;
                    }
                }
            }
        }

        // re-merge in find (score) order, wrapping each rebuilt document
        $results = [] ;

        foreach ( $matches as $match )
        {
            $collection = $match[ Arango::COLLECTION ] ?? null ;
            $key        = $match[ Arango::KEY        ] ?? null ;
            $document   = ( is_string( $collection ) && is_string( $key ) ) ? ( $hydrated[ $collection ][ $key ] ?? null ) : null ;

            if ( $document !== null )
            {
                $results[] =
                [
                    Arango::COLLECTION => $collection ,
                    self::SCORE        => $match[ self::SCORE ] ?? 0 ,
                    self::DOCUMENT     => $document ,
                ] ;
            }
        }

        return $results ;
    }

    /**
     * Runs a federated search end to end: the *find* stage ranks and paginates
     * the matches across every collection, the *rebuild* stage re-hydrates the
     * page through each owning model. Returns a flat list ranked by relevance,
     * each row `{ collection, score, document }`; {@see foundRows()} carries the
     * total for pagination.
     *
     * @param array<string, mixed> $init The request options (the query term, pagination, the skin, …).
     *
     * @return array<int, array<string, mixed>> The ranked `{ collection, score, document }` rows.
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
     */
    public function search( array $init = [] ) : array
    {
        return $this->rebuild( $this->find( $init ) , $init ) ;
    }

    /**
     * Resolves the {@see ArangoDB} façade used to run the search: an instance
     * passed verbatim, or a container id resolved through the container. Any
     * other value leaves the engine without a database.
     *
     * @param array<string, mixed> $init
     *
     * @return static
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function initializeDatabase( array $init ) : static
    {
        $database = $init[ Arango::DATABASE ] ?? null ;

        if ( is_string( $database ) && $database !== Char::EMPTY && $this->container->has( $database ) )
        {
            $database = $this->container->get( $database ) ;
        }

        $this->arangodb = $database instanceof ArangoDB ? $database : null ;

        return $this ;
    }

    /**
     * Normalises the collection → model registry. A **direct** entry keeps its
     * non-empty model-service-id string (`collection => 'model.x'`). A
     * **composite** entry (a polymorphic collection routed by a discriminator
     * field) is normalised to `[ DISCRIMINATOR => field, MAP => [type => id], FALLBACK => id|null ]`.
     * Malformed entries are dropped (the registry is config-trusted).
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
            foreach ( $models as $collection => $spec )
            {
                if ( !is_string( $collection ) || $collection === Char::EMPTY )
                {
                    continue ;
                }

                // Direct form: `collection => 'model.service-id'` (unchanged).
                if ( is_string( $spec ) )
                {
                    if ( $spec !== Char::EMPTY )
                    {
                        $registry[ $collection ] = $spec ;
                    }
                    continue ;
                }

                // Composite form: a polymorphic collection routed by type.
                if ( is_array( $spec ) )
                {
                    $composite = $this->normaliseCompositeModel( $spec ) ;

                    if ( $composite !== null )
                    {
                        $registry[ $collection ] = $composite ;
                    }
                }
            }
        }

        $this->models = $registry ;

        return $this ;
    }

    /**
     * Normalises the collection → required-permission registry: keeps the
     * entries whose value is a subject string or an OR-list of subjects.
     *
     * @param array<string, mixed> $init
     *
     * @return static
     */
    protected function initializeRequires( array $init ) : static
    {
        $requires = $init[ FederatedSearchParam::REQUIRES ] ?? [] ;

        $registry = [] ;

        if ( is_array( $requires ) )
        {
            foreach ( $requires as $collection => $subjects )
            {
                if ( is_string( $collection ) && $collection !== Char::EMPTY && ( is_string( $subjects ) || is_array( $subjects ) ) )
                {
                    $registry[ $collection ] = $subjects ;
                }
            }
        }

        $this->requires = $registry ;

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
     * Reads the engine default skin, keeping {@see Skin::DEFAULT} when none is
     * declared (a non-string value is ignored).
     *
     * @param array<string, mixed> $init
     *
     * @return static
     */
    protected function initializeSkin( array $init ) : static
    {
        $skin = $init[ FederatedSearchParam::SKIN ] ?? null ;

        $this->skin = is_string( $skin ) && $skin !== Char::EMPTY ? $skin : Skin::DEFAULT ;

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

    /**
     * Returns the registered collections the request is authorized to search:
     * each collection of the registry whose declared {@see FederatedSearchParam::REQUIRES}
     * subject(s) are granted by the request authorizer (`Arango::AUTHORIZER`),
     * via {@see isAuthorized()}. A collection without a declared requirement is
     * public; without an authorizer everything is allowed (fail-open).
     *
     * @param array<string, mixed> $init The request options (`Arango::AUTHORIZER`).
     *
     * @return array<int, string> The allowed collection names.
     */
    private function allowedCollections( array $init ) : array
    {
        $allowed = [] ;

        foreach ( array_keys( $this->models ) as $collection )
        {
            if ( isAuthorized( [ Field::REQUIRES => $this->requires[ $collection ] ?? null ] , $init ) )
            {
                $allowed[] = $collection ;
            }
        }

        return $allowed ;
    }

    /**
     * Returns the analyzer the federated search applies, defaulting to
     * {@see AnalyzerType::IDENTITY} when the spec declares none.
     *
     * @return string
     */
    private function analyzerName() : string
    {
        $analyzer = $this->searchable[ Search::ANALYZER ] ?? null ;

        return is_string( $analyzer ) && $analyzer !== Char::EMPTY ? $analyzer : AnalyzerType::IDENTITY ;
    }

    /**
     * Builds the SEARCH expression matching the bound term against every
     * declared field: `doc.<field> IN TOKENS(@search, "<analyzer>")`,
     * OR-combined. The term is bound (developer-trusted field names are inlined
     * by {@see key()}). Returns null when no usable field is declared.
     *
     * @param string $term The query term.
     * @param array<string, mixed> &$binds The bind variables, filled by reference.
     *
     * @return string|null
     *
     * @throws BindException
     */
    private function buildSearchExpression( string $term , array &$binds ) : ?string
    {
        $fields = $this->searchable[ Search::FIELDS ] ?? null ;

        if ( !is_array( $fields ) || $fields === [] )
        {
            return null ;
        }

        $bind     = aqlBind( $term , $binds , Arango::SEARCH ) ;
        $analyzer = json_encode( $this->analyzerName() ) ;

        $matches = [] ;

        foreach ( $fields as $field )
        {
            if ( is_string( $field ) && $field !== Char::EMPTY )
            {
                $matches[] = key( $field , AQL::DOC ) . Char::SPACE . Comparator::IN . Char::SPACE . tokens( $bind , $analyzer ) ;
            }
        }

        return $matches === [] ? null : compile( $matches , Char::SPACE . Logic::OR . Char::SPACE ) ;
    }

    /**
     * Returns the `_key` of a rebuilt document, reading it from an array or an
     * object shape (a model hydrates to either). Null when absent.
     *
     * @param mixed $document
     *
     * @return string|null
     */
    private function documentKey( mixed $document ) : ?string
    {
        if ( is_array( $document ) )
        {
            return isset( $document[ Schema::_KEY ] ) ? (string) $document[ Schema::_KEY ] : null ;
        }

        if ( is_object( $document ) && isset( $document->{ Schema::_KEY } ) )
        {
            return (string) $document->{ Schema::_KEY } ;
        }

        return null ;
    }

    /**
     * Buckets a composite collection's keys by the model resolved from each key's
     * discriminator value — read in one lightweight lookup
     * ({@see readDiscriminators()}). A key whose type maps to no model (and no
     * fallback) is dropped.
     *
     * @param string               $collection The polymorphic collection.
     * @param array<string, mixed> $spec       The normalised composite spec.
     * @param array<int, string>   $keys       The matched document keys.
     *
     * @return array<string, array<int, string>> A `model-service-id => keys` map.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function bucketKeysByModel( string $collection , array $spec , array $keys ) : array
    {
        $typesByKey = $this->readDiscriminators( $collection , $spec[ FederatedSearchParam::DISCRIMINATOR ] , $keys ) ;

        $buckets = [] ;

        foreach ( $keys as $key )
        {
            $modelId = $this->resolveModelId( $spec , $typesByKey[ $key ] ?? null ) ;

            if ( $modelId !== null )
            {
                $buckets[ $modelId ][] = $key ;
            }
        }

        return $buckets ;
    }

    /**
     * Normalises a composite (polymorphic) model spec, or null when it can never
     * resolve (no mapping and no fallback). The discriminator field defaults to
     * {@see FederatedSearchParam::DEFAULT_DISCRIMINATOR}; the `type => model-id`
     * mapping keeps only non-empty string pairs (declaration order = priority).
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>|null
     */
    private function normaliseCompositeModel( array $spec ) : ?array
    {
        $rawMap = $spec[ FederatedSearchParam::MAP ] ?? null ;
        $map    = [] ;

        if ( is_array( $rawMap ) )
        {
            foreach ( $rawMap as $type => $modelId )
            {
                if ( is_string( $type )    && $type    !== Char::EMPTY
                  && is_string( $modelId ) && $modelId !== Char::EMPTY )
                {
                    $map[ $type ] = $modelId ; // insertion order = resolution priority
                }
            }
        }

        $fallback = $spec[ FederatedSearchParam::FALLBACK ] ?? null ;
        $fallback = ( is_string( $fallback ) && $fallback !== Char::EMPTY ) ? $fallback : null ;

        // A composite entry with neither a mapping nor a fallback can never resolve.
        if ( $map === [] && $fallback === null )
        {
            return null ;
        }

        $key = $spec[ FederatedSearchParam::DISCRIMINATOR ] ?? null ;
        $key = ( is_string( $key ) && $key !== Char::EMPTY ) ? $key : FederatedSearchParam::DEFAULT_DISCRIMINATOR ;

        return
        [
            FederatedSearchParam::DISCRIMINATOR => $key ,
            FederatedSearchParam::MAP           => $map ,
            FederatedSearchParam::FALLBACK      => $fallback ,
        ] ;
    }

    /**
     * Reads the discriminator value of each matched key in one lightweight lookup
     * (`FOR d IN @@collection FILTER d._key IN @keys RETURN { _key, discriminator }`),
     * keyed by `_key`. Returns an empty map when no database is configured, so the
     * resolution falls back per the registry spec. The field name is config-trusted
     * but still guarded by {@see assertAttributeName()} before interpolation.
     *
     * @param string             $collection The polymorphic collection.
     * @param string             $field      The discriminator field name.
     * @param array<int, string> $keys       The matched document keys.
     *
     * @return array<string, mixed> A `_key => discriminator-value` map.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function readDiscriminators( string $collection , string $field , array $keys ) : array
    {
        $database = $this->arangodb?->database() ;

        if ( $database === null )
        {
            return [] ; // no database → no type known ; resolution falls back per spec
        }

        assertAttributeName( $field ) ; // config-trusted, but guard before interpolating

        $binds   = [] ;
        $collVar = aqlBindCollection( $collection , $binds ) ;
        $keysVar = aqlBind( $keys , $binds ) ;
        $keyPath = key( Schema::_KEY , AQL::DOC ) ;

        $aql = compile(
        [
            aqlFor( [ AQL::DOC_REF => AQL::DOC , AQL::IN => $collVar ] ) ,
            aqlFilter( [ compile( [ $keyPath , Comparator::IN , $keysVar ] ) ] ) ,
            aqlReturn( aqlDocument(
                [ Schema::_KEY => $keyPath , self::DISCRIMINATOR_ALIAS => key( $field , AQL::DOC ) ] ,
                [ AQL::USE_SPACE => true , AQL::RAW_VALUES => [ Schema::_KEY , self::DISCRIMINATOR_ALIAS ] ]
            ) ) ,
        ]) ;

        $typesByKey = [] ;

        foreach ( $database->query( $aql , $binds )->all() as $row )
        {
            $documentKey = $row[ Schema::_KEY ] ?? null ;

            if ( is_string( $documentKey ) )
            {
                $typesByKey[ $documentKey ] = $row[ self::DISCRIMINATOR_ALIAS ] ?? null ;
            }
        }

        return $typesByKey ;
    }

    /**
     * Resolves the model-service-id for a hit from its registry spec and its
     * discriminator value. A direct (string) spec returns it verbatim. A
     * composite spec walks its `type => model-id` map in declaration order
     * (priority) — accepting a scalar type or an array of types — and falls back
     * to its fallback model-id, or null (the hit is dropped).
     *
     * @param string|array<string, mixed> $spec The normalised registry spec.
     * @param mixed                        $type The document discriminator value (string, array, or null).
     *
     * @return string|null
     */
    private function resolveModelId( string|array $spec , mixed $type ) : ?string
    {
        if ( is_string( $spec ) )
        {
            return $spec ; // direct
        }

        $candidates = is_array( $type ) ? $type : ( $type === null ? [] : [ $type ] ) ;

        foreach ( $spec[ FederatedSearchParam::MAP ] as $declaredType => $modelId )
        {
            if ( in_array( $declaredType , $candidates , true ) )
            {
                return $modelId ; // map order = priority
            }
        }

        return $spec[ FederatedSearchParam::FALLBACK ] ; // a model-id or null (drop)
    }

    /**
     * Resolves a model-service-id to its {@see Documents} instance through the
     * container. Null when the service is missing or is not a {@see Documents}.
     *
     * @param string $modelId
     *
     * @return Documents|null
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function resolveModelInstance( string $modelId ) : ?Documents
    {
        if ( !$this->container->has( $modelId ) )
        {
            return null ;
        }

        $model = $this->container->get( $modelId ) ;

        return $model instanceof Documents ? $model : null ;
    }

    /**
     * Builds the RETURN expression of the find query: the document provenance
     * (`{ collection, key }` from `PARSE_IDENTIFIER(doc._id)`) merged with its
     * relevance score.
     *
     * @return string
     *
     * @throws UnsupportedOperationException
     */
    private function returnExpression() : string
    {
        return merge
        ([
            parseIdentifier( key( Schema::_ID , AQL::DOC ) ) ,
            aqlDocument( [ self::SCORE => self::SCORE ] , [ AQL::USE_SPACE => true , AQL::RAW_VALUES => [ self::SCORE ] ] ) ,
        ]) ;
    }
}
