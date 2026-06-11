<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\clients\analyzer\enums\AnalyzerType;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\clients\view\enums\ViewField;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\functions\SearchFunction;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\ViewDiffStatus;
use oihana\arango\db\results\ViewDiffReport;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Search;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\traits\LazyTrait;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use function oihana\arango\db\functions\search\analyzer;
use function oihana\arango\db\functions\search\boost;
use function oihana\arango\db\functions\strings\like;
use function oihana\arango\db\functions\strings\tokens;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\func;
use function oihana\core\strings\key;

/**
 * This class is the generic Model class.
 */
trait SearchTrait
{
    use BindTrait ,
        LazyTrait ;

    /**
     * The searchable fields.
     */
    public ?array $searchable = [] ;

    /**
     * The model-level ArangoSearch declaration (`AQL::VIEW` block, see {@see Search}).
     * When present (with a {@see Search::NAME}), the `?search=` parameter switches
     * from the simple `LIKE` sweep to an index-accelerated, relevance-ranked
     * `SEARCH` against the declared View.
     */
    public ?array $view = null ;

    /**
     * The 'searchable' parameter key.
     */
    public const string SEARCHABLE = 'searchable' ;

    /**
     * Returns the desired per-collection link map of the declared View —
     * the model's collection linked with {@see buildViewLink()} — or an
     * empty map when the model has no collection.
     *
     * @return array<string, ArangoSearchLink>
     */
    public function getViewLinks() :array
    {
        return empty( $this->collection ) ? [] : [ $this->collection => $this->buildViewLink() ] ;
    }

    /**
     * Returns the name of the declared View ({@see Search::NAME} of the
     * `AQL::VIEW` block), or `null` when the model declares none.
     *
     * @return ?string
     */
    public function getViewName() :?string
    {
        $name = is_array( $this->view ) ? ( $this->view[ Search::NAME ] ?? null ) : null ;
        return is_string( $name ) && $name !== Char::EMPTY ? $name : null ;
    }

    /**
     * Indicates whether the View search is active: the model declares a named
     * View (`AQL::VIEW` block) with at least one searched field, **and** the
     * request carries a non-empty search term.
     *
     * @param array|string|null $search The `$init` array (reads `Arango::SEARCH`) or the search term itself.
     *
     * @return bool
     */
    public function hasViewSearch( array|string|null $search = [] ) :bool
    {
        if( is_array( $search ) )
        {
            $search = $search[ Arango::SEARCH ] ?? null ;
        }

        if( !is_string( $search ) || $search === Char::EMPTY )
        {
            return false ;
        }

        return $this->getViewName() !== null
            && count( $this->getViewSearchFields() ) > 0 ;
    }

    /**
     * Initialize the 'searchable' property.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeSearchable( array $init = [] ) :static
    {
        $this->searchable = $init[ self::SEARCHABLE ] ?? $this->searchable ;
        return $this ;
    }

    /**
     * Initialize the model-level ArangoSearch declaration (`AQL::VIEW` block)
     * and lazily provision the View, mirroring the collection provisioning of
     * `initializeCollection()`: when the model is lazy and the declared View
     * does not exist, it is created from the declaration — the searched fields
     * (dotted paths supported) are linked on the model's collection with the
     * declared {@see Search::ANALYZER}. An existing View is never altered —
     * inspect and resynchronize explicitly with {@see viewDiff()} /
     * {@see viewSync()} (or the `views` action of the `arangodb` command).
     *
     * @param array $init
     *
     * @return static
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function initializeView( array $init = [] ) :static
    {
        $this->view = $init[ AQL::VIEW ] ?? $this->view ;

        $name = is_array( $this->view ) ? ( $this->view[ Search::NAME ] ?? null ) : null ;
        $lazy = $this->initializeLazy( $init )->isLazy() ;

        if( $lazy && is_string( $name ) && $name !== Char::EMPTY && !empty( $this->collection ) && !$this->viewExists( $name ) )
        {
            $this->viewCreate( $name , [ $this->collection => $this->buildViewLink() ] ) ;
        }

        return $this ;
    }

    /**
     * Prepare the searchable AQL conditions.
     *
     * @param array|string|null $search
     * @param ?array $binds
     * @param ?array $searchable
     * @param  string $docRef
     *
     * @return ?string
     *
     * @throws BindException
     *
     * @example
     * ```
     * ?search=Marc,Marco
     * ```
     */
    public function prepareSearch
    (
        array|string|null $search     = [] ,
        ?array            &$binds     = null ,
        ?array            $searchable = null ,
        string            $docRef     = AQL::DOC
    )
    :?string
    {
        if( is_array( $search ) )
        {
            $search = $search[ Arango::SEARCH ] ?? null ;
        }

        if( is_string( $search ) && $search != Char::EMPTY )
        {
            $searchable = $searchable ?? $this->searchable ;
            $words      = explode ( Char::COMMA , $search ) ;
            if( count( $words ) > 0 && is_array( $searchable ) && count( $searchable ) > 0 )
            {
                $likes   = [] ;
                $index   = 0 ;
                foreach( $words as $word )
                {
                    $word = $this->bind
                    (
                        Char::MODULUS . $word . Char::MODULUS ,
                        $binds ,
                        AQL::SEARCH . Char::UNDERLINE . $index++
                    ) ;
                    foreach( $searchable as $field )
                    {
                        $likes[] = like( key( $field , $docRef ) , $word , caseInsensitive: true ) ;
                    }
                }
                return betweenParentheses
                (
                    expression : compile( $likes , Char::SPACE . Logic::OR . Char::SPACE ) ,
                    trim       : false
                ) ;
            }
        }
        return null ;
    }

