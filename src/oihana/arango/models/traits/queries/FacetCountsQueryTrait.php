<?php

namespace oihana\arango\models\traits\queries;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operator;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\Group;

use oihana\enums\Char;
use oihana\enums\Order;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use oihana\reflect\exceptions\ConstantException;

use org\schema\constants\Schema;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;

use function oihana\arango\models\helpers\facets\resolveFacetDirection;
use function oihana\arango\db\functions\arrays\countDistinct;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\expandArrayPath;
use function oihana\arango\db\operations\aqlCollect;
use function oihana\arango\db\operations\aqlCollectReturn;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlLimit;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlSort;
use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\models\helpers\facets\resolveFacetJoin;
use function oihana\arango\models\helpers\isAttributeAuthorized;
use function oihana\arango\models\helpers\isAuthorized;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Builds the AQL query that computes per-value **facet counts** for several
 * dimensions at once, alongside (not replacing) the document list.
 *
 * Each requested dimension is a key of the model's `$this->facets` whitelist
 * (the filterable facets become the counted facets). One `LET` sub-query per
 * dimension counts values over the **same conjunctive filter** as the list, so
 * the buckets reflect the currently filtered set:
 *
 * ```aql
 * LET category = (FOR doc IN @@coll FILTER <same filters> COLLECT value = doc.category WITH COUNT INTO count SORT count DESC, value ASC RETURN { value, count })
 * LET status   = (FOR doc IN @@coll FILTER <same filters> COLLECT value = doc.status   WITH COUNT INTO count SORT count DESC, value ASC RETURN { value, count })
 * RETURN { category, status }
 * ```
 *
 * Supported types: the scalar {@see Facet::FIELD}, the array-membership
 * {@see Facet::IN} family ({@see Facet::LIST}, {@see Facet::LIST_FIELD},
 * {@see Facet::LIST_FIELD_SORTED}) and the two linked facets {@see Facet::EDGE}
 * and {@see Facet::JOIN}; other facet types are skipped. A `Facet::PROPERTY`
 * carrying the `[*]` array-expansion marker (e.g. `offers[*].priceCurrency`)
 * unwinds the object array and counts the sub-field per element — see
 * {@see FacetCountsQueryTrait::buildFacetCountSubquery()}.
 *
 * A **linked** dimension counts the documents reached through the relation it
 * already filters on — an `INBOUND` edge traversal or a key-join — bucketing on
 * a field of the *related* document named by `Facet::VALUE` (default `_key`):
 *
 * ```aql
 * LET location = (FOR doc IN @@coll FILTER <same filters> FOR doc_location IN INBOUND doc places_edges COLLECT value = doc_location.name WITH COUNT INTO count SORT count DESC, value ASC RETURN { value, count })
 * ```
 *
 * The unwinding facet types (the `[*]` expansion, the {@see Facet::IN} family
 * and the linked facets) count *rows* by default, so a document reaching the
 * same value several times — a repeated array element, two parallel edges to the
 * same vertex, several joined documents — is counted several times, diverging
 * from the equivalent `?filter=` existence test, which counts *documents*.
 * Declaring `Facet::DISTINCT => true` on such a facet switches its bucket count
 * to `COUNT_DISTINCT( doc._key )`, so the count reflects distinct root documents
 * and matches the filter. The flag is opt-in (default unchanged) and a no-op on
 * the scalar {@see Facet::FIELD} type, which already counts one row per document.
 *
 * **Top-N buckets.** Every dimension returns *all* its values by default, which
 * a sidebar showing ten entries does not need. `Facet::LIMIT => n` closes the
 * shared tail with a `LIMIT n` placed **after** the sort, so what survives is
 * the n **biggest** buckets. It is read once for every type — the scalar field,
 * the unwound array, the `[*]` sub-field and the linked relations — because they
 * all end on the same tail. `Arango::FACET_COUNTS_LIMIT` overrides it per
 * request, so the declaration is a default rather than a ceiling.
 *
 * **A total order.** Buckets are sorted `count DESC, value ASC`. The second
 * criterion is what makes a top-N mean something: ordering by the count alone
 * leaves equal-count buckets in whatever order the server produced, and AQL
 * guarantees no stable sort — so a `LIMIT n` falling inside a run of equal
 * counts could keep a different subset from one request to the next. The bucket
 * value is unique per bucket (it *is* the `COLLECT` key), so the order is total.
 *
 * **Permission.** The dimension gate runs before the type is dispatched, so it
 * covers the linked types unchanged — but the two guards do not weigh the same
 * for them: the projection inheritance
 * ({@see isAttributeAuthorized}) looks the
 * dimension up in the *main* model's fields, which cannot say anything about a
 * field of another collection. A linked facet is therefore gated by the
 * `Field::REQUIRES` declared **on the facet itself**, exactly as on the
 * filtering side.
 *
 * @see FacetCountsQueryTrait::buildFacetCountsQuery() The entry point.
 */
