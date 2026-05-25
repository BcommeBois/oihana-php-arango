<?php

namespace oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\collection\indexes\enums\IndexType ;

/**
 * Time-to-live index definition.
 *
 * The server periodically removes documents whose indexed attribute
 * value (interpreted as a numeric Unix timestamp in seconds, or as a
 * date string in `ISO 8601`) is older than `$expireAfter` seconds.
 *
 * Only one TTL index per collection is allowed.
 *
 * Example:
 * ```php
 * $sessions->createIndex
 * (
 *     new TtlIndex
 *     (
 *         fields      : [ 'createdAt' ] ,
 *         expireAfter : 3600 , // one hour
 *     )
 * ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/ttl-indexes/
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class TtlIndex implements IndexDefinition
{
    /**
     * @param array<int, string> $fields       Exactly one field path holding the reference date / timestamp.
     * @param int                $expireAfter  Document lifetime, in seconds, past the indexed timestamp.
     * @param string|null        $name         Optional human-readable index name.
     * @param bool|null          $inBackground Build the index in the background.
     */
    public function __construct
    (
        public array   $fields ,
        public int     $expireAfter ,
        public ?string $name         = null ,
        public ?bool   $inBackground = null ,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        $data =
        [
            IndexField::TYPE         => IndexType::TTL ,
            IndexField::FIELDS       => $this->fields ,
            IndexField::EXPIRE_AFTER => $this->expireAfter ,
        ] ;

        if ( $this->name         !== null ) { $data[ IndexField::NAME ]          = $this->name         ; }
        if ( $this->inBackground !== null ) { $data[ IndexField::IN_BACKGROUND ] = $this->inBackground ; }

        return $data ;
    }
}