    /**
     * Prepare the relevance-ranked `SEARCH` expression of an active View search,
     * or `null` when the View search is inactive (no `AQL::VIEW` declaration,
     * no searched fields, or no search term) — the caller then falls back to
     * the classic `LIKE` sweep of {@see prepareSearch()}.
     *
     * The grammar keeps the `?search=` contract (comma-separated terms, `OR`
     * everywhere, values bound — never inlined). Per term and per field:
     *
     * - the base match `doc.<field> IN TOKENS(@search_N, "<analyzer>")`
     *   (both sides analyzed), weighted by `BOOST(…, <boost>)` when the field
     *   boost differs from `1`;
     * - with {@see Search::PHRASE}, an exact-phrase bonus
     *   `BOOST(PHRASE(doc.<field>, @search_N), <boost × 2>)`;
     * - with {@see Search::FUZZY} `> 0`, a typo-tolerant
     *   `LEVENSHTEIN_MATCH(doc.<field>, @search_N, <fuzzy>)`.
     *
     * The whole expression is wrapped in `ANALYZER(…, "<analyzer>")`.
     *
     * @param array|string|null $search The `$init` array (reads `Arango::SEARCH`) or the search term itself.
     * @param ?array            $binds  Bind variables, populated by reference.
     * @param string            $docRef The document variable the fields hang off.
     *
     * @return ?string The `SEARCH` expression, or `null` when the View search is inactive.
     *
     * @throws BindException
     */
    public function prepareViewSearch
    (
        array|string|null $search  = [] ,
        ?array            &$binds  = null ,
        string            $docRef  = AQL::DOC
    )
    :?string
    {
        if( !$this->hasViewSearch( $search ) )
        {
            return null ;
        }

        if( is_array( $search ) )
        {
            $search = $search[ Arango::SEARCH ] ?? null ;
        }

        $name     = $this->view[ Search::ANALYZER ] ?? AnalyzerType::IDENTITY ;
        $quoted   = json_encode( $name ) ;
        $fields   = $this->getViewSearchFields() ;
        $phrase   = ( $this->view[ Search::PHRASE ] ?? false ) === true ;
        $fuzzy    = (int) ( $this->view[ Search::FUZZY ] ?? 0 ) ;

        $expressions = [] ;
        $index       = 0 ;

        foreach( explode( Char::COMMA , $search ) as $word )
        {
            $term = $this->bind( $word , $binds , AQL::SEARCH . Char::UNDERLINE . $index++ ) ;

            foreach( $fields as $field => $weight )
            {
                $path  = key( $field , $docRef ) ;
                $match = $path . Char::SPACE . Comparator::IN . Char::SPACE . tokens( $term , $quoted ) ;

                $expressions[] = $weight == 1 ? $match : boost( $match , $weight ) ;

                if( $phrase )
                {
                    $expressions[] = boost( func( SearchFunction::PHRASE , [ $path , $term ] ) , $weight * 2 ) ;
                }

                if( $fuzzy > 0 )
                {
                    $expressions[] = func( SearchFunction::LEVENSHTEIN_MATCH , [ $path , $term , $fuzzy ] ) ;
                }
            }
        }

        return analyzer
        (
            compile( $expressions , Char::SPACE . Logic::OR . Char::SPACE ) ,
            $name
        ) ;
    }

