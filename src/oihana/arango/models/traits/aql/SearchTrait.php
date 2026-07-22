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
use oihana\exceptions\ValidationException;
use oihana\traits\LazyTrait;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\isAuthorized;
use function oihana\arango\models\helpers\isPathAuthorized;

use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\stripArrayExpansion;

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
     * The default operator combining the words of a `?search=` term **within a
     * field** during the classic `LIKE` sweep ({@see prepareSearch()}):
     * {@see \oihana\arango\db\enums\Logic::AND} (every word must be found in the
     * field, order-independent) or {@see \oihana\arango\db\enums\Logic::OR} (the
     * whole term matched as one substring — the default, backward-compatible).
     * Set once per model through the `Search::OPERATOR` init key. The View search
     * carries its own operator inside the `AQL::VIEW` block (per field), see
     * {@see Search::OPERATOR}.
     */
    public string $searchOperator = Logic::OR ;

    /**
     * The extra characters (beyond whitespace) that split a `?search=` term into
     * words for the `AND` operator of the classic `LIKE` sweep ({@see prepareSearch()}).
     * A string of characters; set once per model through the `Search::SEPARATORS`
     * init key (a list of characters is normalized to a string at init). The
     * default is the hyphen (`-`) so « Jean-Marc » splits like « Jean Marc »; an
     * empty string keeps hyphenated codes whole. Only used in `AND` mode. See
     * {@see Search::SEPARATORS}.
     */
    public string $searchSeparators = Char::HYPHEN ;

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
     * @throws ValidationException
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
     *
     * @throws ValidationException
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

        return $this->getViewName() !== null && count( $this->getViewSearchFields() ) > 0 ;
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
     * Initialize the `LIKE` sweep operator ({@see $searchOperator}) from the
     * `Search::OPERATOR` init key. An explicit value is normalized to
     * {@see Logic::AND} / {@see Logic::OR}; an absent key keeps the default
     * ({@see Logic::OR}, backward-compatible).
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeSearchOperator( array $init = [] ) :static
    {
        if( isset( $init[ Search::OPERATOR ] ) )
        {
            $this->searchOperator = Logic::normalize( (string) $init[ Search::OPERATOR ] ) ;
        }
        return $this ;
    }

    /**
     * Initialize the `LIKE` sweep word separators ({@see $searchSeparators}) from
     * the `Search::SEPARATORS` init key. Accepts a string of characters or a list
     * of characters (joined to a string); an absent key keeps the default (the
     * hyphen). Only meaningful with the `AND` operator. See {@see Search::SEPARATORS}.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeSearchSeparators( array $init = [] ) :static
    {
        if( array_key_exists( Search::SEPARATORS , $init ) )
        {
            $separators = $init[ Search::SEPARATORS ] ;
            $this->searchSeparators = is_array( $separators ) ? implode( Char::EMPTY , $separators ) : (string) $separators ;
        }
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
     * @throws ValidationException
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
     * Prepare the searchable AQL conditions — the classic `?search=` `LIKE`
     * sweep, a parenthesized `OR` of case-insensitive `LIKE()` predicates over
     * every (permitted) searchable field, used when no View search is active.
     *
     * The comma-separated terms and the fields are always `OR`-ed. How the words
     * of a single term combine within a field follows the model's
     * {@see $searchOperator} ({@see Search::OPERATOR} init key):
     *
     * - {@see Logic::OR} (default) matches the whole term as one substring
     *   (`LIKE(doc.name, "%marc fourcade%")`), so it needs the words adjacent and
     *   in order — the historical, byte-for-byte grammar;
     * - {@see Logic::AND} splits the term on whitespace and requires every word in
     *   the same field (`( LIKE(doc.name, "%marc%") && LIKE(doc.name, "%fourcade%") )`),
     *   order-independent.
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

            // Permission gating : a searchable field is kept only when BOTH gates
            // grant it — its own Search::REQUIRES (isAuthorized) AND the
            // Field::REQUIRES inherited from the projection at the exact (sub-)field
            // (isPathAuthorized, symmetric with filter/facet/sort/bounds/groupBy).
            // A field carrying no Field::REQUIRES, or absent from the projection,
            // stays ungated (fail-open, same as filter). Denying every field yields
            // `false` (zero rows) — it must NEVER drop the search silently (that
            // would return everything).
            $fieldsDef = property_exists( $this , AQL::FIELDS ) ? $this->fields : null ;
            $specs     = array_filter
            (
                $specs ,
                static fn( array $spec , $field ) => isAuthorized( $spec , $init ) && isPathAuthorized( (string) $field , $fieldsDef , $init ) ,
                ARRAY_FILTER_USE_BOTH
            ) ;

            if( count( $specs ) === 0 )
            {
                return Boolean::FALSE ; // every searchable field denied → match nothing
            }

            // Model-level operator (Search::OPERATOR init key). AND splits each
            // term into words and requires every one in the same field (a
            // per-field conjunction of substring LIKEs, order-independent); OR (the
            // default) keeps the historical whole-term substring match, byte for
            // byte. Fields and comma-terms stay OR-ed either way.
            $likes     = [] ;
            $termIndex = 0 ;

            foreach( explode( Char::COMMA , $search ) as $term )
            {
                if( $this->searchOperator === Logic::AND )
                {
                    $words = $this->splitSearchTermWords( $term , $this->searchSeparators ) ;

                    if( $words === [] )
                    {
                        $termIndex++ ;
                        continue ; // a whitespace-only term contributes nothing
                    }

                    $wordBinds = [] ;
                    foreach( $words as $wordIndex => $word )
                    {
                        $wordBinds[] = $this->bind( Char::MODULUS . $word . Char::MODULUS , $binds , AQL::SEARCH . Char::UNDERLINE . $termIndex . Char::UNDERLINE . $wordIndex ) ;
                    }

                    foreach( array_keys( $specs ) as $field )
                    {
                        $path    = key( $field , $docRef ) ;
                        $perWord = [] ;
                        foreach( $wordBinds as $wordBind )
                        {
                            $perWord[] = like( $path , $wordBind , caseInsensitive: true ) ;
                        }

                        $likes[] = count( $perWord ) > 1
                                 ? betweenParentheses( $perWord , true , Char::SPACE . Logic::AND . Char::SPACE , false )
                                 : $perWord[ 0 ] ;
                    }
                }
                else
                {
                    $word = $this->bind( Char::MODULUS . $term . Char::MODULUS , $binds , AQL::SEARCH . Char::UNDERLINE . $termIndex ) ;

                    foreach( array_keys( $specs ) as $field )
                    {
                        $likes[] = like( key( $field , $docRef ) , $word , caseInsensitive: true ) ;
                    }
                }

                $termIndex++ ;
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
     *   boost differs from `1`; a field reaching into an array of objects has
     *   its `[*]` expansion marker stripped here (`doc.contactPoints.email IN …`,
     *   not `doc.contactPoints[*].email`): the `SEARCH` grammar rejects array
     *   expansion, and the flat path already matches any element of the indexed
     *   array — see {@see buildViewLink()};
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
     * @param ?array $binds Bind variables, populated by reference.
     * @param string $docRef The document variable the fields hang off.
     *
     * @return ?string The `SEARCH` expression, or `null` when the View search is inactive.
     *
     * @throws BindException
     * @throws ValidationException
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

        // View-level default for how a term's words combine within a field. Absent
        // means Logic::OR (the whole term matched in one shot — the historical
        // grammar); a field may override it (Search::OPERATOR).
        $globalOperator = isset( $this->view[ Search::OPERATOR ] )
                        ? Logic::normalize( (string) $this->view[ Search::OPERATOR ] )
                        : Logic::OR ;

        // The extra word-separator characters for the AND split (Search::SEPARATORS),
        // beyond whitespace. Absent → null → the hyphen default (splitSearchTermWords()).
        $separators = $this->view[ Search::SEPARATORS ] ?? null ;

        // Per-field permission gating : a field joins the SEARCH only when BOTH
        // gates grant it — its own Search::REQUIRES (isAuthorized) AND the
        // Field::REQUIRES inherited from the projection at the exact (sub-)field
        // (isPathAuthorized, which strips the `[*]` marker and descends, so
        // `contactPoints[*].email` is gated in depth). This ANDs with the
        // View-level gate above. A field with no Field::REQUIRES, or absent from
        // the projection, stays ungated (fail-open, same as filter). Denying every
        // field yields a SEARCH that matches nothing — it must NEVER fall back to
        // searching everything (that would bypass the gate).
        $fieldsDef = property_exists( $this , 'fields' ) ? $this->fields : null ;
        $fields = array_filter
        (
            $fields ,
            static fn( array $spec , $field ) => isAuthorized( $spec , $init ) && isPathAuthorized( (string) $field , $fieldsDef , $init ) ,
            ARRAY_FILTER_USE_BOTH
        ) ;

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

        // Defensive: the field paths are developer-declared and interpolated as
        // `doc.<path>` accessors below, so guard each one against AQL injection.
        // Validate the stripped form (the plain dotted path), which is what is
        // emitted. This runs even when the View already exists (buildViewLink()
        // may never have validated it).
        foreach( array_keys( $fields ) as $field )
        {
            assertAttributeName( stripArrayExpansion( (string) $field ) ) ;
        }

        // A field combines the words of a term with AND (all words must match the
        // same field) or OR (any word — the default). When no field opts into AND
        // the historical OR grammar is emitted byte-for-byte; otherwise the
        // operator-aware builder handles the (possibly mixed) fields.
        $anyAnd = false ;
        foreach( $fields as $spec )
        {
            if( ( $spec[ Search::OPERATOR ] ?? $globalOperator ) === Logic::AND )
            {
                $anyAnd = true ;
                break ;
            }
        }

        $groups = $anyAnd
                ? $this->buildViewSearchGroupsWithOperator( $search , $binds , $docRef , $fields , $modelAnalyzer , $globalPhrase , $globalFuzzy , $globalOperator , $separators )
                : $this->buildViewSearchGroups( $search , $binds , $docRef , $fields , $modelAnalyzer , $globalPhrase , $globalFuzzy ) ;

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
     * @throws ValidationException
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
                // A field may declare a single Analyzer or a list — flatten both.
                $declared = $spec[ Search::ANALYZER ] ;
                foreach( is_array( $declared ) ? $declared : [ $declared ] as $name )
                {
                    $analyzers[] = $name ;
                }
            }

            if( isset( $spec[ Search::NGRAM ] ) )
            {
                $analyzers[] = $spec[ Search::NGRAM ][ Search::ANALYZER ] ;
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
     * @throws ValidationException
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
     * A field may reach a sub-field of an array of objects with the `[*]`
     * expansion marker (e.g. `contactPoints[*].email`). The marker is stripped
     * here ({@see stripArrayExpansion()}) so the link declares the flat nested
     * path (`contactPoints` → `email`): ArangoSearch (Community) descends into
     * the array on its own — no Enterprise `nested` flag is needed for this
     * (non-correlated) search. The matching query strips the marker too — the
     * `SEARCH` grammar rejects array expansion, see {@see prepareViewSearch()}.
     * The stripped path is validated ({@see assertAttributeName()}) to reject a
     * malformed declaration early.
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
     * @throws ValidationException
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
            // A field may declare one Analyzer (string) or several (list). The
            // link stores the list of Analyzers indexing the field — a single
            // one yields a one-element list, byte-for-byte the previous output.
            $declared  = $spec[ Search::ANALYZER ] ?? $analyzer ;
            $analyzers = is_array( $declared ) ? array_values( $declared ) : [ $declared ] ;

            // An `ngram` Analyzer declared via Search::NGRAM (queried by
            // NGRAM_MATCH) must be indexed on the field too — merge it into the
            // link's analyzers list (deduplicated).
            if( isset( $spec[ Search::NGRAM ] ) )
            {
                $ngramAnalyzer = $spec[ Search::NGRAM ][ Search::ANALYZER ] ;
                if( !in_array( $ngramAnalyzer , $analyzers , true ) )
                {
                    $analyzers[] = $ngramAnalyzer ;
                }
            }

            $node = $analyzers === $linkDefault ? [] : [ ViewField::ANALYZERS => $analyzers ] ;

            // An array-of-objects sub-field is declared with the `[*]` expansion
            // marker (e.g. `contactPoints[*].email`) — a developer-facing notation
            // (shared with the `?filter=` grammar) that is stripped internally on
            // both surfaces : here for the link, and in prepareViewSearch() for
            // the query. ArangoSearch descends into the array on its own, so the
            // link declares the flat path (`contactPoints.email`), which is also
            // the form compared by viewDiff() : the stored and the declared
            // shapes match, so no permanent false drift is reported.
            $cleanPath = stripArrayExpansion( (string) $path ) ;

            // The path is developer-declared, but a malformed one (a typo, a
            // hyphen, a doubled dot) would silently build a broken link that
            // indexes nothing : turn that into a clear failure at build time.
            assertAttributeName( $cleanPath ) ;

            $segments = explode( Char::DOT , $cleanPath ) ;
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
     * Builds the per-Analyzer expression groups of the View search under the
     * historical `OR` grammar : each comma-separated term is bound whole and
     * matched in one shot per field (`doc.<field> IN TOKENS(@search_N, …)`, whose
     * `IN` semantics already `OR` the term's tokens), plus the optional phrase and
     * fuzzy branches. This is the exact grammar emitted when no field opts into
     * {@see Search::OPERATOR} `AND` — see {@see prepareViewSearch()}.
     *
     * @param string $search The raw `?search=` term string (comma-separated).
     * @param ?array $binds Bind variables, populated by reference.
     * @param string $docRef The document variable the fields hang off.
     * @param array $fields The resolved, gated per-field specs.
     * @param string $modelAnalyzer The View-level Analyzer (a per-field override is respected).
     * @param bool $globalPhrase The View-level phrase-bonus default.
     * @param int $globalFuzzy The View-level Levenshtein tolerance default.
     *
     * @return array<string,string[]> Analyzer name => list of expressions, in first-seen order.
     *
     * @throws BindException
     */
    protected function buildViewSearchGroups
    (
        string  $search ,
        ?array &$binds ,
        string  $docRef ,
        array   $fields ,
        string  $modelAnalyzer ,
        bool    $globalPhrase ,
        int     $globalFuzzy
    )
    :array
    {
        $groups = [] ; // analyzer name => list of expressions, in first-seen order
        $index  = 0 ;

        foreach( explode( Char::COMMA , $search ) as $word )
        {
            $term = $this->bind( $word , $binds , AQL::SEARCH . Char::UNDERLINE . $index++ ) ;

            foreach( $fields as $field => $spec )
            {
                [ $analyzers , $weight , $fuzzy , $phrase , $path ] = $this->resolveFieldSearchSpec( $spec , $field , $modelAnalyzer , $globalPhrase , $globalFuzzy , $docRef ) ;

                $this->pushWholeTermField( $groups , $spec , $path , $term , $analyzers , $weight , $phrase , $fuzzy ) ;
            }
        }

        return $groups ;
    }

    /**
     * Builds the per-Analyzer expression groups when at least one field opts into
     * {@see Search::OPERATOR} `AND`. Each comma-separated term is split into words
     * (whitespace) ; a field then combines those words with its resolved operator :
     *
     * - `OR` (default) reproduces the whole-term match
     *   ({@see pushWholeTermField()}), so a mixed View keeps its loose fields loose ;
     * - `AND` requires every word in the same field
     *   (`( doc.<field> IN TOKENS(@w0, …) && doc.<field> IN TOKENS(@w1, …) )`), the
     *   fuzzy branch (when enabled) widening each word
     *   (`( … IN TOKENS(@w, …) || LEVENSHTEIN_MATCH(…, @w, …) )`), and the phrase
     *   bonus (when enabled) kept on the whole term as a ranking boost `OR`-ed on
     *   top — an adjacency match implies the words, so the matched set is unchanged.
     *
     * The whole-term bind is materialized only when a field needs it (an `OR`
     * field, or an `AND` field carrying the phrase bonus), never left dangling.
     * Groups are `OR`-ed between fields and between terms by {@see prepareViewSearch()}.
     *
     * @param string $search The raw `?search=` term string (comma-separated).
     * @param ?array $binds Bind variables, populated by reference.
     * @param string $docRef The document variable the fields hang off.
     * @param array $fields The resolved, gated per-field specs.
     * @param string $modelAnalyzer The View-level Analyzer (a per-field override is respected).
     * @param bool $globalPhrase The View-level phrase-bonus default.
     * @param int $globalFuzzy The View-level Levenshtein tolerance default.
     * @param string $globalOperator The View-level operator default ({@see Logic::AND} / {@see Logic::OR}).
     * @param string|array|null $separators The extra AND word separators ({@see Search::SEPARATORS}); null = the hyphen default.
     *
     * @return array<string,string[]> Analyzer name => list of expressions, in first-seen order.
     *
     * @throws BindException
     */
    protected function buildViewSearchGroupsWithOperator
    (
        string             $search ,
        ?array            &$binds ,
        string             $docRef ,
        array              $fields ,
        string             $modelAnalyzer ,
        bool               $globalPhrase ,
        int                $globalFuzzy ,
        string             $globalOperator ,
        string|array|null  $separators = null
    )
    :array
    {
        // The whole-term bind is shared by every OR field and by the phrase bonus
        // of an AND field. Detect once whether any field references it, so an
        // AND-only View with no phrase never binds (and leaves dangling) a term.
        $needWholeTerm = false ;
        foreach( $fields as $spec )
        {
            $operator = $spec[ Search::OPERATOR ] ?? $globalOperator ;
            $phrase   = array_key_exists( Search::PHRASE , $spec ) ? $spec[ Search::PHRASE ] : $globalPhrase ;

            if( $operator === Logic::OR || ( $operator === Logic::AND && $phrase ) )
            {
                $needWholeTerm = true ;
                break ;
            }
        }

        $groups    = [] ; // analyzer name => list of expressions, in first-seen order
        $termIndex = 0 ;

        foreach( explode( Char::COMMA , $search ) as $rawTerm )
        {
            $words = $this->splitSearchTermWords( $rawTerm , $separators ) ;

            if( $words === [] )
            {
                $termIndex++ ;
                continue ; // a whitespace-only term contributes nothing
            }

            // The whole comma-term (words rejoined by a single space), bound once
            // and only when a field actually references it.
            $wholeTerm = $needWholeTerm
                       ? $this->bind( implode( Char::SPACE , $words ) , $binds , AQL::SEARCH . Char::UNDERLINE . $termIndex )
                       : null ;

            // One bind per word, consumed by every AND field of this term.
            $wordTerms = [] ;
            foreach( $words as $wordIndex => $word )
            {
                $wordTerms[] = $this->bind( $word , $binds , AQL::SEARCH . Char::UNDERLINE . $termIndex . Char::UNDERLINE . $wordIndex ) ;
            }

            foreach( $fields as $field => $spec )
            {
                $operator = $spec[ Search::OPERATOR ] ?? $globalOperator ;

                [ $analyzers , $weight , $fuzzy , $phrase , $path ] = $this->resolveFieldSearchSpec( $spec , $field , $modelAnalyzer , $globalPhrase , $globalFuzzy , $docRef ) ;

                if( $operator === Logic::OR )
                {
                    $this->pushWholeTermField( $groups , $spec , $path , $wholeTerm , $analyzers , $weight , $phrase , $fuzzy ) ;
                    continue ;
                }

                // Logic::AND — every word must match this field (per Analyzer).
                foreach( $analyzers as $name )
                {
                    $groups[ $name ] ??= [] ;

                    $perWord = [] ;
                    foreach( $wordTerms as $wordTerm )
                    {
                        $match = $path . Char::SPACE . Comparator::IN . Char::SPACE . tokens( $wordTerm , json_encode( $name ) ) ;

                        if( $fuzzy > 0 )
                        {
                            // Widen the word with its own typo-tolerant branch.
                            $match = betweenParentheses( [ $match , func( SearchFunction::LEVENSHTEIN_MATCH , [ $path , $wordTerm , $fuzzy ] ) ] , true , Char::SPACE . Logic::OR . Char::SPACE , false ) ;
                        }

                        $perWord[] = $match ;
                    }

                    $andExpr = count( $perWord ) > 1
                             ? betweenParentheses( $perWord , true , Char::SPACE . Logic::AND . Char::SPACE , false )
                             : $perWord[ 0 ] ;

                    $groups[ $name ][] = $weight == 1 ? $andExpr : boost( $andExpr , $weight ) ;

                    if( $phrase )
                    {
                        // Whole-term adjacency bonus, OR-ed on top as a ranking boost.
                        $groups[ $name ][] = boost( func( SearchFunction::PHRASE , [ $path , $wholeTerm ] ) , $weight * 2 ) ;
                    }
                }

                // The ngram (autocomplete) branch, AND-ed per word like the tokens.
                if( isset( $spec[ Search::NGRAM ] ) )
                {
                    $ngramName = $spec[ Search::NGRAM ][ Search::ANALYZER ] ;
                    $groups[ $ngramName ] ??= [] ;

                    $perWord = [] ;
                    foreach( $wordTerms as $wordTerm )
                    {
                        $perWord[] = $this->ngramMatch( $spec , $path , $wordTerm ) ;
                    }

                    $andNgram = count( $perWord ) > 1
                              ? betweenParentheses( $perWord , true , Char::SPACE . Logic::AND . Char::SPACE , false )
                              : $perWord[ 0 ] ;

                    $groups[ $ngramName ][] = $weight == 1 ? $andNgram : boost( $andNgram , $weight ) ;
                }
            }

            $termIndex++ ;
        }

        return $groups ;
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
     * @return array<string, array<string, mixed>>
     *
     * @throws ValidationException
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
                        // A field may declare a single Analyzer (string) or a
                        // list of Analyzers (indexing the same field through
                        // several recipes, e.g. `text` + `ngram` for whole-word
                        // search plus autocomplete). The list shape is preserved.
                        $analyzer = $options[ Search::ANALYZER ] ;
                        $spec[ Search::ANALYZER ] = is_array( $analyzer )
                            ? array_values( array_map( 'strval' , $analyzer ) )
                            : (string) $analyzer ;
                    }

                    if( array_key_exists( Search::LANG , $options ) )
                    {
                        $spec[ Search::LANG ] = (string) $options[ Search::LANG ] ;
                    }

                    if( array_key_exists( Search::OPERATOR , $options ) )
                    {
                        // How the words of a term combine within this field:
                        // Logic::AND (all words) or Logic::OR (any word). An
                        // explicit value is normalized to one of the two; an
                        // absent key means "inherit the View-level default"
                        // resolved by prepareViewSearch().
                        $spec[ Search::OPERATOR ] = Logic::normalize( (string) $options[ Search::OPERATOR ] ) ;
                    }

                    if( array_key_exists( Search::PHRASE , $options ) )
                    {
                        $spec[ Search::PHRASE ] = (bool) $options[ Search::PHRASE ] ;
                    }

                    if( array_key_exists( Search::REQUIRES , $options ) )
                    {
                        $spec[ Search::REQUIRES ] = $options[ Search::REQUIRES ] ;
                    }

                    if( array_key_exists( Search::NGRAM , $options ) )
                    {
                        // An `ngram` Analyzer queried via NGRAM_MATCH (a similarity
                        // threshold), distinct from the IN TOKENS analyzers. Two
                        // forms: the analyzer name alone (default threshold), or a
                        // map carrying the analyzer and an explicit threshold.
                        $ngram = $options[ Search::NGRAM ] ;

                        if( is_array( $ngram ) )
                        {
                            $ngramAnalyzer = (string) ( $ngram[ Search::ANALYZER ] ?? Char::EMPTY ) ;
                            $threshold     = array_key_exists( Search::THRESHOLD , $ngram ) ? (float) $ngram[ Search::THRESHOLD ] : null ;
                        }
                        else
                        {
                            $ngramAnalyzer = (string) $ngram ;
                            $threshold     = null ;
                        }

                        if( $threshold !== null && ( $threshold < 0.0 || $threshold > 1.0 ) )
                        {
                            throw new ValidationException( sprintf( 'Search::THRESHOLD must be between 0.0 and 1.0, got %s' , $threshold ) ) ;
                        }

                        $spec[ Search::NGRAM ] = [ Search::ANALYZER => $ngramAnalyzer , Search::THRESHOLD => $threshold ] ;
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
     *
     * @throws ValidationException
     */
    protected function getViewSearchFields() :array
    {
        return array_map
        (
            static fn( array $spec ) : float => $spec[ Search::BOOST ] ,
            $this->getViewFieldSpecs()
        ) ;
    }

    /**
     * Builds the `NGRAM_MATCH(doc.<field>, @term [, threshold], "<analyzer>")`
     * core of a {@see Search::NGRAM} branch (without the surrounding boost). The
     * threshold is inlined only when the field declares one, else the server
     * default applies. Shared by the whole-term and the per-word emissions.
     *
     * @param array $spec The field spec (must carry a {@see Search::NGRAM} entry).
     * @param string $path The `doc.<field>` accessor.
     * @param string $term The bound term (`@search_N` or `@search_N_M`).
     *
     * @return string The `NGRAM_MATCH(…)` expression.
     */
    protected function ngramMatch( array $spec , string $path , string $term ) :string
    {
        $ngramName = $spec[ Search::NGRAM ][ Search::ANALYZER ] ;
        $threshold = $spec[ Search::NGRAM ][ Search::THRESHOLD ] ;

        $args = [ $path , $term ] ;
        if( $threshold !== null )
        {
            $args[] = $threshold ;
        }
        $args[] = json_encode( $ngramName ) ;

        return func( SearchFunction::NGRAM_MATCH , $args ) ;
    }

    /**
     * Pushes the whole-term match of one field onto the Analyzer groups — the
     * base `doc.<field> IN TOKENS(@term, …)` (boosted when the field weight is not
     * `1`), the optional exact-phrase bonus and the optional fuzzy branch, plus
     * the {@see Search::NGRAM} branch when declared. This is the `OR`-grammar
     * emission, shared by {@see buildViewSearchGroups()} (every field) and by the
     * `OR` fields of {@see buildViewSearchGroupsWithOperator()}.
     *
     * @param array  &$groups    Analyzer name => list of expressions, mutated by reference.
     * @param array  $spec       The resolved field spec.
     * @param string $path       The `doc.<field>` accessor.
     * @param string $term       The bound whole term (`@search_N`).
     * @param array  $analyzers  The Analyzers indexing the field (`IN TOKENS` side).
     * @param float  $weight     The field boost.
     * @param bool   $phrase     Whether to add the exact-phrase bonus.
     * @param int    $fuzzy      The Levenshtein tolerance (`0` disables the branch).
     *
     * @return void
     */
    protected function pushWholeTermField( array &$groups , array $spec , string $path , string $term , array $analyzers , float $weight , bool $phrase , int $fuzzy ) :void
    {
        foreach( $analyzers as $name )
        {
            $groups[ $name ] ??= [] ;

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

        if( isset( $spec[ Search::NGRAM ] ) )
        {
            $ngramName = $spec[ Search::NGRAM ][ Search::ANALYZER ] ;

            $groups[ $ngramName ] ??= [] ;

            $ngramMatch = $this->ngramMatch( $spec , $path , $term ) ;

            $groups[ $ngramName ][] = $weight == 1 ? $ngramMatch : boost( $ngramMatch , $weight ) ;
        }
    }

    /**
     * Resolves the per-field search facets shared by both group builders : the
     * Analyzers indexing the field (a per-field {@see Search::ANALYZER} overrides
     * the View-level one, a single Analyzer normalized to a one-element list), the
     * boost, the fuzzy tolerance and phrase bonus (a per-field value overrides the
     * View-level default), and the `doc.<field>` accessor (with the `[*]` array
     * marker stripped — the flat path already matches any element of the indexed
     * array, and the `SEARCH` grammar rejects the expansion form).
     *
     * @param array      $spec          The resolved field spec.
     * @param int|string $field         The field key (the dotted path, `[*]` allowed).
     * @param string     $modelAnalyzer The View-level Analyzer.
     * @param bool       $globalPhrase  The View-level phrase-bonus default.
     * @param int        $globalFuzzy   The View-level Levenshtein tolerance default.
     * @param string     $docRef        The document variable the field hangs off.
     *
     * @return array{0:string[],1:float,2:int,3:bool,4:string} `[ analyzers, weight, fuzzy, phrase, path ]`.
     */
    protected function resolveFieldSearchSpec( array $spec , int|string $field , string $modelAnalyzer , bool $globalPhrase , int $globalFuzzy , string $docRef ) :array
    {
        // A field may be queried through one Analyzer or several (e.g. `text` +
        // `ngram` for whole-word search plus autocomplete).
        $declared  = $spec[ Search::ANALYZER ] ?? $modelAnalyzer ;
        $analyzers = is_array( $declared ) ? array_values( $declared ) : [ $declared ] ;

        return
        [
            $analyzers ,
            $spec[ Search::BOOST ] ,
            array_key_exists( Search::FUZZY  , $spec ) ? $spec[ Search::FUZZY  ] : $globalFuzzy ,
            array_key_exists( Search::PHRASE , $spec ) ? $spec[ Search::PHRASE ] : $globalPhrase ,
            key( stripArrayExpansion( (string) $field ) , $docRef ) ,
        ] ;
    }

    /**
     * Splits an `AND`-operator search term into its words, on whitespace plus the
     * given extra separator characters ({@see Search::SEPARATORS}). Whitespace
     * always splits; `$separators` adds literal characters (a string, or a list
     * of characters joined to one), `null` falls back to the hyphen default, and
     * an empty value splits on whitespace only. The extra characters are
     * regex-escaped, so any punctuation is safe. Empty words are dropped.
     *
     * @param string             $term       The raw comma-term (may hold several words).
     * @param string|array|null  $separators The extra separator characters (string / list / null=default hyphen).
     *
     * @return string[] The non-empty words, in order.
     */
    protected function splitSearchTermWords( string $term , string|array|null $separators = null ) :array
    {
        if( is_array( $separators ) )
        {
            $separators = implode( Char::EMPTY , $separators ) ;
        }

        $separators ??= Char::HYPHEN ; // default : split compound words like "Jean-Marc"

        $extra   = $separators === Char::EMPTY ? Char::EMPTY : preg_quote( $separators , '/' ) ;
        $pattern = '/[\s' . $extra . ']+/u' ;

        return preg_split( $pattern , trim( $term ) , -1 , PREG_SPLIT_NO_EMPTY ) ;
    }
}
