<?php

namespace oihana\arango\clients\view ;

use oihana\arango\clients\view\enums\ViewField ;

/**
 * Recursive value object describing a single link entry of an
 * ArangoSearch view (either at the top level of the `links` map —
 * one entry per indexed collection — or nested under the `fields`
 * key of a parent link, for per-attribute analyzer chains).
 *
 * The structure is self-referential: every link can carry its own
 * `fields` map, where each value is another `ArangoSearchLink`
 * describing the indexing strategy for that sub-attribute. The
 * recursion is unbounded server-side, but is typically limited to
 * one or two levels in practice.
 *
 * Example — index the `title` and `body` fields of `articles`
 * with two different analyzers:
 *
 * ```php
 * $link = new ArangoSearchLink
 * (
 *     analyzers : [ 'identity' ] ,
 *     fields    :
 *     [
 *         'title' => new ArangoSearchLink( analyzers : [ 'text_en' ] ) ,
 *         'body'  => new ArangoSearchLink
 *         (
 *             analyzers          : [ 'text_en' , 'stem_en' ] ,
 *             trackListPositions : true ,
 *         ) ,
 *     ] ,
 * ) ;
 *
 * $db->createView( 'articles_view' , links : [ 'articles' => $link ] ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/arangosearch/arangosearch-views-reference/#link-properties
 *
 * @package oihana\arango\clients\view
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class ArangoSearchLink
{
    /**
     * @param array<int, string>|null               $analyzers          List of analyzer names applied at this level. Defaults to `["identity"]` server-side when omitted.
     * @param array<string, ArangoSearchLink>|null  $fields             Per-attribute nested link map. Each value is another {@see ArangoSearchLink}.
     * @param bool|null                             $includeAllFields   Whether every attribute of the document is indexed regardless of the `fields` whitelist. Defaults to `false` server-side.
     * @param bool|null                             $trackListPositions Whether the view records the ordinal position of each value in array attributes. Defaults to `false` server-side.
     * @param string|null                           $storeValues        Per-link / per-field `storeValues` strategy — entries of {@see \oihana\arango\clients\view\enums\StoreValues}. Defaults to `"none"` server-side.
     * @param bool|null                             $inBackground       Whether to build the index in the background, without blocking concurrent writes. Defaults to `false` server-side. Top-level link only — ignored on nested `fields` entries.
     */
    public function __construct
    (
        public ?array  $analyzers          = null ,
        public ?array  $fields             = null ,
        public ?bool   $includeAllFields   = null ,
        public ?bool   $trackListPositions = null ,
        public ?string $storeValues        = null ,
        public ?bool   $inBackground       = null ,
    )
    {
    }

    /**
     * Serialises this link (and recursively its `fields` children)
     * into the array shape expected by `POST /_api/view` and the
     * `PATCH|PUT /_api/view/{name}/properties` endpoints.
     *
     * Null fields are omitted from the output so the server applies
     * its own defaults — keeps the wire payload compact and
     * round-trip-friendly.
     *
     * @return array<string, mixed>
     */
    public function toArray() : array
    {
        $data = [] ;

        if ( $this->analyzers          !== null ) { $data[ ViewField::ANALYZERS            ] = array_values( $this->analyzers ) ; }
        if ( $this->includeAllFields   !== null ) { $data[ ViewField::INCLUDE_ALL_FIELDS   ] = $this->includeAllFields   ; }
        if ( $this->trackListPositions !== null ) { $data[ ViewField::TRACK_LIST_POSITIONS ] = $this->trackListPositions ; }
        if ( $this->storeValues        !== null ) { $data[ ViewField::STORE_VALUES         ] = $this->storeValues        ; }
        if ( $this->inBackground       !== null ) { $data[ ViewField::IN_BACKGROUND        ] = $this->inBackground       ; }

        if ( $this->fields !== null )
        {
            $fields = [] ;
            foreach ( $this->fields as $name => $child )
            {
                $fields[ $name ] = $child instanceof self ? $child->toArray() : $child ;
            }
            $data[ ViewField::FIELDS ] = $fields ;
        }

        return $data ;
    }
}