trait FacetCountsQueryTrait
{
    use FilteredScopeTrait ;

    /**
     * The bucket value attribute name in the returned rows (`{ value, count }`).
     */
    private const string FACET_COUNT_VALUE = 'value' ;

    /**
     * The unwind loop variable for array-membership facets (kept distinct from
     * {@see FacetCountsQueryTrait::FACET_COUNT_VALUE} to avoid a name collision).
     */
    private const string FACET_COUNT_ITEM = 'item' ;

    /**
     * Guards a bucket limit, wherever it came from: a positive integer passes,
     * anything else is refused naming its origin.
     *
     * @param mixed  $limit  The candidate limit.
     * @param string $origin What is being blamed, as the message reads it.
     *
     * @return int The validated limit.
     *
     * @throws ValidationException When the limit is not a positive integer.
     */
    private function assertPositiveLimit( mixed $limit , string $origin ) :int
    {
        if ( !is_int( $limit ) || $limit < 1 )
        {
            throw new ValidationException( sprintf
            (
                'Invalid bucket limit (%s): %s. A limit is a positive integer — the number of buckets to keep; omit it for all of them.' ,
                $origin ,
                is_scalar( $limit ) ? var_export( $limit , true ) : get_debug_type( $limit ) ,
            )) ;
        }

        return $limit ;
    }

