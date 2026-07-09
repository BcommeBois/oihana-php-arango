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

use function oihana\arango\db\functions\arrays\countDistinct;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\operations\aqlCollect;
use function oihana\arango\db\operations\aqlCollectReturn;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlSort;
use function oihana\arango\db\helpers\aqlDocument;
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
 * LET category = (FOR doc IN @@coll FILTER <same filters> COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN { value, count })
 * LET status   = (FOR doc IN @@coll FILTER <same filters> COLLECT value = doc.status   WITH COUNT INTO count SORT count DESC RETURN { value, count })
 * RETURN { category, status }
 * ```
 *
 * v1 supports the scalar {@see Facet::FIELD} and the array-membership
 * {@see Facet::IN} family ({@see Facet::LIST}, {@see Facet::LIST_FIELD},
 * {@see Facet::LIST_FIELD_SORTED}); other facet types are skipped. A
 * `Facet::PROPERTY` carrying the `[*]` array-expansion marker (e.g.
 * `offers[*].priceCurrency`) unwinds the object array and counts the sub-field
 * per element — see {@see FacetCountsQueryTrait::buildFacetCountSubquery()}.
 *
 * The unwinding facet types (the `[*]` expansion and the {@see Facet::IN}
 * family) count array *elements* by default, so a document whose array repeats
 * the same value in several elements is counted several times — diverging from
 * the equivalent `?filter=` existence test, which counts *documents*. Declaring
 * `Facet::DISTINCT => true` on such a facet switches its bucket count to
 * `COUNT_DISTINCT( doc._key )`, so the count reflects distinct root documents
 * and matches the filter. The flag is opt-in (default unchanged) and a no-op on
 * the scalar {@see Facet::FIELD} type, which already counts one row per document.
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

            $subquery = $this->buildFacetCountSubquery( $facet , $dimension , $for , $filter , $docRef ) ;
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
     * @param array       $facet The facet definition (`Facet::TYPE`, `Facet::PROPERTY`).
     * @param string      $key The facet key (default property).
     * @param string      $for The pre-built `FOR` segment shared by every dimension —
     *                         the bound collection, or the bound View with its `SEARCH`
     *                         segment when the View search is active.
     * @param string|null $filter The shared `FILTER` clause.
     * @param string      $docRef The document reference.
     *
     * @return string|null
     *
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildFacetCountSubquery( array $facet , string $key , string $for , ?string $filter , string $docRef ) :?string
    {
        $type     = $facet[ Facet::TYPE     ] ?? Facet::FIELD ;
        $property = $facet[ Facet::PROPERTY ] ?? $key ;

        $sort = aqlSort( compile( [ Group::COUNT_NAME , Order::DESC ] ) ) ;

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
            // Split on the marker: 'a[*].b.c[*].d' → ['a', '.b.c', '.d']. Every
            // segment but the last opens a FOR hop (relative to the previous item
            // reference); the last segment is the projected leaf (empty for a
            // bare `tags[*]`, which counts the element itself).
            $segments = explode( Operator::ARRAY_EXPANSION , $property ) ;
            $last     = count( $segments ) - 1 ;

            $reference = $docRef ;
            $fors      = [] ;
            for ( $i = 0 ; $i < $last ; $i++ )
            {
                $container = ltrim( $segments[ $i ] , Char::DOT ) ;
                assertAttributeName( $container ) ; // defensive: config-trusted, but cheap to guard.

                $itemRef = $i === 0 ? self::FACET_COUNT_ITEM : self::FACET_COUNT_ITEM . ( $i + 1 ) ;
                $fors[]  = aqlFor( [ AQL::DOC_REF => $itemRef , AQL::IN => key( $container , $reference ) ] ) ;
                $reference = $itemRef ;
            }

            $leaf  = ltrim( $segments[ $last ] , Char::DOT ) ;
            $value = $reference ;
            if ( $leaf !== Char::EMPTY )
            {
                assertAttributeName( $leaf ) ;
                $value = key( $leaf , $reference ) ;
            }

            return compile(
            [
                $for ,
                $filter ,
                ...$fors ,
                ...$this->facetCountCollect( $value , $sort , $distinctKey ) ,
            ]) ;
        }

        assertAttributeName( $property ) ; // defensive: property is config-trusted, but cheap to guard.

        return match ( $type )
        {
            Facet::FIELD =>
                compile(
                [
                    $for ,
                    $filter ,
                    ...$this->facetCountCollect( key( $property , $docRef ) , $sort ) ,
                ]) ,

            Facet::IN , Facet::LIST , Facet::LIST_FIELD , Facet::LIST_FIELD_SORTED =>
                compile(
                [
                    $for ,
                    $filter ,
                    aqlFor( [ AQL::DOC_REF => self::FACET_COUNT_ITEM , AQL::IN => key( $property , $docRef ) ] ) ,
                    ...$this->facetCountCollect( self::FACET_COUNT_ITEM , $sort , $distinctKey ) ,
                ]) ,

            default => null ,
        } ;
    }

    /**
     * The shared `COLLECT value = <expr> … SORT count DESC RETURN { value, count }` tail.
     *
     * By default the bucket count is the number of unwound rows
     * (`WITH COUNT INTO count`). When `$distinctKey` is provided (opt-in
     * `Facet::DISTINCT => true` on an unwinding facet), the count becomes the
     * number of DISTINCT root documents in the bucket
     * (`AGGREGATE count = COUNT_DISTINCT( <distinctKey> )`), so a document whose
     * array repeats the same sub-field value is counted once — matching the
     * `?filter=` existence semantics. The aggregate is deliberately named
     * {@see Group::COUNT_NAME} (`count`) so the derived `RETURN { value, count }`
     * and the `SORT count DESC` clause stay identical in both modes.
     *
     * @param string      $expression The value expression to group on.
     * @param string      $sort The pre-built `SORT count DESC` clause.
     * @param string|null $distinctKey The root document key expression to count
     *                                 distinctly (e.g. `doc._key`), or null for
     *                                 the default per-element count.
     *
     * @return array{0:string,1:string,2:string} `[ COLLECT, SORT, RETURN ]` fragments.
     *
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    private function facetCountCollect( string $expression , string $sort , ?string $distinctKey = null ) :array
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

        return [ aqlCollect( $spec ) , $sort , aqlCollectReturn( $spec ) ] ;
    }
}
