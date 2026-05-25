<?php

namespace oihana\arango\clients\cursor ;

use Countable ;
use Generator ;
use IteratorAggregate ;
use RuntimeException ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\cursor\enums\CursorField ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;

/**
 * Iterator over the result of an AQL query.
 *
 * Wraps the initial response returned by `POST /_api/cursor` and
 * automatically fetches subsequent batches (`POST /_api/cursor/{id}`)
 * as the caller iterates through the results. The cursor is **lazy**:
 * a new batch is only fetched when the previous one has been fully
 * consumed.
 *
 * Implements `IteratorAggregate` (yields each row exactly once during a
 * single pass — the underlying server-side cursor is not rewindable)
 * and `Countable` (returns the server-side total when the query was
 * created with the `count: true` option).
 *
 * Example:
 * ```php
 * $cursor = $db->query
 * (
 *     aql( 'FOR u IN users FILTER u.active == ? RETURN u' , true ) ,
 *     options : [ 'count' => true ] ,
 * ) ;
 *
 * foreach ( $cursor as $user )
 * {
 *     // ...
 * }
 * echo count( $cursor ) ; // server-side total
 * ```
 *
 * @package oihana\arango\clients\cursor
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class Cursor implements IteratorAggregate , Countable
{
    /**
     * @param Database             $database        Parent database (used to fetch subsequent batches and to close the server-side cursor).
     * @param array<string, mixed> $initialResponse Decoded body of the initial `POST /_api/cursor` response.
     */
    public function __construct
    (
        public Database $database ,
        array           $initialResponse ,
    )
    {
        $this->batch      = is_array( $initialResponse[ CursorField::RESULT ] ?? null ) ? $initialResponse[ CursorField::RESULT ] : [] ;
        $this->cursorId   = isset( $initialResponse[ CursorField::ID ] ) ? (string) $initialResponse[ CursorField::ID ] : null ;
        $this->extra      = is_array( $initialResponse[ CursorField::EXTRA ] ?? null ) ? $initialResponse[ CursorField::EXTRA ] : [] ;
        $this->hasMore    = (bool) ( $initialResponse[ CursorField::HAS_MORE ] ?? false ) ;
        $this->totalCount = isset( $initialResponse[ CursorField::COUNT ] ) ? (int) $initialResponse[ CursorField::COUNT ] : null ;
    }

    /**
     * Current batch of rows (replaced on every fetched batch).
     * @var array<int, mixed>
     */
    private array $batch ;

    /**
     * Server-side cursor identifier when more batches are available, null otherwise.
     */
    private ?string $cursorId ;

    /**
     * Extra metadata (warnings, stats, profile, …) — overwritten on every fetched batch.
     * @var array<string, mixed>
     */
    private array $extra ;

    /**
     * Whether more batches remain to be fetched from the server.
     */
    private bool $hasMore ;

    /**
     * Total count of result rows on the server, or null when the query was not created with `count: true`.
     */
    private ?int $totalCount ;

    /**
     * Eagerly fetches every remaining batch and returns the full result set.
     *
     * Use this for small result sets where streaming is overkill — for
     * large queries prefer iterating with `foreach`.
     *
     * @return array<int, mixed>
     *
     * @throws ArangoException When fetching a subsequent batch fails.
     */
    public function all() : array
    {
        $rows = [] ;
        foreach ( $this as $row )
        {
            $rows[] = $row ;
        }
        return $rows ;
    }

    /**
     * Closes the server-side cursor immediately (releases its resources).
     *
     * Implicit on the last batch — the server cleans up the cursor once
     * it has been fully consumed — so this is only necessary when the
     * caller wants to abandon iteration early.
     *
     * @return void
     *
     * @throws ArangoException When the close request fails.
     */
    public function close() : void
    {
        if ( $this->cursorId === null || !$this->hasMore )
        {
            return ;
        }
        $this->database->request
        (
            method : HttpMethod::DELETE ,
            path   : ArangoRoute::CURSOR . '/' . rawurlencode( $this->cursorId ) ,
        ) ;
        $this->hasMore  = false ;
        $this->cursorId = null ;
    }

    /**
     * Returns the server-side total count of result rows.
     *
     * Only available when the query was created with the `count: true`
     * option; otherwise throws.
     *
     * @return int
     *
     * @throws RuntimeException When the cursor was not created with `count: true`.
     */
    public function count() : int
    {
        if ( $this->totalCount === null )
        {
            throw new RuntimeException
            (
                'Cursor::count() is only available when the query was created with the `count: true` option.'
            ) ;
        }
        return $this->totalCount ;
    }

    /**
     * Depletes the cursor by applying `$callback` to each remaining row
     * and concatenating the results into a single flat array.
     *
     * The callback receives `(mixed $row, int $index, self $cursor)`
     * and may return either a single value (appended to the result) or
     * an array (spread one level deep into the result). Mirrors
     * `Array.prototype.flatMap()` semantics.
     *
     * Use this to combine a `map()` and a one-level `array_merge()` in
     * one streaming pass — the alternative
     * (`array_merge(...iterator_to_array($cursor->map(...)))`) requires
     * materialising the whole cursor first.
     *
     * @param callable(mixed, int, self): (mixed|array<int, mixed>) $callback Per-row transformation.
     *
     * @return array<int, mixed> Flattened result.
     *
     * @throws ArangoException When fetching a subsequent batch fails.
     */
    public function flatMap( callable $callback ) : array
    {
        $result = [] ;
        $index  = 0 ;

        foreach ( $this as $row )
        {
            $value = $callback( $row , $index++ , $this ) ;

            if ( is_array( $value ) )
            {
                foreach ( $value as $item )
                {
                    $result[] = $item ;
                }
            }
            else
            {
                $result[] = $value ;
            }
        }

        return $result ;
    }

    /**
     * Depletes the cursor by applying `$callback` to each remaining row.
     *
     * The callback receives `(mixed $row, int $index, self $cursor)`.
     * Return `false` from the callback to abort iteration early —
     * `forEach()` then returns `false` to signal a short-circuit;
     * otherwise it returns `true` once the cursor is empty.
     *
     * @param callable(mixed, int, self): (bool|null|void) $callback Per-row callback.
     *
     * @return bool `true` when the cursor was fully consumed, `false` when the callback returned `false` and aborted iteration.
     *
     * @throws ArangoException When fetching a subsequent batch fails.
     */
    public function forEach( callable $callback ) : bool
    {
        $index = 0 ;

        foreach ( $this as $row )
        {
            if ( $callback( $row , $index++ , $this ) === false )
            {
                return false ;
            }
        }

        return true ;
    }

    /**
     * Returns the extra metadata sent by the server with the most recent
     * batch (warnings, stats, profile, …).
     *
     * @return array<string, mixed>
     */
    public function getExtra() : array
    {
        return $this->extra ;
    }

    /**
     * Returns the total number of result rows that would have been
     * returned had the query been executed without a LIMIT clause.
     *
     * Available only when the request was issued with the `fullCount`
     * option set to `true` — returns `0` otherwise.
     *
     * @return int
     */
    public function getFullCount() : int
    {
        return (int) ( $this->extra[ CursorField::STATS ][ CursorField::FULL_COUNT ] ?? 0 ) ;
    }

    /**
     * Returns the server-side cursor identifier, or null when no further
     * batch can be fetched.
     *
     * @return string|null
     */
    public function getId() : ?string
    {
        return $this->cursorId ;
    }

    /**
     * Lazy generator: yields each result row exactly once, fetching
     * subsequent batches from the server as needed.
     *
     * @return Generator<int, mixed>
     *
     * @throws ArangoException When fetching a subsequent batch fails.
     */
    public function getIterator() : Generator
    {
        while ( true )
        {
            foreach ( $this->batch as $row )
            {
                yield $row ;
            }
            if ( !$this->hasMore || $this->cursorId === null )
            {
                break ;
            }
            $this->fetchNextBatch() ;
        }
    }

    /**
     * Returns true when more batches remain to be fetched from the server.
     *
     * @return bool
     */
    public function hasMore() : bool
    {
        return $this->hasMore ;
    }

    /**
     * Lazily transforms each row through `$callback` and yields the
     * results one at a time.
     *
     * The callback receives `(mixed $row, int $index, self $cursor)`
     * and returns the transformed value. The returned {@see Generator}
     * is lazy: nothing is computed until the caller starts iterating,
     * and batches are pulled from the server only on demand.
     *
     * Use this to build a streaming pipeline without buffering the
     * whole cursor in memory. For an eager transformation, materialise
     * the generator with `iterator_to_array(...)` — or use
     * {@see flatMap()} when each row produces multiple output values.
     *
     * Example:
     * ```php
     * foreach ( $cursor->map( static fn( $row ) => $row[ 'name' ] ) as $name )
     * {
     *     echo $name ;
     * }
     * ```
     *
     * @param callable(mixed, int, self): mixed $callback Per-row transformation.
     *
     * @return Generator<int, mixed>
     *
     * @throws ArangoException When fetching a subsequent batch fails.
     */
    public function map( callable $callback ) : Generator
    {
        $index = 0 ;

        foreach ( $this as $row )
        {
            yield $callback( $row , $index++ , $this ) ;
        }
    }

    /**
     * Depletes the cursor by folding it through `$reducer`, returning
     * the accumulated value.
     *
     * The reducer receives `(mixed $accumulator, mixed $row, int $index, self $cursor)`
     * and returns the new accumulator value. The initial accumulator
     * defaults to `null` — pass it explicitly when the reduction starts
     * from a typed value (`0`, `''`, `[]`, …).
     *
     * @param callable(mixed, mixed, int, self): mixed $reducer Folding function.
     * @param mixed                                    $initial Initial accumulator value.
     *
     * @return mixed Final accumulator value (equals `$initial` when the cursor was empty).
     *
     * @throws ArangoException When fetching a subsequent batch fails.
     */
    public function reduce( callable $reducer , mixed $initial = null ) : mixed
    {
        $accumulator = $initial ;
        $index       = 0 ;

        foreach ( $this as $row )
        {
            $accumulator = $reducer( $accumulator , $row , $index++ , $this ) ;
        }

        return $accumulator ;
    }

    /**
     * Fetches the next batch from the server and updates the internal state.
     *
     * @throws ArangoException When the request fails.
     */
    private function fetchNextBatch() : void
    {
        $response = $this->database->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::CURSOR . '/' . rawurlencode( $this->cursorId ) ,
        ) ;

        $body = is_array( $response->body ) ? $response->body : [] ;

        $this->batch   = is_array( $body[ CursorField::RESULT ] ?? null ) ? $body[ CursorField::RESULT ] : [] ;
        $this->hasMore = (bool) ( $body[ CursorField::HAS_MORE ] ?? false ) ;

        if ( isset( $body[ CursorField::EXTRA ] ) && is_array( $body[ CursorField::EXTRA ] ) )
        {
            $this->extra = $body[ CursorField::EXTRA ] ;
        }

        // The server stops returning the cursor id once hasMore becomes false.
        if ( !$this->hasMore )
        {
            $this->cursorId = null ;
        }
    }
}
