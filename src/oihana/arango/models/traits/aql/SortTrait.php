<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\Search;
use oihana\enums\Char;
use oihana\enums\Order;
use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;
use oihana\traits\SortDefaultTrait;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\geo\distance;
use function oihana\arango\db\functions\search\bm25;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\resolveGeoPoint;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Turns the textual `?sort=` grammar into an AQL `SORT` expression, and powers
 * distance ordering via the `?near=` anchor.
 *
 * ### `?sort=` grammar
 * A comma-separated list of keys; a leading `-` flips a key to descending. Each
 * key is resolved through the model's {@see AQL::SORTABLE} whitelist (URL key →
 * AQL field path); unknown keys are silently dropped. When no `?sort=` is given,
 * the model's `SORT_DEFAULT` applies.
 * ```
 * ?sort=name,-created   // SORT doc.name ASC, doc.created DESC
 * ```
 *
 * ### Distance ordering (`?near=`)
 * `?near={ "key":"geo", "latitude":48.85, "longitude":2.35 }` provides a
 * reference point and exposes the synthetic sort key **`distance`**
 * ({@see Schema::DISTANCE}). It is **sort-only** — it orders, it does not filter
 * (pair it with a `geo` `?filter=` to bound a radius). `?sort=` stays the single
 * ordering authority:
 * - `?near=…` alone (no `?sort=`) defaults to `SORT <distance> ASC`.
 * - `?near=…&sort=-distance` orders farthest first.
 * - `?near=…&sort=distance,name` orders by distance then name (you pick the priority).
 * - `?near=…&sort=name` keeps `name` only — distance is **not** auto-appended.
 * - `?sort=distance` without `?near=` is dropped (no anchor).
 *
 * The reference point is bound (`@lat` / `@lng`) and the predicate uses
 * `DISTANCE(doc.<key>.latitude, doc.<key>.longitude, @lat, @lng)`, so it is
 * index-accelerated by a two-field `GeoIndex`. Coordinates are bound **only**
 * when a `distance` criterion is actually emitted, so the query never declares
 * an unused bind variable.
 *
 * @package oihana\arango\models\traits\aql
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
trait SortTrait
{
    use BindTrait ,
        SortDefaultTrait ;

    /**
     * The collection (map) of all the sortable fields.
     */
    public ?array $sortable = null ;

    /**
     * Initialize the sortable array definition.
     * @param array $init
     * @return $this
     */
    public function initializeSortable( array $init = [] ):static
    {
        $this->sortable = $init[ AQL::SORTABLE ] ?? $this->sortable ;
        return $this ;
    }

