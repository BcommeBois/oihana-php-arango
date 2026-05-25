<?php

namespace oihana\arango\clients\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Single source of truth for the ArangoDB HTTP route prefixes consumed by the {@see \oihana\arango\clients} surface.
 *
 * Each constant carries the path of an upstream endpoint, without the
 * `/_db/{name}` database scope that the transport prepends — that scope
 * is the transport's responsibility, not the call-site's.
 *
 * Three families coexist:
 * - **`/_api/*`** — the bulk of CRUD-style routes (collections, documents,
 *   indexes, cursor, …),
 * - **`/_admin/*`** — server diagnostics & management endpoints (auth-protected),
 * - **`/_open/*`** — unauthenticated routes (today only `/_open/auth`).
 *
 * The database scope prefix (`/_db/`) is intentionally not exposed here
 * because it is *not* a route — it is an URL-assembly artefact handled
 * by {@see \oihana\arango\clients\http\HttpTransport::buildUrl()}.
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/
 *
 * @package oihana\arango\clients\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ArangoRoute
{
    use ConstantsTrait ;

    /**
     * Server availability probe — `GET /_admin/server/availability`. Returns
     * the server mode (`default` / `readonly`) on a 2xx response, or a 503
     * status code when the server is shutting down or in maintenance mode.
     */
    public const string ADMIN_AVAILABILITY = '/_admin/server/availability' ;

    /**
     * Server wall-clock endpoint — `GET /_admin/time`.
     */
    public const string ADMIN_TIME = '/_admin/time' ;

    /**
     * ArangoSearch analyzer endpoint — `POST/GET /_api/analyzer`
     * for the analyzer collection itself, `GET/DELETE
     * /_api/analyzer/{name}` for a specific analyzer.
     */
    public const string ANALYZER = '/_api/analyzer' ;

    /**
     * Collection management endpoint — `POST/GET/PUT/DELETE /_api/collection`.
     *
     * Sub-routes (`/count`, `/properties`, `/rename`, `/truncate`) are
     * exposed by {@see \oihana\arango\clients\collection\enums\CollectionRoute}.
     */
    public const string COLLECTION = '/_api/collection' ;

    /**
     * Cursor lifecycle endpoint — `POST/PUT/DELETE /_api/cursor`. Used by
     * {@see \oihana\arango\clients\Database::query()} (POST to open) and
     * by {@see \oihana\arango\clients\cursor\Cursor} (PUT to fetch the
     * next batch, DELETE to dispose).
     */
    public const string CURSOR = '/_api/cursor' ;

    /**
     * Database management endpoint — `GET/POST/DELETE /_api/database`.
     */
    public const string DATABASE = '/_api/database' ;

    /**
     * Document CRUD endpoint — `POST/GET/PATCH/PUT/DELETE /_api/document`.
     * Bulk variants of the write operations operate on the same path
     * with an array body.
     */
    public const string DOCUMENT = '/_api/document' ;

    /**
     * Named graphs (gharial) endpoint — `POST/GET /_api/gharial` for the
     * graph collection itself, `GET/PUT/DELETE /_api/gharial/{name}` for a
     * specific graph, and `.../vertex` / `.../edge` sub-routes for
     * vertex collections and edge definitions management.
     */
    public const string GHARIAL = '/_api/gharial' ;

    /**
     * AQL query plan endpoint — `POST /_api/explain`. Returns the
     * execution plan the optimizer would use without running the
     * query.
     */
    public const string EXPLAIN = '/_api/explain' ;

    /**
     * Bulk import fast path — `POST /_api/import`. Streams JSON Lines
     * (or CSV) into the storage engine, ~100× faster than the per-document
     * route for large batches.
     */
    public const string IMPORT = '/_api/import' ;

    /**
     * Secondary index management endpoint — `POST/GET/DELETE /_api/index`.
     */
    public const string INDEX = '/_api/index' ;

    /**
     * Unauthenticated authentication endpoint — `POST /_open/auth`. Trades
     * a `{username, password}` payload for a JWT.
     */
    public const string OPEN_AUTH = '/_open/auth' ;

    /**
     * AQL parser endpoint — `POST /_api/query`. Validates the query
     * string and returns its AST + the list of collections it references,
     * without executing it.
     */
    public const string QUERY = '/_api/query' ;

    /**
     * Streaming-transaction lifecycle endpoint —
     * `POST /_api/transaction/begin` to start, `GET|PUT|DELETE
     * /_api/transaction/{id}` to status / commit / abort, and
     * `GET /_api/transaction` to list active transactions.
     */
    public const string TRANSACTION = '/_api/transaction' ;

    /**
     * Server version endpoint — `GET /_api/version`.
     */
    public const string VERSION = '/_api/version' ;

    /**
     * ArangoSearch view endpoint — `POST/GET /_api/view` for the
     * view collection itself, `GET/DELETE /_api/view/{name}` for a
     * specific view, `GET/PATCH/PUT /_api/view/{name}/properties`
     * for the per-view configuration.
     */
    public const string VIEW = '/_api/view' ;
}
