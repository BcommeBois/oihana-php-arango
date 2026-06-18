<?php

namespace oihana\arango\search ;

use DI\Container ;

use oihana\arango\clients\analyzer\enums\AnalyzerType ;
use oihana\arango\clients\cursor\enums\CursorField ;
use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\Comparator ;
use oihana\arango\db\enums\Logic ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\Documents ;
use oihana\arango\models\enums\Search ;
use oihana\arango\models\enums\filters\FilterComparator ;
use oihana\arango\models\enums\filters\FilterParam ;
use oihana\arango\search\enums\FederatedSearchParam ;

use oihana\controllers\enums\Skin ;
use oihana\enums\Char ;
use oihana\traits\ContainerTrait ;

use org\schema\constants\Schema ;

use function oihana\arango\db\binds\aqlBind ;
use function oihana\arango\db\functions\documents\parseIdentifier ;
use function oihana\arango\db\functions\strings\tokens ;
use function oihana\arango\db\operations\aqlScoredSearch ;
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
     * @param Container            $container The DI container, used to resolve the database and the per-collection models.
     * @param array<string, mixed> $init      The engine options:
     * <ul>
     *   <li>{@see FederatedSearchParam::VIEW}       — the `search-alias` view name to query.</li>
     *   <li>{@see FederatedSearchParam::SEARCHABLE} — the federated search spec (`fields` + `analyzer`).</li>
     *   <li>{@see FederatedSearchParam::MODELS}     — the `collection => model-service-id` registry.</li>
     *   <li>{@see FederatedSearchParam::SKIN}       — the default skin used to rebuild documents (default {@see Skin::DEFAULT}).</li>
     *   <li>{@see Arango::DATABASE}                 — the {@see ArangoDB} façade (or its container id) used to run the search.</li>
     * </ul>
     */
    public function __construct( Container $container , array $init = [] )
    {
        $this->container = $container ;

        $this->initializeDatabase  ( $init )
             ->initializeView      ( $init )
             ->initializeSearchable( $init )
             ->initializeSkin      ( $init )
             ->initializeModels    ( $init ) ;
    }

    use ContainerTrait ;

    /**
     * The default page size of the federated SEARCH when none is supplied.
     */
    public const int DEFAULT_LIMIT = 25 ;

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
     * The collection → model-service-id registry — the directory telling the
     * engine which model rebuilds the documents of which collection.
     *
     * @var array<string, string>
     */
    public array $models = [] ;

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
     * @throws \oihana\exceptions\BindException
     * @throws \ReflectionException
     * @throws \oihana\arango\clients\exceptions\ArangoException
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

        $aql = aqlScoredSearch
        (
            view     : $view ,
            search   : $expression ,
            limit    : (int) ( $init[ Arango::LIMIT  ] ?? self::DEFAULT_LIMIT ) ,
            analyzer : $this->analyzerName() ,
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
     * @param array<string, mixed>             $init    The request options (`Arango::SKIN` overrides the engine default).
     *
     * @return array<int, array<string, mixed>> The ranked `{ collection, score, document }` rows.
     */
    public function rebuild( array $matches , array $init = [] ) : array
    {
        if ( $matches === [] )
        {
            return [] ;
        }

        $skin = $init[ Arango::SKIN ] ?? $this->skin ;

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

        // rebuild each collection through its model, index the documents by key
        $hydrated = [] ;

        foreach ( $keysByCollection as $collection => $keys )
        {
            $model = $this->resolveModel( $collection ) ;

            if ( $model === null )
            {
                continue ;
            }

            $documents = $model->list
            ([
                Arango::FILTER => [ FilterParam::KEY => Schema::_KEY , FilterParam::OP => FilterComparator::IN , FilterParam::VAL => $keys ] ,
                Arango::SKIN   => $skin ,
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
     * @throws \oihana\exceptions\BindException
     * @throws \ReflectionException
     * @throws \oihana\arango\clients\exceptions\ArangoException
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
     * @param string                $term  The query term.
     * @param array<string, mixed> &$binds The bind variables, filled by reference.
     *
     * @return string|null
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
     * Resolves the {@see Documents} model that rebuilds a collection through the
     * registry + the container. Null when the collection is unregistered, the
     * service is missing, or it is not a {@see Documents}.
     *
     * @param string $collection
     *
     * @return Documents|null
     */
    private function resolveModel( string $collection ) : ?Documents
    {
        $modelId = $this->models[ $collection ] ?? null ;

        if ( $modelId === null || !$this->container->has( $modelId ) )
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
     */
    private function returnExpression() : string
    {
        return 'MERGE(' . parseIdentifier( AQL::DOC . '._id' ) . ', { ' . self::SCORE . ': ' . self::SCORE . ' })' ;
    }
}
