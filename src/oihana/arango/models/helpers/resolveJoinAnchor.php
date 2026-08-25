<?php

namespace oihana\arango\models\helpers;

use ReflectionException;

use oihana\arango\db\enums\AQL;

use org\schema\constants\Prop;

use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\in;
use function oihana\core\strings\key;

/**
 * Resolves the **anchor** of a key-join: the `FOR` opening the joined collection
 * and the predicate tying a joined document to the main one.
 *
 * Several surfaces join the same way and differ only in what they *do* with the
 * joined documents — a facet tests their existence or counts them, a bound
 * measures one of their numeric fields — and in the declaration key naming the
 * main side (`Facet::PROPERTY`, `Bound::PROPERTY`, …). That last difference is
 * the `$propertyKey` parameter; everything else is shared, so a declaration
 * cannot mean one thing when it filters and another when it measures.
 *
 * The join is `doc_<key>.<AQL::KEY> == doc.<property>`, with `AQL::KEY` the
 * joined side (default `_key`) and the property the main side (default the
 * entry key) — which expresses both "the document holds the foreign key" and
 * the reverse one-to-many "the joined documents reference the document". When
 * `AQL::ARRAY` is set the main side holds an array of keys, so the equality
 * becomes a membership test (`IN`).
 *
 * @param string $key         The entry key; drives the joined document reference (`doc_<key>`) and the default main-side property.
 * @param array  $definition  The declaration (`AQL::COLLECTION`, `AQL::KEY`, `AQL::ARRAY`, and the main-side property).
 * @param string $doc         The main document reference.
 * @param string $propertyKey The declaration key naming the main-side property.
 *
 * @return array{0:string,1:string,2:string} A `[ docRef , for , match ]` triplet:
 *         the joined document reference, the `FOR doc_<key> IN <collection>`
 *         clause, and the join predicate to place in the `FILTER`.
 *
 * @throws ReflectionException
 *
 * @package oihana\arango\models\helpers
 * @since   1.7.0
 * @author  Marc Alcaraz
 */
function resolveJoinAnchor( string $key , array $definition , string $doc , string $propertyKey ) : array
{
    $docRef     = AQL::DOC_PREFIX . $key ;
    $collection = $definition[ AQL::COLLECTION ] ?? null ;
    $joinKey    = $definition[ AQL::KEY        ] ?? Prop::_KEY ;
    $property   = $definition[ $propertyKey    ] ?? $key ;
    $isArray    = $definition[ AQL::ARRAY      ] ?? false ;

    // Join match: doc_<key>.<KEY> == doc.<property>  (or IN for an array of keys)
    $joinLeft  = key( $joinKey  , $docRef ) ;
    $joinRight = key( $property , $doc ) ;

    return
    [
        $docRef ,
        aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => $collection ] ) ,
        $isArray ? in( $joinLeft , $joinRight ) : equal( $joinLeft , $joinRight ) ,
    ] ;
}