    /**
     * Builds the multi-`LET` facet-counts query, or an empty string when nothing
     * is countable.
     *
     * @param array $init The list query options (`Arango::FACET_COUNTS` holds the dimensions).
     * @param array $bindVars The bind variables, populated by reference.
     * @param string $docRef The document reference.
     *
     * @return string The compiled AQL query, or an empty string.
     *
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
    public function buildFacetCountsQuery( array $init = [] , array &$bindVars = [] , string $docRef = AQL::DOC ) :string
    {
        $dimensions = $init[ Arango::FACET_COUNTS ] ?? [] ;
        if ( is_string( $dimensions ) )
        {
            $dimensions = array_map( 'trim' , explode( Char::COMMA , $dimensions ) ) ;
        }

        if ( !is_array( $dimensions ) || empty( $dimensions ) || !is_array( $this->facets ) )
        {
            return Char::EMPTY ;
        }

        // The FOR + conjunctive FILTER are the list's scope, so every bucket
        // reflects exactly the displayed set. Shared by every list-family query.
        [ $for , $filter ] = $this->buildFilteredScope( $init , $bindVars ) ;

        // The per-request bucket limit is global to the query, so it is read
        // once here rather than per dimension — see facetCountLimit().
        $limitOverride = $init[ Arango::FACET_COUNTS_LIMIT ] ?? null ;

        $lets  = [] ;
        $names = [] ;
        foreach ( $dimensions as $dimension )
        {
            // Whitelist: only configured facet keys are countable.
            $facet = is_string( $dimension ) ? ( $this->facets[ $dimension ] ?? null ) : null ;
            if ( !is_array( $facet ) )
            {
                continue ;
            }

            // Permission gate: a dimension on a field hidden from the projection
            // (Field::REQUIRES, inherited from $fields or declared on the facet) is
            // dropped — its distinct values and counts would leak the hidden field
            // in clear (a direct facet-counts oracle).
            if ( !isAttributeAuthorized( $dimension , $this->fields ?? null , $init ) || !isAuthorized( $facet , $init ) )
            {
                continue ;
            }

            $subquery = $this->buildFacetCountSubquery( $facet , $dimension , $for , $filter , $docRef , $limitOverride ) ;
            if ( $subquery === null )
            {
                continue ; // unsupported facet type (v1)
            }

            $lets[]  = aqlLet( $dimension , $subquery , useParentheses: true ) ;
            $names[] = $dimension ;
        }

        if ( empty( $names ) )
        {
            return Char::EMPTY ;
        }

        return compile( [ ...$lets , aqlReturn( aqlDocument( compile( $names , Char::COMMA . Char::SPACE ) ) ) ] ) ;
    }

    /**
     * Builds one dimension's counting sub-query, or null for an unsupported type.
     *
     * @param array $facet The facet definition (`Facet::TYPE`, `Facet::PROPERTY`).
     * @param string $key The facet key (default property).
     * @param string $for The pre-built `FOR` segment shared by every dimension —
     *                         the bound collection, or the bound View with its `SEARCH`
     *                         segment when the View search is active.
     * @param string|null $filter The shared `FILTER` clause.
     * @param string $docRef The document reference.
     * @param int|false|null $limitOverride The request-level bucket limit
     *                         (`Arango::FACET_COUNTS_LIMIT`): an integer overriding
     *                         the declaration, `false` for explicitly unlimited, or
     *                         null when the declaration decides.
     *
     * @return string|null
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildFacetCountSubquery( array $facet , string $key , string $for , ?string $filter , string $docRef , int|false|null $limitOverride = null ) :?string
    {
        $type     = $facet[ Facet::TYPE     ] ?? Facet::FIELD ;
        $property = $facet[ Facet::PROPERTY ] ?? $key ;

        // `SORT count DESC, value ASC`. The second criterion is not decoration:
        // ordering by the count alone leaves buckets of equal count in whatever
        // order the server happens to produce, and AQL guarantees no stable sort.
        // Two requests could then answer the same buckets in a different order,
        // and — far worse — a `LIMIT n` falling inside a run of equal counts
        // would keep a different subset each time. The bucket value breaks the
        // tie: it is unique per bucket (it *is* the COLLECT key), so the order is
        // total, and reproducible.
        $sort = aqlSort
        ([
            compile( [ Group::COUNT_NAME        , Order::DESC ] ) ,
            compile( [ self::FACET_COUNT_VALUE  , Order::ASC  ] ) ,
        ]) ;

        // Opt-in `Facet::DISTINCT => true`: count DISTINCT root documents per
        // bucket instead of the unwound array elements. Only the two unwinding
        // branches (the `[*]` expansion and the IN/LIST family) over-count a
        // document when the same sub-field value repeats across several of its
        // array elements — that is the divergence from the equivalent `?filter=`
        // existence test (which counts documents). The scalar FIELD branch already
        // emits one row per document, so the flag is a no-op there and is left
        // untouched. When set, the shared tail aggregates COUNT_DISTINCT on the
        // ROOT document key (whatever the `[*]` hop depth) — see facetCountCollect().
        $distinctKey = !empty( $facet[ Facet::DISTINCT ] ) ? key( Schema::_KEY , $docRef ) : null ;

        // How many buckets survive: the declared `Facet::LIMIT => n` default, or
        // the request-level override that has the last word. Resolved here so
        // every branch below shares it — see facetCountLimit().
        $limit = $this->facetCountLimit( $facet , $key , $limitOverride ) ;

        // A linked facet counts the RELATED documents — those reached through an
        // edge traversal (EDGE) or a key-join (JOIN) — so its bucket value is a
        // field of the *related* document, named by `Facet::VALUE` (default
        // `_key`). Handled before the `[*]` branch below on purpose: the source of
        // a linked facet is the relation, never an array of the main document, and
        // `Facet::PROPERTY` keeps its join meaning (the main-side attribute) rather
        // than naming a path to unwind.
        if ( $type === Facet::EDGE || $type === Facet::JOIN )
        {
            [ $relationRef , $relation ] = $this->facetCountRelation( $facet , $key , $type , $docRef ) ;

            $bucket = $facet[ Facet::VALUE ] ?? Schema::_KEY ;

            assertAttributeName( $bucket ) ; // defensive: config-trusted, but cheap to guard.

            return compile
            ([
                $for ,
                $filter ,
                ...$relation ,
                ...$this->facetCountCollect( key( $bucket , $relationRef ) , $sort , $distinctKey , $limit ) ,
            ]) ;
        }

        // An object-array sub-field is declared with the `[*]` expansion marker
        // (e.g. `offers[*].priceCurrency`). Unlike `?filter=` / `?search`, which
        // flatten the path, a facet count must *unwind* the array with a FOR and
        // project the sub-field so each element is counted as its own bucket
        // (`FOR item IN doc.offers COLLECT value = item.priceCurrency …`). The
        // marker is the signal — it overrides the declared FIELD / IN type. Each
        // `[*]` is one FOR hop, so nested object arrays are counted per leaf
        // element (`offers[*].prices[*].currency` → `FOR item IN doc.offers FOR
        // item2 IN item.prices COLLECT value = item2.currency …`).
        if ( str_contains( $property , Operator::ARRAY_EXPANSION ) )
        {
            // One FOR hop per `[*]`, then the projected leaf — shared with the
            // bounds sub-query, which unwinds the same way before its own tail.
            [ $fors , $value ] = expandArrayPath( $property , $docRef , self::FACET_COUNT_ITEM ) ;

            return compile
            ([
                $for ,
                $filter ,
                ...$fors ,
                ...$this->facetCountCollect( $value , $sort , $distinctKey , $limit ) ,
            ]) ;
        }

        assertAttributeName( $property ) ; // defensive: property is config-trusted, but cheap to guard.

        return match ( $type )
        {
            Facet::FIELD => compile
            ([
                $for ,
                $filter ,
                ...$this->facetCountCollect( key( $property , $docRef ) , $sort , null , $limit ) ,
            ]) ,

            Facet::IN , Facet::LIST , Facet::LIST_FIELD , Facet::LIST_FIELD_SORTED => compile
            ([
                $for ,
                $filter ,
                aqlFor( [ AQL::DOC_REF => self::FACET_COUNT_ITEM , AQL::IN => key( $property , $docRef ) ] ) ,
                ...$this->facetCountCollect( self::FACET_COUNT_ITEM , $sort , $distinctKey , $limit ) ,
            ]) ,

            default => null ,
        } ;
    }

    /**
     * The shared `COLLECT value = <expr> … SORT count DESC, value ASC RETURN { value, count }` tail.
     *
     * By default the bucket count is the number of unwound rows
     * (`WITH COUNT INTO count`). When `$distinctKey` is provided (opt-in
     * `Facet::DISTINCT => true` on an unwinding facet), the count becomes the
     * number of DISTINCT root documents in the bucket
     * (`AGGREGATE count = COUNT_DISTINCT( <distinctKey> )`), so a document whose
     * array repeats the same sub-field value is counted once — matching the
     * `?filter=` existence semantics. The aggregate is deliberately named
     * {@see Group::COUNT_NAME} (`count`) so the derived `RETURN { value, count }`
     * and the `SORT count DESC, value ASC` clause stay identical in both modes.
     *
     * When `$limit` is provided (opt-in `Facet::LIMIT => n`), a `LIMIT n` closes
     * the tail **after** the sort, so what survives is the *n biggest* buckets
     * rather than an arbitrary n of them.
     *
     * @param string $expression The value expression to group on.
     * @param string $sort The pre-built `SORT count DESC, value ASC` clause.
     * @param string|null $distinctKey The root document key expression to count
     *                                 distinctly (e.g. `doc._key`), or null for
     *                                 the default per-element count.
     * @param int|null $limit The number of buckets to keep, or null for all of them.
     *
     * @return array<int,string> The `[ COLLECT, SORT, (LIMIT,) RETURN ]` fragments.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    private function facetCountCollect( string $expression , string $sort , ?string $distinctKey = null , ?int $limit = null ) :array
    {
        $spec = $distinctKey !== null
              ? [
                    AQL::ASSIGN    => [ self::FACET_COUNT_VALUE => $expression ] ,
                    AQL::AGGREGATE => [ Group::COUNT_NAME => countDistinct( $distinctKey ) ] ,
                ]
              : [
                    AQL::ASSIGN     => [ self::FACET_COUNT_VALUE => $expression ] ,
                    AQL::WITH_COUNT => Group::COUNT_NAME ,
                ] ;

        // The limit is written in clear rather than bound: the counting
        // sub-queries of every requested dimension share one bind map, so a
        // per-dimension `@LIMIT` would collide. It is an integer already
        // validated by facetCountLimit(), so nothing from the wire reaches the
        // query text — the same reasoning as the inlined `quant` of the filters.
        $tail = $limit === null ? [] : [ aqlLimit( $limit ) ] ;

        return [ aqlCollect( $spec ) , $sort , ...$tail , aqlCollectReturn( $spec ) ] ;
    }

    /**
     * Resolves how many buckets a dimension keeps: the number of the `LIMIT`, or
     * null when it is unlimited (the default).
     *
     * Two voices can speak, and the request has the last word. `Facet::LIMIT`
     * declares the dimension's **default** — the sane cap a sidebar wants on a
     * large vocabulary — and `Arango::FACET_COUNTS_LIMIT` overrides it for one
     * request, raising it as well as lowering it. The override has three states,
     * which is why it is not merely an integer:
     *
     * - **null** (absent) → the declaration decides;
     * - a **positive integer** → that many buckets, whatever was declared;
     * - **`false`** → explicitly unlimited, cancelling a declared limit. It is
     *   what the controller translates `?facetCountsLimit=all` into — the model
     *   never sees the HTTP word.
     *
     * A **non-positive or non-integer** limit is refused rather than ignored, on
     * either side, and the reason is the helper it would reach: {@see aqlLimit()}
     * answers an empty string to `0` or less, which emits **no `LIMIT` clause at
     * all** — so a limit asking for nothing would silently return *everything*,
     * the exact opposite of what it says. A limit that cannot be honoured shows.
     *
     * @param array          $facet    The facet definition.
     * @param string         $key      The facet key, named in the refusal message.
     * @param int|false|null $override The request-level override, or null for none.
     *
     * @return int|null The number of buckets to keep, or null when unlimited.
     *
     * @throws ValidationException When the declared limit, or the override, is not a positive integer.
     */
    private function facetCountLimit( array $facet , string $key , int|false|null $override = null ) :?int
    {
        if ( $override === false )
        {
            return null ; // explicitly unlimited: the request cancels the declaration.
        }

        if ( $override !== null )
        {
            return $this->assertPositiveLimit( $override , sprintf( 'the "%s" parameter' , Arango::FACET_COUNTS_LIMIT ) ) ;
        }

        $limit = $facet[ Facet::LIMIT ] ?? null ;

        return $limit === null ? null : $this->assertPositiveLimit( $limit , sprintf( 'Facet::LIMIT on "%s"' , $key ) ) ;
    }

