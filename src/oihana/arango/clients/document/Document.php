<?php

namespace oihana\arango\clients\document ;

use org\schema\constants\Schema ;

/**
 * Immutable value object wrapping a single ArangoDB document.
 *
 * Exposes lightweight accessors for the three reserved ArangoDB
 * attributes (`_key`, `_id`, `_rev`) along with a generic
 * `get()` / `has()` API on the rest of the payload, and a
 * `toArray()` escape hatch for callers that want to interoperate with
 * array-based code paths (query builders, JSON encoders,
 * `oihana\reflect\Reflection::hydrate()`, …).
 *
 * Construction is fire-and-forget: the document is filled in from the
 * server response and never mutated locally. To "modify" a document,
 * send an update request through the parent {@see \oihana\arango\clients\Database}
 * and construct a new instance from the response.
 *
 * Example:
 * ```php
 * $document = new Document( [ Schema::_KEY => 'abc' , Schema::_ID => 'users/abc' , 'name' => 'Marc' ] ) ;
 *
 * $document->getKey() ; // 'abc'
 * $document->getId()  ; // 'users/abc'
 * $document->get( 'name' ) ; // 'Marc'
 * $document->isNew() ; // false
 *
 * // Hydrate into a typed DTO (delegated to oihana/php-reflect):
 * $dto = ( new \oihana\reflect\Reflection() )->hydrate( $document->toArray() , UserDto::class ) ;
 * ```
 *
 * @package oihana\arango\clients\document
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class Document
{
    /**
     * @param array<string, mixed> $data Raw document data as returned by the server (including the reserved `_key` / `_id` / `_rev` attributes when present).
     */
    public function __construct
    (
        public array $data = [] ,
    )
    {
    }

    /**
     * Returns the value of a single field on the document, or `$default`
     * when the field is absent.
     *
     * @param string $field   Field name (top-level only).
     * @param mixed  $default Fallback value when the field is absent.
     *
     * @return mixed
     */
    public function get( string $field , mixed $default = null ) : mixed
    {
        return $this->data[ $field ] ?? $default ;
    }

    /**
     * Returns the ArangoDB document identifier (`_id`), or null when the
     * document has not been persisted yet.
     *
     * @return string|null
     */
    public function getId() : ?string
    {
        return isset( $this->data[ Schema::_ID ] ) ? (string) $this->data[ Schema::_ID ] : null ;
    }

    /**
     * Returns the ArangoDB document key (`_key`), or null when the
     * document has not been persisted yet.
     *
     * @return string|null
     */
    public function getKey() : ?string
    {
        return isset( $this->data[ Schema::_KEY ] ) ? (string) $this->data[ Schema::_KEY ] : null ;
    }

    /**
     * Returns the document revision (`_rev`) — read-only, managed by the
     * server. Null when the document has not been persisted yet.
     *
     * @return string|null
     */
    public function getRev() : ?string
    {
        return isset( $this->data[ Schema::_REV ] ) ? (string) $this->data[ Schema::_REV ] : null ;
    }

    /**
     * Returns true when the document has the given field (even when the
     * stored value is null).
     *
     * @param string $field
     *
     * @return bool
     */
    public function has( string $field ) : bool
    {
        return array_key_exists( $field , $this->data ) ;
    }

    /**
     * Returns true when the document has not been persisted yet (no
     * `_key` assigned by the server).
     *
     * @return bool
     */
    public function isNew() : bool
    {
        return !isset( $this->data[ Schema::_KEY ] ) ;
    }

    /**
     * Returns the underlying raw data array (including reserved attributes).
     *
     * @return array<string, mixed>
     */
    public function toArray() : array
    {
        return $this->data ;
    }
}
