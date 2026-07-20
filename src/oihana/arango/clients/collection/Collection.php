<?php

namespace oihana\arango\clients\collection ;

use InvalidArgumentException ;
use JsonException ;

use oihana\enums\http\HttpHeader ;
use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\collection\enums\CollectionField ;
use oihana\arango\clients\collection\enums\CollectionRoute ;
use oihana\arango\clients\collection\enums\CollectionType ;
use oihana\arango\clients\collection\indexes\IndexDefinition ;
use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\clients\document\Document ;
use oihana\arango\clients\document\enums\DocumentField ;
use oihana\arango\clients\enums\ArangoRoute ;
use oihana\arango\clients\exceptions\ArangoException ;

use function oihana\arango\clients\helpers\mergeWrittenPayload ;
use function oihana\arango\clients\helpers\stringifyOptions ;

/**
 * Operations scoped to a single ArangoDB collection.
 *
 * Instances are obtained through {@see Database::collection()} and share
 * the parent database's HTTP transport. The collection name is fixed at
 * construction time and is automatically interpolated into the
 * `/_api/document/{name}` and `/_api/collection/{name}` routes.
 *
 * Every write method (`insert()`, `update()`, `replace()`, `remove()`)
 * returns a {@see Document} built from the server response. By default
 * that document only carries the reserved `_key` / `_id` / `_rev`
 * attributes — pass `returnNew: true` (or `returnOld: true` on
 * `remove()`) in the options to receive the full payload, which is then
 * merged into the resulting `Document`.
 *
 * Example:
 * ```php
 * $users = $db->collection( 'users' ) ;
 *
 * $marc = $users->insert( [ 'name' => 'Marc' ] , [ 'returnNew' => true ] ) ;
 * echo $marc->getKey() ;
 * echo $marc->get( 'name' ) ; // 'Marc' (because of returnNew)
 *
 * if ( $users->documentExists( $marc->getKey() ) )
 * {
 *     $users->update( $marc->getKey() , [ 'role' => 'admin' ] ) ;
 * }
 * ```
 *
 * @package oihana\arango\clients\collection
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class Collection
{
    /**
     * @param Database $database Parent database (provides the shared HTTP transport).
     * @param string   $name     Name of the target collection on the server.
     *
     * @final Subclasses (e.g. {@see EdgeCollection}) inherit this signature unchanged,
     *        so {@see self::rename()} can safely return `new static( $database, $name )`.
     */
    public function __construct( public Database $database , public string $name ) {}

    /**
     * Query parameter accepted by `/_api/index` and `/_api/document` to
     * scope a request to a specific collection.
     */
    private const string COLLECTION_QUERY_PARAM = 'collection' ;

    /**
     * Content-Type sent on `/_api/import` requests. JSON Lines (one
     * JSON document per line) is the standard wire format for this
     * endpoint.
     */
    private const string IMPORT_CONTENT_TYPE = 'application/x-ldjson' ;

    /**
     * Value sent for the `type` query parameter of `/_api/import` when
     * the payload is a JSON Lines stream of full documents (one JSON
     * object per line). The legacy `array` / `auto` formats are not
     * exposed by this client.
     */
    private const string IMPORT_TYPE_DOCUMENTS = 'documents' ;

    /**
     * Name of the `type` query parameter on `/_api/import`.
     */
    private const string IMPORT_TYPE_QUERY_PARAM = 'type' ;

    /**
     * Returns a {@see Cursor} iterating over every document of the
     * collection.
     *
     * Backed by a plain `FOR doc IN @@col RETURN doc` AQL query (the
     * `/_api/simple/all` endpoint was deprecated in ArangoDB 3.x).
     * Pagination is applied through optional `LIMIT` clauses — only
     * non-zero values are emitted, so the default behaviour fetches
     * the whole collection in lazy batches.
     *
     * Equivalent to `byExample([], $limit, $offset)`.
     *
     * @param int $limit  Maximum number of documents to return (`0` = no LIMIT).
     * @param int $offset Number of documents to skip before returning results (`0` = no offset). Named after the AQL native `LIMIT offset, count` syntax.
     *
     * @return Cursor
     *
     * @throws ArangoException When the request fails.
     */
    public function all( int $limit = 0 , int $offset = 0 ) : Cursor
    {
        return $this->byExample( [] , $limit , $offset ) ;
    }

    /**
     * Returns a {@see Cursor} iterating over documents that match every
     * key/value pair of `$example` (equality match — for richer
     * predicates, drop down to {@see Database::query()} and write the
     * AQL by hand).
     *
     * Backed by a `FOR doc IN @@col [FILTER doc.k1 == @v1 AND …] RETURN doc`
     * AQL query (the `/_api/simple/by-example` endpoint was deprecated
     * in ArangoDB 3.x).
     *
     * To avoid AQL injection through example keys, each key is
     * validated against `/^[a-zA-Z_][a-zA-Z0-9_.]*$/` — simple
     * top-level attributes and dotted paths (`address.city`) are
     * supported, anything else throws an
     * {@see InvalidArgumentException}. Example **values** are always
     * passed as bind variables and never inlined.
     *
     * @param array<string, mixed> $example Equality predicate (empty array = no FILTER).
     * @param int                  $limit   Maximum number of documents to return (`0` = no LIMIT).
     * @param int                  $offset  Number of documents to skip before returning results (`0` = no offset). Named after the AQL native `LIMIT offset, count` syntax.
     *
     * @return Cursor
     *
     * @throws InvalidArgumentException When an example key is not a simple attribute or dotted path.
     * @throws ArangoException          When the request fails.
     */
    public function byExample( array $example , int $limit = 0 , int $offset = 0 ) : Cursor
    {
        $bindVars = [ '@col' => $this->name ] ;
        $filters  = [] ;
        $i        = 0 ;

        foreach ( $example as $key => $value )
        {
            if ( !is_string( $key ) || !preg_match( '/^[a-zA-Z_][a-zA-Z0-9_.]*$/' , $key ) )
            {
                throw new InvalidArgumentException
                (
                    'Collection::byExample() only accepts simple attribute names or dotted paths as keys; got: ' . var_export( $key , true ) ,
                ) ;
            }
            $param              = 'v' . $i++ ;
            $filters[]          = 'doc.' . $key . ' == @' . $param ;
            $bindVars[ $param ] = $value ;
        }

        $clauses = [ 'FOR doc IN @@col' ] ;

        if ( !empty( $filters ) )
        {
            $clauses[] = 'FILTER ' . implode( ' AND ' , $filters ) ;
        }

        if ( $limit > 0 )
        {
            if ( $offset > 0 )
            {
                $clauses[]            = 'LIMIT @offset, @limit' ;
                $bindVars[ 'offset' ] = $offset ;
            }
            else
            {
                $clauses[] = 'LIMIT @limit' ;
            }

            $bindVars[ 'limit' ] = $limit ;
        }

        $clauses[] = 'RETURN doc' ;

        return $this->database->query( implode( ' ' , $clauses ) , $bindVars ) ;
    }

    /**
     * Returns the current number of documents in the collection.
     *
     * @return int
     *
     * @throws ArangoException When the request fails.
     */
    public function count() : int
    {
        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->collectionPath( CollectionRoute::COUNT ) ,
        ) ;

        $body = is_array( $response->body ) ? $response->body : [] ;

        return (int) ( $body[ CollectionField::COUNT ] ?? 0 ) ;
    }

    /**
     * Creates this collection on the server.
     *
     * The collection name is taken from {@see $name}; the type defaults
     * to {@see CollectionType::DOCUMENT} when the caller does not set
     * it explicitly through `$options`. Subclasses targeting another
     * collection type (e.g. {@see EdgeCollection}) override this method
     * to substitute a different default.
     *
     * Any extra option is forwarded as-is to the server's
     * `POST /_api/collection` endpoint (`waitForSync`, `keyOptions`,
     * `numberOfShards`, `replicationFactor`, `writeConcern`,
     * `cacheEnabled`, …).
     *
     * @param array<string, mixed> $options Extra creation options.
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function create( array $options = [] ) : void
    {
        $options[ CollectionField::TYPE ] = $options[ CollectionField::TYPE ] ?? CollectionType::DOCUMENT ;
        $options[ CollectionField::NAME ] = $this->name ;

        $this->database->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::COLLECTION ,
            body   : $options ,
        ) ;
    }

    /**
     * Creates a secondary index on this collection.
     *
     * @param IndexDefinition $definition Index definition built from one of the {@see indexes} value objects (PersistentIndex, GeoIndex, TtlIndex, FulltextIndex, …).
     *
     * @return array<string, mixed> Raw server response (carries the assigned `id`, the resolved type, the indexed fields, …).
     *
     * @throws ArangoException When the request fails.
     */
    public function createIndex( IndexDefinition $definition ) : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::INDEX ,
            body   : $definition->toArray() ,
            query  : [ self::COLLECTION_QUERY_PARAM => $this->name ] ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Fetches a single document by key.
     *
     * @param string $key The document key (`_key`).
     *
     * @return Document
     *
     * @throws ArangoException When the document is missing or the request fails.
     */
    public function document( string $key ) : Document
    {
        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->documentPath( $key ) ,
        ) ;

        return new Document( is_array( $response->body ) ? $response->body : [] ) ;
    }

    /**
     * Returns true when a document with the given key exists in this collection.
     *
     * Uses an HTTP HEAD request (no body) so the round-trip stays cheap.
     * Any non-404 error is rethrown as an {@see ArangoException}.
     *
     * @param string $key The document key.
     *
     * @return bool
     *
     * @throws ArangoException When the request fails for a reason other than a 404 response.
     */
    public function documentExists( string $key ) : bool
    {
        try
        {
            $this->database->request
            (
                method : HttpMethod::HEAD ,
                path   : $this->documentPath( $key ) ,
            ) ;
            return true ;
        }
        catch ( ArangoException $e )
        {
            if ( $e->getCode() === 404 )
            {
                return false ;
            }
            throw $e ;
        }
    }

    /**
     * Drops this collection from the server.
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function drop() : void
    {
        $this->database->request
        (
            method : HttpMethod::DELETE ,
            path   : $this->collectionPath() ,
        ) ;
    }

    /**
     * Drops an index from this collection.
     *
     * Accepts either a full server-side handle (`users/12345` or
     * `users/idx_email_unique`) or just the key / name part (`12345`,
     * `idx_email_unique`) — the collection name is prefixed
     * automatically when missing.
     *
     * @param string $idOrName Full handle or key/name of the index to drop.
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function dropIndex( string $idOrName ) : void
    {
        if ( !str_contains( $idOrName , '/' ) )
        {
            $idOrName = $this->name . '/' . $idOrName ;
        }

        [ $collectionPart , $keyPart ] = explode( '/' , $idOrName , 2 ) ;

        $this->database->request
        (
            method : HttpMethod::DELETE ,
            path   : ArangoRoute::INDEX . '/' . rawurlencode( $collectionPart ) . '/' . rawurlencode( $keyPart ) ,
        ) ;
    }

    /**
     * Returns true when this collection exists on the server.
     *
     * Issues `GET /_api/collection/{name}` and treats a 404 as a clean
     * "missing" — any other failure is rethrown as an
     * {@see ArangoException}.
     *
     * @return bool
     *
     * @throws ArangoException When the request fails for a reason other than a 404 response.
     */
    public function exists() : bool
    {
        try
        {
            $this->database->request
            (
                method : HttpMethod::GET ,
                path   : $this->collectionPath() ,
            ) ;
            return true ;
        }
        catch ( ArangoException $e )
        {
            if ( $e->getCode() === 404 )
            {
                return false ;
            }
            throw $e ;
        }
    }

    /**
     * Returns the first document matching every key/value pair of
     * `$example` (equality match), or `null` when no document matches.
     *
     * Shortcut over {@see byExample()} that materialises the first row
     * of the result cursor into a {@see Document}. The key validation
     * rules from `byExample()` apply.
     *
     * @param array<string, mixed> $example Equality predicate.
     *
     * @return Document|null
     *
     * @throws InvalidArgumentException When an example key is not a simple attribute or dotted path.
     * @throws ArangoException          When the request fails.
     */
    public function firstExample( array $example ) : ?Document
    {
        $cursor = $this->byExample( $example , 1 ) ;

        foreach ( $cursor as $row )
        {
            return new Document( is_array( $row ) ? $row : (array) $row ) ;
        }

        return null ;
    }

    /**
     * Returns the collection name this instance is bound to.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name ;
    }

    /**
     * Bulk-imports an array of documents through the dedicated
     * `POST /_api/import` endpoint.
     *
     * Around 100× faster than {@see saveAll()} on large batches because
     * the server skips the per-document parser / response builder and
     * streams the input directly into the storage engine. The trade-off
     * is that partial failures do not surface as per-row {@see Document}
     * instances — the server returns aggregated counters
     * (`created` / `errors` / `empty` / `updated` / `ignored`), exposed
     * here as an {@see ImportResult}.
     *
     * Each entry of `$documents` must be an associative array; objects
     * are not accepted at this layer (call `->toArray()` on the
     * caller's side, or use {@see saveAll()} which returns typed
     * {@see Document} instances).
     *
     * Server-side options are forwarded as query parameters (booleans
     * are stringified to the spelling Arango expects). Recognised keys
     * include `overwrite` (truncate the target before importing),
     * `waitForSync`, `complete` (abort on first error), `details`
     * (populate {@see ImportResult::$details}), `onDuplicate` (one of
     * the {@see enums\OnDuplicate} constants), `fromPrefix`, `toPrefix`.
     *
     * Example:
     * ```php
     * $result = $users->import
     * (
     *     [
     *         [ '_key' => 'alice' , 'name' => 'Alice' ] ,
     *         [ '_key' => 'bob'   , 'name' => 'Bob'   ] ,
     *     ] ,
     *     [
     *         'waitForSync' => true ,
     *         'onDuplicate' => OnDuplicate::UPDATE ,
     *         'details'     => true ,
     *     ] ,
     * ) ;
     *
     * if ( $result->hasErrors() )
     * {
     *     foreach ( $result->details as $message ) { error_log( $message ) ; }
     * }
     * ```
     *
     * @param array<int, array<string, mixed>> $documents Documents to import.
     * @param array<string, mixed>             $options   Server-side options (`overwrite`, `waitForSync`, `complete`, `details`, `onDuplicate`, `fromPrefix`, `toPrefix`, …).
     *
     * @return ImportResult
     *
     * @throws InvalidArgumentException When an entry of `$documents` is not an associative array.
     * @throws JsonException            When an entry contains a value that cannot be encoded as JSON.
     * @throws ArangoException          When the request itself fails (network, 4xx/5xx on the whole batch).
     */
    public function import( array $documents , array $options = [] ) : ImportResult
    {
        $lines = [] ;

        foreach ( $documents as $index => $document )
        {
            if ( !is_array( $document ) )
            {
                throw new InvalidArgumentException
                (
                    'Collection::import() only accepts an array of associative arrays; entry #' . $index . ' is ' . get_debug_type( $document ) . '.' ,
                ) ;
            }

            $lines[] = json_encode( $document , JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ;
        }

        $body = $lines === [] ? '' : implode( "\r\n" , $lines ) . "\r\n" ;

        $query = array_merge
        (
            stringifyOptions( $options ) ,
            [
                self::COLLECTION_QUERY_PARAM    => $this->name ,
                self::IMPORT_TYPE_QUERY_PARAM   => self::IMPORT_TYPE_DOCUMENTS ,
            ] ,
        ) ;

        $response = $this->database->request
        (
            method  : HttpMethod::POST ,
            path    : ArangoRoute::IMPORT ,
            body    : $body ,
            query   : $query ,
            headers : [ HttpHeader::CONTENT_TYPE => self::IMPORT_CONTENT_TYPE ] ,
        ) ;

        return ImportResult::fromBody( is_array( $response->body ) ? $response->body : [] ) ;
    }

    /**
     * Returns the server-side metadata of a single index.
     *
     * Accepts either a full server-side handle (`users/12345` or
     * `users/idx_email`) or just the key / name part (`12345`,
     * `idx_email`) — the collection name is prefixed automatically
     * when missing.
     *
     * @param string $idOrName Full handle or key/name of the index.
     *
     * @return array<string, mixed> Raw server response (`id`, `type`, `fields`, …).
     *
     * @throws ArangoException When the request fails (including when the index does not exist).
     */
    public function index( string $idOrName ) : array
    {
        if ( !str_contains( $idOrName , '/' ) )
        {
            $idOrName = $this->name . '/' . $idOrName ;
        }

        [ $collectionPart , $keyPart ] = explode( '/' , $idOrName , 2 ) ;

        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : ArangoRoute::INDEX . '/' . rawurlencode( $collectionPart ) . '/' . rawurlencode( $keyPart ) ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Returns the list of indexes defined on this collection.
     *
     * Each entry is the raw server-side metadata (id, type, fields, …);
     * `IndexField` constants can be used to read its keys safely.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException When the request fails.
     */
    public function indexes() : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : ArangoRoute::INDEX ,
            query  : [ self::COLLECTION_QUERY_PARAM => $this->name ] ,
        ) ;

        $body = is_array( $response->body ) ? $response->body : [] ;

        return is_array( $body[ IndexField::INDEXES ] ?? null ) ? $body[ IndexField::INDEXES ] : [] ;
    }

    /**
     * Inserts a new document into the collection.
     *
     * The returned {@see Document} carries the server-assigned
     * `_key` / `_id` / `_rev`; pass `returnNew: true` in `$options` to
     * receive the full inserted payload merged into the result.
     *
     * @param array<string, mixed> $data    Document payload.
     * @param array<string, mixed> $options Server-side options (`returnNew`, `waitForSync`, `overwriteMode`, …).
     *
     * @return Document
     *
     * @throws ArangoException When the request fails.
     */
    public function insert( array $data , array $options = [] ) : Document
    {
        $response = $this->database->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::DOCUMENT . '/' . rawurlencode( $this->name ) ,
            body   : $data ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWritten( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Returns the full server-side metadata of this collection
     * (`GET /_api/collection/{name}/properties`).
     *
     * The response is returned as a raw associative array — fields
     * include the canonical {@see CollectionField} entries (`name`,
     * `isSystem`, `type`, …) plus the type-specific details (`keyOptions`,
     * `numberOfShards`, `replicationFactor`, `writeConcern`,
     * `cacheEnabled`, `globallyUniqueId`, `id`, `status`, …).
     *
     * @return array<string, mixed>
     *
     * @throws ArangoException When the request fails.
     */
    public function properties() : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::GET ,
            path   : $this->collectionPath( CollectionRoute::PROPERTIES ) ,
        ) ;

        return is_array( $response->body ) ? $response->body : [] ;
    }

    /**
     * Removes a document by key.
     *
     * The returned {@see Document} carries the meta returned by the
     * server (`_key` / `_id` / `_rev`); pass `returnOld: true` in
     * `$options` to receive the deleted payload merged into the result.
     *
     * @param string               $key     Document key.
     * @param array<string, mixed> $options Server-side options (`returnOld`, `waitForSync`, …).
     *
     * @return Document
     *
     * @throws ArangoException When the request fails.
     */
    public function remove( string $key , array $options = [] ) : Document
    {
        $response = $this->database->request
        (
            method : HttpMethod::DELETE ,
            path   : $this->documentPath( $key ) ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWritten( $response->body , DocumentField::OLD ) ;
    }

    /**
     * Removes multiple documents from the collection in a single round-trip.
     *
     * `$selectors` accepts either raw keys (strings) or objects carrying
     * `_key` / `_id` — both forms are forwarded as-is to ArangoDB in the
     * request body of `DELETE /_api/document/{collection}`.
     *
     * The server processes the array entry-by-entry: each entry produces
     * an entry in the response array, either the deleted meta
     * (`_key` / `_id` / `_rev` plus optional `old` payload when
     * `returnOld: true`) or an error object (`error: true`,
     * `errorNum`, `errorMessage`). Partial failures do not abort the
     * batch — the caller inspects each returned {@see Document} to
     * decide what to do.
     *
     * @param array<int, string|array<string, mixed>> $selectors Document keys or `{_key, …}` / `{_id, …}` objects.
     * @param array<string, mixed>                    $options   Server-side options (`returnOld`, `waitForSync`, `silent`, `ignoreRevs`, `refillIndexCaches`, …).
     *
     * @return array<int, Document> One entry per input selector, same order as the input.
     *
     * @throws ArangoException When the request itself fails (network, 4xx/5xx on the whole batch).
     */
    public function removeAll( array $selectors , array $options = [] ) : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::DELETE ,
            path   : ArangoRoute::DOCUMENT . '/' . rawurlencode( $this->name ) ,
            body   : $selectors ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWrittenBatch( $response->body , DocumentField::OLD ) ;
    }

    /**
     * Renames the collection on the server and returns a NEW instance of
     * the same class (`static`) bound to the new name. The current
     * instance keeps pointing at the old name and should be discarded
     * once `rename()` returns.
     *
     * Cluster note: ArangoDB rejects rename operations on cluster
     * deployments (only single-server setups support it). The error is
     * surfaced as an {@see ArangoException}.
     *
     * @param string $newName New collection name.
     *
     * @return static New instance bound to `$newName`.
     *
     * @throws ArangoException When the request fails.
     */
    public function rename( string $newName ) : static
    {
        $this->database->request
        (
            method : HttpMethod::PUT ,
            path   : $this->collectionPath( CollectionRoute::RENAME ) ,
            body   : [ CollectionField::NAME => $newName ] ,
        ) ;

        return new static( $this->database , $newName ) ;
    }

    /**
     * Replaces an existing document with the given payload (PUT semantics).
     *
     * @param string               $key     Document key.
     * @param array<string, mixed> $data    Replacement payload.
     * @param array<string, mixed> $options Server-side options (`returnNew`, `returnOld`, `ignoreRevs`, `waitForSync`, …).
     *
     * @return Document
     *
     * @throws ArangoException When the request fails.
     */
    public function replace( string $key , array $data , array $options = [] ) : Document
    {
        $response = $this->database->request
        (
            method : HttpMethod::PUT ,
            path   : $this->documentPath( $key ) ,
            body   : $data ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWritten( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Replaces multiple documents in a single round-trip.
     *
     * Each entry of `$documents` must carry the `_key` (or `_id`) of
     * the target document; the rest of the payload becomes the new
     * content of that document (PUT semantics — fields absent from
     * the payload are dropped).
     *
     * The server processes the array entry-by-entry: each entry
     * produces an entry in the response array, either the meta
     * (`_key` / `_id` / `_rev` + optional `new` / `old`) or an error
     * object (`error: true`, `errorNum`, `errorMessage`). Partial
     * failures do not abort the batch.
     *
     * @param array<int, array<string, mixed>> $documents Replacement payloads (each MUST include `_key` or `_id`).
     * @param array<string, mixed>             $options   Server-side options (`returnNew`, `returnOld`, `ignoreRevs`, `silent`, `waitForSync`, …).
     *
     * @return array<int, Document> One entry per input document, same order as the input.
     *
     * @throws ArangoException When the request itself fails (network, 4xx/5xx on the whole batch).
     */
    public function replaceAll( array $documents , array $options = [] ) : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::PUT ,
            path   : ArangoRoute::DOCUMENT . '/' . rawurlencode( $this->name ) ,
            body   : $documents ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWrittenBatch( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Inserts multiple documents in a single round-trip.
     *
     * Each entry of `$documents` is treated as a brand-new document
     * (POST semantics). The server processes the array entry-by-entry:
     * each entry produces an entry in the response array, either the
     * inserted meta (`_key` / `_id` / `_rev` + optional `new`) or an
     * error object (`error: true`, `errorNum`, `errorMessage`).
     * Partial failures do not abort the batch.
     *
     * @param array<int, array<string, mixed>> $documents Documents to insert.
     * @param array<string, mixed>             $options   Server-side options (`returnNew`, `overwriteMode`, `keepNull`, `mergeObjects`, `silent`, `waitForSync`, …).
     *
     * @return array<int, Document> One entry per input document, same order as the input.
     *
     * @throws ArangoException When the request itself fails (network, 4xx/5xx on the whole batch).
     */
    public function saveAll( array $documents , array $options = [] ) : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::POST ,
            path   : ArangoRoute::DOCUMENT . '/' . rawurlencode( $this->name ) ,
            body   : $documents ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWrittenBatch( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Truncates the collection (removes every document, keeps the collection itself).
     *
     * @return void
     *
     * @throws ArangoException When the request fails.
     */
    public function truncate() : void
    {
        $this->database->request
        (
            method : HttpMethod::PUT ,
            path   : $this->collectionPath( CollectionRoute::TRUNCATE ) ,
        ) ;
    }

    /**
     * Partially updates an existing document with the given partial payload
     * (PATCH semantics — only the supplied fields are touched).
     *
     * @param string               $key     Document key.
     * @param array<string, mixed> $partial Partial payload.
     * @param array<string, mixed> $options Server-side options (`returnNew`, `returnOld`, `keepNull`, `mergeObjects`, …).
     *
     * @return Document
     *
     * @throws ArangoException When the request fails.
     */
    public function update( string $key , array $partial , array $options = [] ) : Document
    {
        $response = $this->database->request
        (
            method : HttpMethod::PATCH ,
            path   : $this->documentPath( $key ) ,
            body   : $partial ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWritten( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Partially updates multiple documents in a single round-trip
     * (PATCH semantics — only the supplied fields are touched on each
     * document).
     *
     * Each entry of `$patches` must carry the `_key` (or `_id`) of the
     * target document; the rest of the payload is merged into that
     * document on the server.
     *
     * The server processes the array entry-by-entry: each entry
     * produces an entry in the response array, either the meta
     * (`_key` / `_id` / `_rev` + optional `new` / `old`) or an error
     * object (`error: true`, `errorNum`, `errorMessage`). Partial
     * failures do not abort the batch.
     *
     * @param array<int, array<string, mixed>> $patches Patch payloads (each MUST include `_key` or `_id`).
     * @param array<string, mixed>             $options Server-side options (`returnNew`, `returnOld`, `keepNull`, `mergeObjects`, `ignoreRevs`, `silent`, `waitForSync`, …).
     *
     * @return array<int, Document> One entry per input patch, same order as the input.
     *
     * @throws ArangoException When the request itself fails (network, 4xx/5xx on the whole batch).
     */
    public function updateAll( array $patches , array $options = [] ) : array
    {
        $response = $this->database->request
        (
            method : HttpMethod::PATCH ,
            path   : ArangoRoute::DOCUMENT . '/' . rawurlencode( $this->name ) ,
            body   : $patches ,
            query  : stringifyOptions( $options ) ,
        ) ;

        return $this->wrapWrittenBatch( $response->body , DocumentField::NEW ) ;
    }

    /**
     * Builds the `/_api/collection/{name}{suffix}` path with the collection
     * name URL-encoded. `$suffix` is concatenated as-is and is expected to
     * be one of the {@see CollectionRoute} constants (or an empty string
     * to target the collection itself).
     *
     * @param string $suffix Sub-route suffix (typically a {@see CollectionRoute} constant, e.g. `/count`).
     *
     * @return string
     */
    private function collectionPath( string $suffix = '' ) : string
    {
        return ArangoRoute::COLLECTION . '/' . rawurlencode( $this->name ) . $suffix ;
    }

    /**
     * Builds the `/_api/document/{collection}/{key}` path with both segments URL-encoded.
     *
     * @param string $key Document key (`_key`).
     *
     * @return string
     */
    private function documentPath( string $key ) : string
    {
        return ArangoRoute::DOCUMENT . '/' . rawurlencode( $this->name ) . '/' . rawurlencode( $key ) ;
    }

    /**
     * Wraps a batched write-operation response body into an array of
     * {@see Document} instances — one per row of the response, in the
     * order the server emitted them.
     *
     * Each entry of the response can be either a success meta (the
     * usual `_key` / `_id` / `_rev` + optional `new` / `old` payload)
     * or a per-row error object (`error: true`, `errorNum`,
     * `errorMessage`). Both shapes flow through the existing
     * {@see wrapWritten()} so callers can use the typed
     * {@see Document} API to introspect either case (e.g.
     * `$doc->get('error') === true` to detect a failed row).
     *
     * @param mixed  $body         Decoded response body — expected to be a list of entries.
     * @param string $payloadField Key of the optional payload field (`new` for insert/update/replace, `old` for remove).
     *
     * @return array<int, Document>
     */
    private function wrapWrittenBatch( mixed $body , string $payloadField ) : array
    {
        if ( !is_array( $body ) )
        {
            return [] ;
        }

        $documents = [] ;
        foreach ( $body as $entry )
        {
            $documents[] = $this->wrapWritten( $entry , $payloadField ) ;
        }
        return $documents ;
    }

    /**
     * Wraps a write-operation response body into a {@see Document}.
     *
     * When the server returned the optional `new` / `old` payload (because
     * `returnNew` / `returnOld` was set), it is merged into the resulting
     * document data, with the server-assigned reserved attributes
     * (`_key` / `_id` / `_rev`) taking precedence.
     *
     * @param mixed  $body         Decoded response body.
     * @param string $payloadField Key of the optional payload field (`new` for insert/update/replace, `old` for remove).
     *
     * @return Document
     */
    private function wrapWritten( mixed $body , string $payloadField ) : Document
    {
        if ( !is_array( $body ) )
        {
            return new Document() ;
        }

        return new Document( mergeWrittenPayload( $body , $payloadField ) ) ;
    }
}
