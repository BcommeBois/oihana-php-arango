<?php

namespace oihana\arango\db\helpers;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operator;
use oihana\enums\Char;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\operations\aqlFor;
use function oihana\core\strings\key;

/**
 * Unwinds an array-expansion path into the chain of `FOR` hops AQL needs to walk
 * it, plus the reference of the projected leaf.
 *
 * It is the query-side counterpart of {@see stripArrayExpansion()}: where the
 * search links and the hierarchical `?filter=` builder *flatten* the
 * {@see Operator::ARRAY_EXPANSION} marker into a dotted path, an aggregation has
 * to *unwind* it — an array element cannot be aggregated in place, it must be
 * iterated. `'offers[*].tiers[*].amount'` becomes:
 * ```aql
 * FOR item  IN doc.offers
 * FOR item2 IN item.tiers
 *   … item2.amount
 * ```
 * The path is split on the marker, every segment but the last opening one hop
 * relative to the previous item reference, and the last segment being the
 * projected leaf — empty for a bare `tags[*]`, which projects the element
 * itself. Each item reference is `<itemRef>`, then `<itemRef>2`, `<itemRef>3` …
 * (there is no `<itemRef>1`).
 *
 * Every segment is validated by {@see assertAttributeName()} — the whole path
 * never is, since it carries the markers — so a malformed path fails loud
 * instead of reaching AQL. A doubled marker (`a[*][*]`) yields an empty
 * intermediate container and is rejected on that ground.
 *
 * The helper deliberately stops at the hops: the root `FOR`, the shared `FILTER`
 * and the aggregation tail belong to the caller, and diverge between consumers
 * ({@see \oihana\arango\models\traits\queries\BoundsQueryTrait} collects
 * `MIN`/`MAX`/count, {@see \oihana\arango\models\traits\queries\FacetCountsQueryTrait}
 * counts buckets).
 *
 * @param string $property The `[*]`-bearing attribute path.
 * @param string $docRef   The document reference the first hop starts from.
 * @param string $itemRef  The base name of the item variables (default `item`).
 *
 * @return array{0:string[],1:string} A `[ fors , value ]` pair: the `FOR` hops in
 *         order, and the reference to project (the leaf, or the innermost item
 *         when the path ends on a marker).
 *
 * @throws ReflectionException
 * @throws ValidationException When a container or the leaf is not a valid attribute name.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\expandArrayPath;
 *
 * expandArrayPath( 'offers[*].price' , 'doc' ) ;
 * // [ [ 'FOR item IN doc.offers' ] , 'item.price' ]
 *
 * expandArrayPath( 'a[*].b.c[*].d' , 'doc' ) ;
 * // [ [ 'FOR item IN doc.a' , 'FOR item2 IN item.b.c' ] , 'item2.d' ]
 *
 * expandArrayPath( 'tags[*]' , 'doc' ) ;
 * // [ [ 'FOR item IN doc.tags' ] , 'item' ]
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
function expandArrayPath( string $property , string $docRef , string $itemRef = 'item' ) : array
{
    // Split on the marker: 'a[*].b.c[*].d' → ['a', '.b.c', '.d']. Every
    // segment but the last opens a FOR hop (relative to the previous item
    // reference); the last segment is the projected leaf (empty for a
    // bare `tags[*]`, which projects the element itself).
    $segments = explode( Operator::ARRAY_EXPANSION , $property ) ;
    $last     = count( $segments ) - 1 ;

    $reference = $docRef ;
    $fors      = [] ;
    for ( $i = 0 ; $i < $last ; $i++ )
    {
        $container = ltrim( $segments[ $i ] , Char::DOT ) ;
        assertAttributeName( $container ) ; // defensive: config-trusted, but cheap to guard.

        $hop       = $itemRef . ( $i === 0 ? Char::EMPTY : ( $i + 1 ) ) ;
        $fors[]    = aqlFor( [ AQL::DOC_REF => $hop , AQL::IN => key( $container , $reference ) ] ) ;
        $reference = $hop ;
    }

    $leaf  = ltrim( $segments[ $last ] , Char::DOT ) ;
    $value = $reference ;
    if ( $leaf !== Char::EMPTY )
    {
        assertAttributeName( $leaf ) ;
        $value = key( $leaf , $reference ) ;
    }

    return [ $fors , $value ] ;
}
