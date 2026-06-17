<?php

namespace oihana\arango\db\options\views ;

use oihana\arango\clients\view\enums\ViewField ;

/**
 * Declarative definition of a `search-alias` view — the unit the view
 * lifecycle tooling (`searchAliasViewDiff()` / `searchAliasViewSync()` and the
 * `arango:views` action) reasons about. The federation counterpart of
 * {@see \oihana\arango\db\options\analyzers\AnalyzerDefinition}.
 *
 * A search-alias view is a thin alias over one `inverted` index per collection;
 * it owns no links. This value object bundles the `name`, the `{collection, index}`
 * entries, and any extra create `options`.
 *
 * The `indexes` argument accepts two equivalent forms — normalized by
 * {@see getIndexes()} into the server-ready list of
 * `{ collection, index }` objects:
 *
 * - **Convenience map** — `[ 'customers' => 'inv_search', 'products' => 'inv_search' ]`
 *   (collection name → inverted-index name),
 * - **Explicit list** — `[ [ ViewField::COLLECTION => 'customers', ViewField::INDEX => 'inv_search' ], … ]`.
 *
 * Example:
 * ```php
 * new SearchAliasView
 * (
 *     'global_search' ,
 *     [ 'customers' => 'inv_search' , 'products' => 'inv_search' ] ,
 * ) ;
 * ```
 *
 * @package oihana\arango\db\options\views
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
readonly class SearchAliasView
{
    /**
     * @param string               $name    The view name (local to the database).
     * @param array<mixed>         $indexes The `{collection, index}` entries — a `collection => index`
     *                                      map or an explicit list of `{collection, index}`.
     * @param array<string, mixed> $options Extra create options forwarded verbatim to the server.
     */
    public function __construct
    (
        public string $name ,
        public array  $indexes = [] ,
        public array  $options = [] ,
    )
    {
    }

    /**
     * Returns the `indexes` normalized to the server-ready list of
     * `{ collection, index }` objects, accepting either declaration form
     * (collection→index map or explicit list). Malformed entries are dropped.
     *
     * @return array<int, array{collection:string, index:string}>
     */
    public function getIndexes() : array
    {
        $normalized = [] ;

        foreach ( $this->indexes as $key => $value )
        {
            if ( is_string( $key ) && is_string( $value ) )
            {
                // Convenience map form: collection => index
                $normalized[] = [ ViewField::COLLECTION => $key , ViewField::INDEX => $value ] ;
                continue ;
            }

            if ( is_array( $value ) && isset( $value[ ViewField::COLLECTION ] , $value[ ViewField::INDEX ] ) )
            {
                // Explicit list form: { collection, index }
                $normalized[] =
                [
                    ViewField::COLLECTION => $value[ ViewField::COLLECTION ] ,
                    ViewField::INDEX      => $value[ ViewField::INDEX ] ,
                ] ;
            }
        }

        return $normalized ;
    }
}