    /**
     * Resolves how a linked facet reaches the documents it counts: the `FOR`
     * opening the related documents and — for a key-join — the `FILTER` tying
     * them to the main one.
     *
     * The two types differ only in that reaching, exactly as they do on the
     * filtering side ({@see \oihana\arango\models\traits\aql\facets\HasFacetEdge}
     * vs {@see \oihana\arango\models\traits\aql\facets\HasFacetJoin}): an `INBOUND`
     * traversal needs no predicate — it already targets the right vertices —
     * while a join opens the whole collection and narrows it with its match.
     * The join anchor is shared with the filtering facets through {@see resolveFacetJoin},
     * so a declaration counts over exactly the relation it filters on.
     *
     * The declared names are guarded by {@see assertAttributeName()}: they are
     * config-trusted, but a missing edge collection or joined collection would
     * otherwise compile to a truncated `FOR … IN` — a broken query blamed on the
     * request rather than on the declaration.
     *
     * @param array $facet The facet definition (`AQL::EDGE`, or `AQL::COLLECTION` / `AQL::KEY` / `Facet::PROPERTY` / `AQL::ARRAY`).
     * @param string $key The facet key; drives the related document reference (`doc_<key>`).
     * @param string $type The facet type ({@see Facet::EDGE} or {@see Facet::JOIN}).
     * @param string $docRef The main document reference.
     *
     * @return array{0:string,1:array<int,string>} The related document reference,
     *         and the AQL fragments reaching it (a `FOR`, plus a `FILTER` for a join).
     *
     * @throws ConstantException
     * @throws ReflectionException
     * @throws ValidationException
     */
    private function facetCountRelation( array $facet , string $key , string $type , string $docRef ) :array
    {
        if ( $type === Facet::EDGE )
        {
            $edge = $facet[ AQL::EDGE ] ?? null ;

            assertAttributeName( $edge ) ;

            $relationRef = AQL::DOC_PREFIX . $key ;

            return
            [
                $relationRef ,
                [ aqlFor( [ AQL::DOC_REF => $relationRef , AQL::IN => compile( [ resolveFacetDirection( $facet ) , $docRef , $edge ] ) ] ) ] ,
            ] ;
        }

        assertAttributeName( $facet[ AQL::COLLECTION ] ?? null ) ;
        assertAttributeName( $facet[ AQL::KEY        ] ?? Schema::_KEY ) ;
        assertAttributeName( $facet[ Facet::PROPERTY ] ?? $key ) ;

        [ $relationRef , $joinFor , $match ] = resolveFacetJoin( $key , $facet , $docRef ) ;

        return [ $relationRef , [ $joinFor , aqlFilter( $match ) ] ] ;
    }
}
