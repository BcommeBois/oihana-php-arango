<?php

namespace oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\collection\indexes\enums\IndexType ;

/**
 * Legacy fulltext index definition.
 *
 * **Deprecated** since ArangoDB 3.10 in favour of {@see InvertedIndex}
 * / ArangoSearch views, but still kept here because a lot of existing
 * code relies on it. Avoid for new schemas.
 *
 * @see https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/fulltext-indexes/
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class FulltextIndex implements IndexDefinition
{
    /**
     * @param array<int, string> $fields       Exactly one field path holding the text payload.
     * @param int|null           $minLength    Minimum word length to index, in characters.
     * @param string|null        $name         Optional human-readable index name.
     * @param bool|null          $inBackground Build the index in the background.
     */
    public function __construct
    (
        public array   $fields ,
        public ?int    $minLength    = null ,
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
            IndexField::TYPE   => IndexType::FULLTEXT ,
            IndexField::FIELDS => $this->fields ,
        ] ;

        if ( $this->minLength    !== null ) { $data[ IndexField::MIN_LENGTH ]    = $this->minLength    ; }
        if ( $this->name         !== null ) { $data[ IndexField::NAME ]          = $this->name         ; }
        if ( $this->inBackground !== null ) { $data[ IndexField::IN_BACKGROUND ] = $this->inBackground ; }

        return $data ;
    }
}
