<?php

namespace oihana\arango\clients\collection ;

use oihana\arango\clients\collection\enums\ImportField ;

/**
 * Immutable summary returned by {@see Collection::import()}.
 *
 * Mirrors the JSON object the server emits on `POST /_api/import`:
 * the four counters that decompose the input batch
 * (`created` + `errors` + `empty` + `updated` + `ignored` add up to
 * the number of source rows), plus an optional `details` list of
 * per-row error messages populated when the request was issued with
 * the `details: true` option.
 *
 * Partial failures do NOT raise — the server processes the batch
 * row-by-row and reports the outcome in the counters. Callers can
 * detect failures with {@see hasErrors()} and inspect the verbatim
 * messages in {@see $details} when relevant.
 *
 * Example:
 * ```php
 * $result = $users->import
 * (
 *     [
 *         [ '_key' => 'alice' , 'name' => 'Alice' ] ,
 *         [ '_key' => 'bob'   , 'name' => 'Bob'   ] ,
 *     ] ,
 *     [ 'waitForSync' => true , 'details' => true ] ,
 * ) ;
 *
 * echo $result->created ; // 2
 *
 * if ( $result->hasErrors() )
 * {
 *     foreach ( $result->details as $message )
 *     {
 *         error_log( $message ) ;
 *     }
 * }
 * ```
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/documents/#create-multiple-documents
 *
 * @package oihana\arango\clients\collection
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class ImportResult
{
    /**
     * @param int                $created Number of documents successfully created.
     * @param int                $errors  Number of documents that failed to be imported.
     * @param int                $empty   Number of empty (or otherwise skipped) source rows.
     * @param int                $updated Number of existing documents updated (only meaningful with `onDuplicate: update|replace`).
     * @param int                $ignored Number of duplicate documents silently ignored (only meaningful with `onDuplicate: ignore`).
     * @param array<int, string> $details Per-row error messages, populated when the request used `details: true`. Empty list otherwise.
     */
    public function __construct
    (
        public int   $created = 0 ,
        public int   $errors  = 0 ,
        public int   $empty   = 0 ,
        public int   $updated = 0 ,
        public int   $ignored = 0 ,
        public array $details = [] ,
    )
    {
    }

    /**
     * Builds an {@see ImportResult} from a server response body.
     *
     * Missing or non-integer counters fall back to `0`; a missing or
     * malformed `details` field falls back to an empty list. Unknown
     * extra fields are ignored.
     *
     * @param array<string, mixed> $body Raw decoded JSON object returned by `POST /_api/import`.
     *
     * @return self
     */
    public static function fromBody( array $body ) : self
    {
        $details    = $body[ ImportField::DETAILS ] ?? null ;
        $detailList = [] ;

        if ( is_array( $details ) )
        {
            foreach ( $details as $message )
            {
                if ( is_string( $message ) )
                {
                    $detailList[] = $message ;
                }
            }
        }

        return new self
        (
            created : (int) ( $body[ ImportField::CREATED ] ?? 0 ) ,
            errors  : (int) ( $body[ ImportField::ERRORS  ] ?? 0 ) ,
            empty   : (int) ( $body[ ImportField::EMPTY   ] ?? 0 ) ,
            updated : (int) ( $body[ ImportField::UPDATED ] ?? 0 ) ,
            ignored : (int) ( $body[ ImportField::IGNORED ] ?? 0 ) ,
            details : $detailList ,
        ) ;
    }

    /**
     * Returns true when the server reported at least one failed row.
     *
     * @return bool
     */
    public function hasErrors() : bool
    {
        return $this->errors > 0 ;
    }

    /**
     * Returns the result as a plain associative array — useful for
     * logging, JSON serialisation or interop with array-based code paths.
     *
     * @return array<string, int|array<int, string>>
     */
    public function toArray() : array
    {
        return
        [
            ImportField::CREATED => $this->created ,
            ImportField::ERRORS  => $this->errors  ,
            ImportField::EMPTY   => $this->empty   ,
            ImportField::UPDATED => $this->updated ,
            ImportField::IGNORED => $this->ignored ,
            ImportField::DETAILS => $this->details ,
        ] ;
    }
}
