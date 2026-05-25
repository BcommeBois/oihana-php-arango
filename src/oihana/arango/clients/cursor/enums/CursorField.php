<?php

namespace oihana\arango\clients\cursor\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Field names exchanged with the ArangoDB server through the AQL cursor
 * API (`/_api/cursor`).
 *
 * Covers both directions:
 * - request body fields sent on `POST /_api/cursor` (`query`, `bindVars`,
 *   plus the cursor options forwarded as-is by the caller — `count`,
 *   `batchSize`, `fullCount`, …),
 * - response body fields returned by the server in the initial response
 *   and in each subsequent batch fetch (`result`, `hasMore`, `id`,
 *   `count`, `extra`).
 *
 * @see https://docs.arangodb.com/stable/aql/how-to-invoke-aql/
 *
 * @package oihana\arango\clients\cursor\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class CursorField
{
    use ConstantsTrait ;

    /**
     * Cursor option — preferred batch size for result paging. Lives at
     * the root of the `POST /_api/cursor` request body.
     */
    public const string BATCH_SIZE = 'batchSize' ;

    /**
     * Map of bind name → value sent on `POST /_api/cursor`.
     *
     * Note: the server expects a JSON object (never an array). When the
     * map is empty, callers should still pass an empty object (cast
     * `(object) []`) so JSON encoding does not produce `[]`.
     */
    public const string BIND_VARS = 'bindVars' ;

    /**
     * Cursor option — enable the per-query plan cache. Lives at the
     * root of the `POST /_api/cursor` request body.
     */
    public const string CACHE = 'cache' ;

    /**
     * Server-side total count of result rows. Returned in the response
     * only when the request set `count: true`.
     */
    public const string COUNT = 'count' ;

    /**
     * Extra response metadata (warnings, stats, profile, …).
     */
    public const string EXTRA = 'extra' ;

    /**
     * Total number of result rows that would have been returned had the
     * query been executed without a LIMIT clause.
     *
     * Carried in two places:
     * - request option (`options.fullCount: true`) — must be set for the
     *   server to compute this value,
     * - response payload, nested under `extra.stats.fullCount`.
     */
    public const string FULL_COUNT = 'fullCount' ;

    /**
     * Flag carried in the response body indicating that more batches
     * remain to be fetched.
     */
    public const string HAS_MORE = 'hasMore' ;

    /**
     * Server-side cursor identifier, present in the response only when
     * more batches remain (i.e. when `hasMore` is true).
     */
    public const string ID = 'id' ;

    /**
     * Cursor option — maximum amount of wall-clock time the server may
     * spend on the query before terminating it (seconds, fractional).
     * Carried under the nested `options.{...}` sub-object on
     * `POST /_api/cursor`.
     */
    public const string MAX_RUNTIME = 'maxRuntime' ;

    /**
     * Cursor option — maximum amount of RAM (in bytes) the query may
     * allocate before being aborted. Lives at the root of the
     * `POST /_api/cursor` request body.
     */
    public const string MEMORY_LIMIT = 'memoryLimit' ;

    /**
     * Raw AQL query string sent on `POST /_api/cursor`.
     */
    public const string QUERY = 'query' ;

    /**
     * Per-batch array of rows returned by the server.
     */
    public const string RESULT = 'result' ;

    /**
     * Nested object on `POST /_api/cursor` carrying the non-root cursor
     * options recognised by the server (`fullCount`, `profile`, `stream`,
     * `maxRuntime`, `failOnWarning`, `optimizer`, …).
     *
     * Distinct from the top-level options (`count`, `batchSize`, `ttl`,
     * `cache`, `memoryLimit`) which live at the body root.
     */
    public const string OPTIONS = 'options' ;

    /**
     * Cursor options that ArangoDB accepts at the **root** of the
     * `POST /_api/cursor` request body. Anything else
     * (`fullCount`, `profile`, `stream`, `maxRuntime`, `failOnWarning`,
     * `optimizer`, …) must be nested under {@see OPTIONS} so the
     * server actually applies it.
     *
     * `QUERY` and `BIND_VARS` are intentionally absent — they are
     * required body fields, not options, and are extracted before any
     * root-vs-nested dispatch happens.
     *
     * @var list<string>
     */
    public const array ROOT_OPTIONS =
    [
        self::COUNT ,
        self::BATCH_SIZE ,
        self::CACHE ,
        self::MEMORY_LIMIT ,
        self::TTL ,
    ] ;

    /**
     * Sub-key of `extra` containing query execution statistics
     * (`fullCount`, `writesExecuted`, `executionTime`, `scannedFull`, …).
     */
    public const string STATS = 'stats' ;

    /**
     * Cursor option — server-side time-to-live of the cursor (in
     * seconds). The cursor is dropped automatically when no batch is
     * pulled within that window. Lives at the root of the
     * `POST /_api/cursor` request body.
     */
    public const string TTL = 'ttl' ;
}