    /**
     * Prepare the AQL `SORT` expression from the `?sort=` grammar and, optionally, the `?near=` anchor.
     *
     * Each comma-separated criterion in `Arango::SORT` is resolved against `$sortable`
     * (URL key → AQL field path); a leading `-` makes it descending. The synthetic
     * `distance` key ({@see Schema::DISTANCE}) is resolved from `Arango::NEAR` and only
     * honored when `$binds` is provided (so the reference point can be bound).
     *
     * @param array      $init     Per-call parameters. Reads `Arango::SORT` (grammar) and `Arango::NEAR` (geo anchor).
     * @param array|null $sortable URL-key → field-path whitelist. Defaults to `$this->sortable`.
     * @param string     $docRef   The document variable the fields hang off (default `doc`).
     * @param array|null $binds    Bind variables, populated by reference. Required to enable `distance`/`?near=` sorting.
     *
     * @return string|null The `SORT` body (without the `SORT` keyword), or an empty string when nothing sorts.
     *
     * @throws BindException When a bound coordinate cannot be registered.
     * @throws ValidationException When a sort key (open mode) or the `?near=` `key` is not a safe attribute name.
     *
     * @example Plain field sort
     * ```php
     * $model->prepareSort( [ Arango::SORT => 'name,-created' ] ) ;
     * // "doc.name ASC, doc.created DESC"
     * ```
     *
     * @example Distance sort (nearest first) via `?near=`
     * ```php
     * $binds = [] ;
     * $model->prepareSort
     * (
     *     [ Arango::NEAR => [ FilterParam::KEY => 'geo' , 'latitude' => 48.85 , 'longitude' => 2.35 ] ] ,
     *     binds : $binds
     * ) ;
     * // "DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) ASC"
     * ```
     *
     * @example Distance then name
     * ```php
     * $model->prepareSort
     * (
     *     [ Arango::SORT => 'distance,name' , Arango::NEAR => [ ... ] ] ,
     *     binds : $binds
     * ) ;
     * // "DISTANCE(...) ASC, doc.name ASC"
     * ```
     */
    public function prepareSort
    (
        array  $init     = [] ,
        ?array $sortable = null ,
        string $docRef   = AQL::DOC ,
        ?array &$binds   = null
    )
    :?string
    {
        $sort       = $init[ Arango::SORT ] ?? $this->sortDefault ;
        $sortable ??= $this->sortable ;
        $orders     = is_array( $sort ) ? $sort : [] ;

        $nearActive = $binds !== null && is_array( $init[ Arango::NEAR ] ?? null ) ;

        // Synthetic relevance key, driven by an active View search (AQL::VIEW
        // declaration + ?search term) — the score counterpart of ?near=/distance.
        $scoreActive = $binds !== null
                    && is_callable( [ $this , 'hasViewSearch' ] )
                    && $this->hasViewSearch( $init ) ;

        $explicit = $init[ Arango::SORT ] ?? null ;

        // An active search alone (no ?sort=) defaults to a most-relevant-first
        // score sort (descending) — relevance outranks the model's sortDefault.
        if( $scoreActive && ( $explicit === null || $explicit === Char::EMPTY ) )
        {
            $sort = Char::HYPHEN . Search::SCORE ;
        }
        // ?near= alone (no ?sort=) defaults to a nearest-first distance sort.
        elseif( $nearActive && ( $sort === null || $sort === Char::EMPTY ) )
        {
            $sort = Schema::DISTANCE ;
        }

        $nearExpression = null ;
        $nearResolved   = false ;

        if( is_string( $sort ) )
        {
            $criteria = explode( Char::COMMA , $sort ) ;

            foreach( $criteria as $key )
            {
                if( empty( $key ) )
                {
                    continue ;
                }

                if( $key[0] === Char::HYPHEN )
                {
                    $order = Order::DESC ;
                    $key   = ltrim( $key , Char::HYPHEN ) ;
                }
                else
                {
                    $order = Order::ASC ;
                }

                // Synthetic relevance key, driven by the active View search:
                // resolves to the BM25 score of the document (descending = most
                // relevant first). Dropped when no View search is active.
                if( $key === Search::SCORE )
                {
                    if( $scoreActive )
                    {
                        $orders[] = bm25( $docRef ) . Char::SPACE . $order ;
                    }
                    continue ;
                }

                // Synthetic distance key, driven by ?near= (bound lazily, only when emitted).
                if( $key === Schema::DISTANCE )
                {
                    if( $nearActive )
                    {
                        if( !$nearResolved )
                        {
                            $nearResolved   = true ;
                            $nearExpression = $this->prepareNear( $init[ Arango::NEAR ] , $binds , $docRef ) ;
                        }

                        if( $nearExpression !== null )
                        {
                            $orders[] = $nearExpression . Char::SPACE . $order ;
                        }
                    }
                    continue ;
                }

                if( is_array( $sortable ) )
                {
                    if( array_key_exists( $key , $sortable ) )
                    {
                        $orders[] = key( compile( $sortable[ $key ] ?? null , Char::DOT ) , $docRef ) . Char::SPACE . $order ;
                    }
                }
                else
                {
                    // Open mode (no whitelist): the URL key flows into doc.<key>,
                    // so it must be validated against AQL injection.
                    assertAttributeName( $key ) ;
                    $orders[] = key( $key , $docRef ) . Char::SPACE . $order ;
                }
            }
        }

        return compile( $orders , Char::COMMA . Char::SPACE ) ;
    }

    /**
     * Build the `DISTANCE(...)` expression for a `?near=` anchor and bind its coordinates.
     *
     * Reads the `{ key, latitude, longitude }` payload, validates the attribute key
     * against injection ({@see assertAttributeName()}), binds the reference point, and
     * returns the AQL distance expression. Returns `null` when the `key` is missing or
     * the coordinates are incomplete.
     *
     * @param array      $near   The `?near=` payload (`{ key, latitude, longitude }`), already array-checked by the caller.
     * @param array|null $binds  Bind variables, populated by reference.
     * @param string     $docRef The document variable the fields hang off.
     *
     * @return string|null `DISTANCE(doc.<key>.latitude, doc.<key>.longitude, @lat, @lng)` or `null`.
     *
     * @throws BindException When a bound coordinate cannot be registered.
     * @throws ValidationException When the `key` is not a safe attribute name.
     */
    protected function prepareNear( array $near , ?array &$binds , string $docRef = AQL::DOC ): ?string
    {
        $key = $near[ FilterParam::KEY ] ?? null ;

        if( !is_string( $key ) || $key === Char::EMPTY )
        {
            return null ;
        }

        [ $latitude , $longitude ] = resolveGeoPoint( $near ) ;

        if( $latitude === null || $longitude === null )
        {
            return null ;
        }

        assertAttributeName( $key ) ;

        return distance
        (
            key( $key . Char::DOT . Schema::LATITUDE  , $docRef ) ,
            key( $key . Char::DOT . Schema::LONGITUDE , $docRef ) ,
            $this->bind( $latitude  , $binds ) ,
            $this->bind( $longitude , $binds )
        ) ;
    }
}
