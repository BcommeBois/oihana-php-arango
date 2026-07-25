<?php

namespace oihana\arango\models\helpers\facets;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Facet;

use org\schema\constants\Prop;

use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\in;
use function oihana\core\strings\key;

/**
 * Resolves the **anchor** of a key-join facet: the `FOR` opening the joined
 * collection and the predicate tying a joined document to the main one.
 *
 * The three join facets — {@see \oihana\arango\models\traits\aql\facets\HasFacetJoin},
 * {@see \oihana\arango\models\traits\aql\facets\HasFacetJoinAggregate} and
 * {@see \oihana\arango\models\traits\aql\facets\HasFacetJoinComplex} — differ in
 * what they *do* with the joined documents (test their existence, aggregate a
 * numeric field over them, match several of their fields), never in how they
 * *reach* them. That reaching is this helper: it reads the join definition and
 * returns the three fragments every one of them needs.
 *
 * The join is `doc_<key>.<AQL::KEY> == doc.<Facet::PROPERTY>`, with `AQL::KEY`
 * the joined side (default `_key`) and `Facet::PROPERTY` the main side (default
 * the facet key) — which expresses both "the document holds the foreign key"
 * and the reverse one-to-many "the joined documents reference the document".
 * When `AQL::ARRAY` is set the main side holds an array of keys, so the
 * equality becomes a membership test (`IN`).
 *
 * @param string $key   The facet key; drives the joined document reference (`doc_<key>`) and the default main-side property.
 * @param array  $facet The facet definition (`AQL::COLLECTION`, `AQL::KEY`, `Facet::PROPERTY`, `AQL::ARRAY`).
 * @param string $doc   The main document reference.
 *
 * @return array{0:string,1:string,2:string} A `[ docRef , for , match ]` triplet:
 *         the joined document reference, the `FOR doc_<key> IN <collection>`
 *         clause, and the join predicate to place in the `FILTER`.
 *
 * @throws ReflectionException
 *
 * @example
 * ```php
 * use function oihana\arango\models\helpers\facets\resolveFacetJoin;
 *
 * // Posts joined to their author: doc.authorId == author._key
 * [ $docRef , $for , $match ] = resolveFacetJoin( 'author' , [ AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' ] , 'doc' ) ;
 * // $docRef = 'doc_author'
 * // $for    = 'FOR doc_author IN authors'
 * // $match  = 'doc_author._key == doc.authorId'
 *
 * // The main document holds an array of keys
 * [ , , $match ] = resolveFacetJoin( 'tags' , [ AQL::COLLECTION => 'tags' , AQL::ARRAY => true , Facet::PROPERTY => 'tagIds' ] , 'doc' ) ;
 * // $match = 'doc_tags._key IN doc.tagIds'
 * ```
 *
 * @package oihana\arango\models\helpers\facets
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
function resolveFacetJoin( string $key , array $facet , string $doc ) : array
{
    $docRef     = AQL::DOC_PREFIX . $key ;
    $collection = $facet[ AQL::COLLECTION ] ?? null ;
    $joinKey    = $facet[ AQL::KEY        ] ?? Prop::_KEY ;
    $property   = $facet[ Facet::PROPERTY ] ?? $key ;
    $isArray    = $facet[ AQL::ARRAY      ] ?? false ;

    // Join match: doc_<key>.<KEY> == doc.<PROPERTY>  (or IN for an array of keys)
    $joinLeft  = key( $joinKey  , $docRef ) ;
    $joinRight = key( $property , $doc ) ;

    return
    [
        $docRef ,
        aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => $collection ] ) ,
        $isArray ? in( $joinLeft , $joinRight ) : equal( $joinLeft , $joinRight ) ,
    ] ;
}
