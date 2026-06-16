<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\clients\analyzer\enums\AnalyzerType;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\clients\view\enums\ViewField;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\functions\SearchFunction;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\results\DiffReport;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Search;
use oihana\enums\Boolean;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\traits\LazyTrait;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\isAuthorized;

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
     * The searchable fields swept by the classic `?search=` `LIKE` (see
     * {@see prepareSearch()}). A list of field names; an entry may instead be an
     * array carrying its name under {@see Search::KEY} plus options such as
     * {@see Search::REQUIRES} to gate the field by permission:
     *
     * ```php
     * AQL::SEARCHABLE =>
     * [
     *     'name' ,                                                       // public
     *     [ Search::KEY => 'salary' , Search::REQUIRES => 'hr:salary' ], // gated
     * ]
     * ```
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
        $init = is_array( $search ) ? $search : [] ;

        if( is_array( $search ) )
        {
            $search = $search[ Arango::SEARCH ] ?? null ;
        }

        if( is_string( $search ) && $search != Char::EMPTY )
        {
            $specs = $this->getSearchableSpecs( $searchable ) ;

            if( count( $specs ) === 0 )
            {
                return null ; // no searchable field declared → search inactive
            }

            // Permission gating : a searchable field declaring Search::REQUIRES
            // is kept only when the request authorizer grants it (fail-open
            // without one). Denying every field yields `false` (zero rows) — it
            // must NEVER drop the search silently (that would return everything).
            $specs = array_filter( $specs , static fn( array $spec ) => isAuthorized( $spec , $init ) ) ;

            if( count( $specs ) === 0 )
            {
                return Boolean::FALSE ; // every searchable field denied → match nothing
            }

            $likes = [] ;
            $index = 0 ;
            foreach( explode( Char::COMMA , $search ) as $word )
            {
                $word = $this->bind
                (
                    Char::MODULUS . $word . Char::MODULUS ,
                    $binds ,
                    AQL::SEARCH . Char::UNDERLINE . $index++
                ) ;
                foreach( array_keys( $specs ) as $field )
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
     * - with a per-field or View-level {@see Search::PHRASE}, an exact-phrase
     *   bonus `BOOST(PHRASE(doc.<field>, @search_N), <boost × 2>)`; a field may
     *   override the View-level flag (an explicit `false` opts that field out);
     * - with a per-field or View-level {@see Search::FUZZY} `> 0`, a
     *   typo-tolerant `LEVENSHTEIN_MATCH(doc.<field>, @search_N, <fuzzy>)`;
     *   a field may override the View-level tolerance — an explicit `0`
     *   opts that field out while the rest stays fuzzy.
     *
     * Field expressions are grouped by their resolved Analyzer (a field may
     * override the View-level {@see Search::ANALYZER}) and each group is wrapped
     * in its own `ANALYZER(…, "<analyzer>")`, the groups being `OR`-ed together.
     * With a single Analyzer the output is a single `ANALYZER(…)` wrap, byte for
     * byte the classic form.
     *
     * {@see Search::REQUIRES} gates the search by the request authorizer
     * (`Arango::AUTHORIZER`, see {@see isAuthorized()}) at two levels: on the
     * `AQL::VIEW` block it gates the whole search, inside a field entry it gates
     * that field; the two combine with `AND` (fail-open without an authorizer).
     * If the View-level gate is denied, or permissions remove every field, the
     * expression is `false` — the search matches nothing and never falls back
     * to searching everything.
     *
     * When the request carries an active language (`Arango::LANG`, the `?lang=`
     * parameter), localized fields (those declaring {@see Search::LANG}) join
     * the `SEARCH` only when their locale matches; locale-agnostic fields always
     * do. An active language matching no field is ignored — the `SEARCH` is
     * never emptied (within the permitted set).
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

        $init = is_array( $search ) ? $search : [] ;
        $lang = $init[ Arango::LANG ] ?? null ;

        if( is_array( $search ) )
        {
            $search = $search[ Arango::SEARCH ] ?? null ;
        }

        // View-level permission gate : a Search::REQUIRES on the AQL::VIEW block
        // gates the whole search. Denied → the SEARCH matches nothing, whatever
        // the per-field declarations. AND with the per-field gate below.
        if( isset( $this->view[ Search::REQUIRES ] )
            && !isAuthorized( [ Search::REQUIRES => $this->view[ Search::REQUIRES ] ] , $init ) )
        {
            return Boolean::FALSE ; // SEARCH false → zero rows (whole search denied)
        }

        $modelAnalyzer = $this->view[ Search::ANALYZER ] ?? AnalyzerType::IDENTITY ;
        $fields        = $this->getViewFieldSpecs() ;
        $globalPhrase  = ( $this->view[ Search::PHRASE ] ?? false ) === true ;
        $globalFuzzy   = (int) ( $this->view[ Search::FUZZY ] ?? 0 ) ;

        // Per-field permission gating : a field declaring Search::REQUIRES joins
        // the SEARCH only when the request authorizer grants it (fail-open without
        // one). This ANDs with the View-level gate above. Denying every field
        // yields a SEARCH that matches nothing — it must NEVER fall back to
        // searching everything (that would bypass the gate).
        $fields = array_filter( $fields , static fn( array $spec ) => isAuthorized( $spec , $init ) ) ;

        if( $fields === [] )
        {
            return Boolean::FALSE ; // SEARCH false → zero rows (denied everything)
        }

        if( $lang !== null )
        {
            // Localized fields join the SEARCH only when their locale matches the
            // active language; locale-agnostic fields always do. Filtering out
            // every field (unknown locale) is ignored so the SEARCH is never empty.
            $localized = array_filter
            (
                $fields ,
                static fn( array $spec ) => !isset( $spec[ Search::LANG ] ) || $spec[ Search::LANG ] === $lang
            ) ;

            if( $localized !== [] )
            {
                $fields = $localized ;
            }
        }

        $groups = [] ; // analyzer name => list of expressions, in first-seen order
        $index  = 0 ;

        foreach( explode( Char::COMMA , $search ) as $word )
        {
            $term = $this->bind( $word , $binds , AQL::SEARCH . Char::UNDERLINE . $index++ ) ;

            foreach( $fields as $field => $spec )
            {
                $name   = $spec[ Search::ANALYZER ] ?? $modelAnalyzer ;
                $weight = $spec[ Search::BOOST ] ;
                $fuzzy  = array_key_exists( Search::FUZZY  , $spec ) ? $spec[ Search::FUZZY  ] : $globalFuzzy ;
                $phrase = array_key_exists( Search::PHRASE , $spec ) ? $spec[ Search::PHRASE ] : $globalPhrase ;

                $groups[ $name ] ??= [] ;

                $path  = key( $field , $docRef ) ;
                $match = $path . Char::SPACE . Comparator::IN . Char::SPACE . tokens( $term , json_encode( $name ) ) ;

                $groups[ $name ][] = $weight == 1 ? $match : boost( $match , $weight ) ;

                if( $phrase )
                {
                    $groups[ $name ][] = boost( func( SearchFunction::PHRASE , [ $path , $term ] ) , $weight * 2 ) ;
                }

                if( $fuzzy > 0 )
                {
                    $groups[ $name ][] = func( SearchFunction::LEVENSHTEIN_MATCH , [ $path , $term , $fuzzy ] ) ;
                }
            }
        }

        $wrapped = [] ;

        foreach( $groups as $name => $expressions )
        {
            $wrapped[] = analyzer( compile( $expressions , Char::SPACE . Logic::OR . Char::SPACE ) , $name ) ;
        }

        return compile( $wrapped , Char::SPACE . Logic::OR . Char::SPACE ) ;
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
     * {@see DiffStatus::INVALID} — such a View is never created nor
     * synchronized automatically.
     *
     * @return DiffReport
     */
    public function viewDiff() :DiffReport
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
            return new DiffReport( $name ?? Char::EMPTY , DiffStatus::INVALID , $errors ) ;
        }

        $report = $this->arangodb?->viewDiff( $name , $this->getViewLinks() )
               ?? new DiffReport( $name , DiffStatus::UNREACHABLE , [ 'no database available' ] ) ;

        if( $report->status === DiffStatus::UNREACHABLE )
        {
            return $report ;
        }

        $analyzers = [ $this->view[ Search::ANALYZER ] ?? AnalyzerType::IDENTITY ] ;
        foreach( $this->getViewFieldSpecs() as $spec )
        {
            if( isset( $spec[ Search::ANALYZER ] ) )
            {
                $analyzers[] = $spec[ Search::ANALYZER ] ;
            }
        }
        foreach( array_unique( $analyzers ) as $analyzer )
        {
            if( !$this->analyzerExists( $analyzer ) )
            {
                $errors[] = sprintf( "analyzer '%s' not found on the server" , $analyzer ) ;
            }
        }
        if( !$this->collectionExists( $this->collection ) )
        {
            $errors[] = sprintf( "collection '%s' not found on the server" , $this->collection ) ;
        }

        if( $errors !== [] )
        {
            return new DiffReport( $name , DiffStatus::INVALID , [ ...$errors , ...$report->changes ] ) ;
        }

        return $report ;
    }

    /**
     * Reconciles the model's View with its declaration: creates it when
     * missing, repairs a drift with `updateProperties()` (the View stays
     * available while the inverted index rebuilds in the background), and
     * leaves {@see DiffStatus::IN_SYNC}, {@see DiffStatus::INVALID}
     * or {@see DiffStatus::UNREACHABLE} reports untouched.
     *
     * @return DiffReport The {@see viewDiff()} report, with `$applied` set when the View has been created or updated.
     */
    public function viewSync() :DiffReport
    {
        $report = $this->viewDiff() ;

        if( $report->status !== DiffStatus::MISSING && $report->status !== DiffStatus::DRIFTED )
        {
            return $report ;
        }

        return $this->arangodb?->viewSync( $this->getViewName() , $this->getViewLinks() ) ?? $report ;
    }

    /**
     * Builds the View link of the model's collection from the `AQL::VIEW`
     * declaration: every searched field (dotted paths become nested fields)
     * is indexed with its resolved Analyzer — the per-field
     * {@see Search::ANALYZER} when declared, otherwise the View-level one.
     *
     * A field whose resolved Analyzer equals the link-level default is emitted
     * as an empty node (no `analyzers` key) rather than spelling the default
     * out: the server normalizes a field whose analyzers equal the link default
     * to `{}` (the redundant mention is dropped), so spelling it out would make
     * the declared form differ forever from the stored one and {@see viewDiff()}
     * would report a permanent false drift. The link carries no link-level
     * analyzers, so its default is the server default (`identity`) — computed
     * here rather than hard-coded so the elimination stays correct if a
     * link-level analyzer is introduced later.
     *
     * @return ArangoSearchLink
     */
    protected function buildViewLink() :ArangoSearchLink
    {
        $analyzer = $this->view[ Search::ANALYZER ] ?? AnalyzerType::IDENTITY ;

        // The link is built with no link-level analyzers, so the server applies
        // its own default. A field matching this default is stored as `{}`.
        $linkDefault = [ AnalyzerType::IDENTITY ] ;

        $fields = [] ;

        foreach( $this->getViewFieldSpecs() as $path => $spec )
        {
            $analyzers = [ $spec[ Search::ANALYZER ] ?? $analyzer ] ;

            $node = $analyzers === $linkDefault ? [] : [ ViewField::ANALYZERS => $analyzers ] ;

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
     * Normalizes the model's `AQL::SEARCHABLE` list into a per-field
     * specification map `field => [ (Search::REQUIRES => …)? ]`, the single
     * source consumed by {@see prepareSearch()} (the `LIKE` sweep) and by the
     * `searchable` fallback of {@see getViewFieldSpecs()}.
     *
     * Each entry of the list is either:
     * - a plain field name (string) → a public field, no options;
     * - an array carrying the field name under {@see Search::KEY} plus its
     *   options (e.g. {@see Search::REQUIRES}) → keeps the list homogeneous
     *   (no mixed numeric/string keys). The map form `field => [ … ]` is also
     *   tolerated (the field falls back to the entry key).
     *
     * @param ?array $searchable An explicit list overriding the model's `searchable` property.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getSearchableSpecs( ?array $searchable = null ) :array
    {
        $searchable = $searchable ?? $this->searchable ;

        if( !is_array( $searchable ) )
        {
            return [] ;
        }

        $specs = [] ;

        foreach( $searchable as $key => $value )
        {
            if( is_string( $value ) )
            {
                $specs[ $value ] = [] ;
            }
            elseif( is_array( $value ) )
            {
                $field = (string) ( $value[ Search::KEY ] ?? $key ) ;
                $spec  = [] ;

                if( array_key_exists( Search::REQUIRES , $value ) )
                {
                    $spec[ Search::REQUIRES ] = $value[ Search::REQUIRES ] ;
                }

                $specs[ $field ] = $spec ;
            }
        }

        return $specs ;
    }

    /**
     * Normalizes the searched fields of the `AQL::VIEW` declaration into a
     * per-field specification map `field => [ Search::BOOST => float, … ]` —
     * the single source of truth from which {@see getViewSearchFields()}
     * derives the boost map and {@see prepareViewSearch()} resolves the
     * per-field options.
     *
     * {@see Search::FIELDS} entries accept a numeric boost shorthand or an
     * array carrying {@see Search::BOOST}, {@see Search::FUZZY},
     * {@see Search::ANALYZER}, {@see Search::LANG}, {@see Search::PHRASE} and
     * {@see Search::REQUIRES};
     * when the declaration has no fields, the model's `searchable` list is used with a
     * neutral boost. A per-field option is kept in the spec only when it is
     * explicitly declared — an absent key means "inherit the View-level
     * default", which {@see prepareViewSearch()} resolves so that an explicit
     * value (`0` included) overrides the global tolerance.
     *
     * @return array<string, array<string, float|int>>
     */
    protected function getViewFieldSpecs() :array
    {
        $fields = is_array( $this->view ) ? ( $this->view[ Search::FIELDS ] ?? null ) : null ;

        if( !is_array( $fields ) || count( $fields ) === 0 )
        {
            $fields = $this->getSearchableSpecs() ; // field => [ (Search::REQUIRES => …)? ]
        }

        return array_map(

            static function( mixed $options ) : array
            {
                if( is_numeric( $options ) )
                {
                    return [ Search::BOOST => (float) $options ] ;
                }

                if( is_array( $options ) )
                {
                    $spec = [ Search::BOOST => (float) ( $options[ Search::BOOST ] ?? 1 ) ] ;

                    if( array_key_exists( Search::FUZZY , $options ) )
                    {
                        $spec[ Search::FUZZY ] = (int) $options[ Search::FUZZY ] ;
                    }

                    if( array_key_exists( Search::ANALYZER , $options ) )
                    {
                        $spec[ Search::ANALYZER ] = (string) $options[ Search::ANALYZER ] ;
                    }

                    if( array_key_exists( Search::LANG , $options ) )
                    {
                        $spec[ Search::LANG ] = (string) $options[ Search::LANG ] ;
                    }

                    if( array_key_exists( Search::PHRASE , $options ) )
                    {
                        $spec[ Search::PHRASE ] = (bool) $options[ Search::PHRASE ] ;
                    }

                    if( array_key_exists( Search::REQUIRES , $options ) )
                    {
                        $spec[ Search::REQUIRES ] = $options[ Search::REQUIRES ] ;
                    }

                    return $spec ;
                }

                return [ Search::BOOST => 1.0 ] ;

            },
            $fields
        ) ;
    }

    /**
     * Normalizes the searched fields into a `field => boost` map — a
     * boost-only façade over {@see getViewFieldSpecs()}, used by
     * {@see buildViewLink()} and {@see viewDiff()} which only care about the
     * field paths and their weights.
     *
     * @return array<string, float>
     */
    protected function getViewSearchFields() :array
    {
        return array_map
        (
            static fn( array $spec ) : float => $spec[ Search::BOOST ] ,
            $this->getViewFieldSpecs()
        ) ;
    }
}
