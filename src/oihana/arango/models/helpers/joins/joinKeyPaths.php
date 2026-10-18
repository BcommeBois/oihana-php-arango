<?php

namespace oihana\arango\models\helpers\joins;

use oihana\exceptions\UnsupportedOperationException;

use function oihana\core\strings\key;

/**
 * Where the keys of a join are read in the parent document — the path an `IN` compares
 * them with, the list they come from, and where each element of that list carries its
 * own key.
 *
 * 🔑 **`Arango::PROPERTY` is appended to the anchor, and an expansion `[*]` splits it in
 * two** : what comes before reaches the list, what comes after is read on each element.
 * A list of objects `[ { _key , id } , … ]` is therefore joined on `'[*]._key'`.
 *
 * 🚨 **An expansion is glued to the path it expands, without a dot.** `key()` puts a dot
 * between two segments, so the property `'[*]._key'` used to produce `doc.refs.[*]._key` —
 * an AQL syntax error on every query — and `'_key'` produced `doc.refs._key`, the attribute
 * of a list, which ArangoDB answers with `null` : nothing was ever joined, and nothing said
 * so. A property with no expansion keeps the path it always had.
 *
 * | `Arango::PROPERTY`  | the `IN` path         | the list      | the element key |
 * | :------------------ | :-------------------- | :------------ | :-------------- |
 * | `null`              | `doc.refs`            | `doc.refs`    | `null`          |
 * | `'ids'`             | `doc.refs.ids`        | `doc.refs.ids`| `null`          |
 * | `'[*]._key'`        | `doc.refs[*]._key`    | `doc.refs`    | `'_key'`        |
 * | `'items[*].ref'`    | `doc.refs.items[*].ref` | `doc.refs.items` | `'ref'`    |
 *
 * A `null` element key means the elements ARE the keys — a list of bare keys.
 *
 * ⚠️ **One expansion, no more.** A second `[*]` would compare a key with a list of lists,
 * which never matches : it is refused rather than left to answer nothing.
 *
 * @param string            $anchor   The AQL path of the joined field in the parent document (`doc.refs`).
 * @param null|string|array $property The `Arango::PROPERTY` of the join definition. An array is appended
 *                                    as it always was, and carries no expansion.
 *
 * @return array{0:string,1:string,2:string|null} The `IN` path, the list path, and the key read on each
 *                                                element (or `null` when the elements are the keys).
 *
 * @throws UnsupportedOperationException When the property carries more than one expansion.
 *
 * @example
 * ```php
 * joinKeyPaths( 'doc.refs' , '[*]._key' ) ;
 * // [ 'doc.refs[*]._key' , 'doc.refs' , '_key' ]
 * ```
 *
 * @package oihana\arango\models\helpers\joins
 * @since 1.11.0
 * @author Marc Alcaraz
 */
function joinKeyPaths( string $anchor , null|string|array $property = null ) :array
{
    if ( !is_string( $property ) || !str_contains( $property , '[*]' ) )
    {
        $path = $property === null ? $anchor : key( $property , $anchor ) ;

        return [ $path , $path , null ] ;
    }

    [ $head , $tail ] = explode( '[*]' , $property , 2 ) ;

    if ( str_contains( $tail , '[*]' ) )
    {
        throw new UnsupportedOperationException
        (
            __FUNCTION__ . " failed, the join property '" . $property . "' expands more than one list : a key compared with a list of lists never matches."
        ) ;
    }

    $head = rtrim( $head , '.' ) ;
    $tail = ltrim( $tail , '.' ) ;
    $list = $head === '' ? $anchor : key( $head , $anchor ) ;

    return
    [
        $list . '[*]' . ( $tail === '' ? '' : '.' . $tail ) ,
        $list ,
        $tail === '' ? null : $tail ,
    ] ;
}