    /**
     * Compares the model's View declaration with the server state and
     * reports the differences, without touching anything.
     *
     * On top of the field/analyzer drift detected by
     * {@see \oihana\arango\db\traits\ViewManagementTrait::viewDiff()}, the
     * model-level report validates the coherence of the declaration itself:
     * a missing {@see Search::NAME}, no searched field, no collection, an
     * analyzer or a collection unknown to the server all resolve to
     * {@see ViewDiffStatus::INVALID} — such a View is never created nor
     * synchronized automatically.
     *
     * @return ViewDiffReport
     */
    public function viewDiff() :ViewDiffReport
    {
        $name   = $this->getViewName() ;
        $errors = [] ;

        if( $name === null )
        {
            $errors[] = 'declaration : no View name (Search::NAME)' ;
        }
        if( count( $this->getViewSearchFields() ) === 0 )
        {
            $errors[] = 'declaration : no searched field (Search::FIELDS or searchable)' ;
        }
        if( empty( $this->collection ) )
        {
            $errors[] = 'declaration : no collection' ;
        }

        if( $errors !== [] )
        {
            return new ViewDiffReport( $name ?? Char::EMPTY , ViewDiffStatus::INVALID , $errors ) ;
        }

        $report = $this->arangodb?->viewDiff( $name , $this->getViewLinks() )
               ?? new ViewDiffReport( $name , ViewDiffStatus::UNREACHABLE , [ 'no database available' ] ) ;

        if( $report->status === ViewDiffStatus::UNREACHABLE )
        {
            return $report ;
        }

        $analyzer = $this->view[ Search::ANALYZER ] ?? AnalyzerType::IDENTITY ;
        if( !$this->analyzerExists( $analyzer ) )
        {
            $errors[] = sprintf( "analyzer '%s' not found on the server" , $analyzer ) ;
        }
        if( !$this->collectionExists( $this->collection ) )
        {
            $errors[] = sprintf( "collection '%s' not found on the server" , $this->collection ) ;
        }

        if( $errors !== [] )
        {
            return new ViewDiffReport( $name , ViewDiffStatus::INVALID , [ ...$errors , ...$report->changes ] ) ;
        }

        return $report ;
    }

    /**
     * Reconciles the model's View with its declaration: creates it when
     * missing, repairs a drift with `updateProperties()` (the View stays
     * available while the inverted index rebuilds in the background), and
     * leaves {@see ViewDiffStatus::IN_SYNC}, {@see ViewDiffStatus::INVALID}
     * or {@see ViewDiffStatus::UNREACHABLE} reports untouched.
     *
     * @return ViewDiffReport The {@see viewDiff()} report, with `$applied` set when the View has been created or updated.
     */
    public function viewSync() :ViewDiffReport
    {
        $report = $this->viewDiff() ;

        if( $report->status !== ViewDiffStatus::MISSING && $report->status !== ViewDiffStatus::DRIFTED )
        {
            return $report ;
        }

        return $this->arangodb?->viewSync( $this->getViewName() , $this->getViewLinks() ) ?? $report ;
    }

    /**
     * Builds the View link of the model's collection from the `AQL::VIEW`
     * declaration: every searched field (dotted paths become nested fields)
     * is indexed with the declared {@see Search::ANALYZER}.
     *
     * @return ArangoSearchLink
     */
    protected function buildViewLink() :ArangoSearchLink
    {
        $analyzers = [ $this->view[ Search::ANALYZER ] ?? AnalyzerType::IDENTITY ] ;

        $fields = [] ;

        foreach( array_keys( $this->getViewSearchFields() ) as $path )
        {
            $node = [ ViewField::ANALYZERS => $analyzers ] ;

            $segments = explode( Char::DOT , (string) $path ) ;
            while( count( $segments ) > 1 )
            {
                $segment = array_pop( $segments ) ;
                $node    = [ ViewField::FIELDS => [ $segment => $node ] ] ;
            }

            $fields = array_replace_recursive( $fields , [ $segments[0] => $node ] ) ;
        }

        return new ArangoSearchLink( fields : $fields ) ;
    }

    /**
     * Normalizes the searched fields of the `AQL::VIEW` declaration into a
     * `field => boost` map: {@see Search::FIELDS} entries accept a numeric
     * boost shorthand or an array carrying {@see Search::BOOST}; when the
     * declaration has no fields, the model's `searchable` list is used with
     * a boost of `1`.
     *
     * @return array<string, float|int>
     */
    protected function getViewSearchFields() :array
    {
        $fields = is_array( $this->view ) ? ( $this->view[ Search::FIELDS ] ?? null ) : null ;

        if( !is_array( $fields ) || count( $fields ) === 0 )
        {
            $fields = is_array( $this->searchable ) ? array_fill_keys( $this->searchable , 1 ) : [] ;
        }

        return array_map(

            static function( mixed $options ) : float
            {
                if( is_numeric( $options ) )
                {
                    return (float) $options ;
                }

                if( is_array( $options ) )
                {
                    return (float) ( $options[ Search::BOOST ] ?? 1 ) ;
                }

                return 1.0 ;

            },
            $fields
        ) ;
    }
}
