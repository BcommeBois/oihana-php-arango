<?php

namespace oihana\arango\search ;

use DI\Container ;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\Comparator ;
use oihana\arango\db\enums\Logic ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\enums\Search ;
use oihana\arango\search\enums\FederatedSearchParam ;

use oihana\enums\Char ;
use oihana\exceptions\BindException;
use oihana\traits\ContainerTrait ;

use ReflectionException;
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
 * 1. **Find** — {@see find()} runs one SEARCH over the `search-alias` view and
 *    returns, for every match, only its source collection, its `_key` and its
 *    relevance score (BM25), ranked and paginated.
 * 2. **Rebuild** — the matches are grouped by collection and each group is
 *    re-hydrated by the model that owns it (resolved through the
 *    collection → model registry), reusing that model's own projection
 *    pipeline; the results are then merged back in score order (Lot C3).
 *
 * This is the read-only orchestrator. It is **not** a {@see \oihana\arango\models\Documents}
 * subclass — it owns no single collection — but a standalone, container-aware
 * service: the container resolves the per-collection models at rebuild time.
 *
 * Lots delivered so far: C1 (skeleton + registry) and C2 (the *find* stage).
 * The *rebuild* stage, the per-source permission gate and the HTTP triplet
 * land in the later lots.
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
             ->initializeModels    ( $init ) ;
    }

    use ContainerTrait ;

    /**
     * The default page size of the federated SEARCH when none is supplied.
     */
    public const int DEFAULT_LIMIT = 25 ;

    /**
     * The relevance-score key carried by each {@see find()} result row (and
     * the AQL `LET` score variable name).
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
     * result set (nothing to search). The full documents are not fetched here —
     * that is the *rebuild* stage (Lot C3).
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

        return $this->arangodb->database()->query( $aql , $binds )->all() ;
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
     * Runs a federated search and returns the matching documents, rebuilt by
     * their own model and ranked by relevance.
     *
     * As of Lot C2 the *find* stage is wired but the *rebuild* stage (Lot C3)
     * is not, so this returns the raw ranked `{ collection, key, score }` rows
     * of {@see find()}; the per-collection re-hydration and the score merge
     * land next.
     *
     * @param array<string, mixed> $init The request options (the query term, pagination, the authorizer, …).
     *
     * @return array<int, mixed> The flat, score-ranked result list.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws ArangoException
     */
    public function search( array $init = [] ) : array
    {
        return $this->find( $init ) ;
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
