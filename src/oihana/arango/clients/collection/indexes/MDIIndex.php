<?php

namespace oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\collection\indexes\enums\IndexType ;

/**
 * Multi-dimensional index definition (`mdi` / `mdi-prefixed`).
 *
 * Stable since ArangoDB 3.12. Indexes numeric (currently `double`) values across several attributes,
 * accelerating range queries like `lat > x AND lng < y AND timestamp BETWEEN a AND b`.
 *
 * The resulting `type` is automatically resolved to {@see IndexType::MDI_PREFIXED} when `$prefixFields` is non-empty,
 * and {@see IndexType::MDI} otherwise.
 *
 * Example:
 * ```php
 * $events->createIndex
 * (
 *     new MDIIndex
 *     (
 *         fields       : [ 'lat' , 'lng' , 'timestamp' ] ,
 *         prefixFields : [ 'tenant' ] ,                  // optional → mdi-prefixed
 *     )
 * ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/multi-dimensional-indexes/
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class MDIIndex implements IndexDefinition
{
    /**
     * @param array<int, string>      $fields          Document attribute paths the index applies to (must hold numeric values matching `$fieldValueTypes`).
     * @param string                  $fieldValueTypes Numeric type stored for each indexed value (currently only `"double"` is supported by the server).
     * @param array<int, string>|null $prefixFields    Optional prefix attributes — when supplied, the index becomes `mdi-prefixed`.
     * @param bool                    $unique          Enforce uniqueness across indexed tuples.
     * @param bool                    $sparse          Skip documents missing every indexed attribute.
     * @param string|null             $name            Optional human-readable index name.
     * @param bool|null               $estimates       Maintain selectivity estimates for the query optimizer.
     * @param array<int, string>|null $storedValues    Additional attribute paths kept alongside the index entries.
     * @param bool|null               $inBackground    Build the index in the background.
     */
    public function __construct
    (
        public array   $fields ,
        public string  $fieldValueTypes = 'double' ,
        public ?array  $prefixFields    = null ,
        public bool    $unique          = false ,
        public bool    $sparse          = false ,
        public ?string $name            = null ,
        public ?bool   $estimates       = null ,
        public ?array  $storedValues    = null ,
        public ?bool   $inBackground    = null ,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        $prefixed = $this->prefixFields !== null && count( $this->prefixFields ) > 0 ;

        $data =
        [
            IndexField::TYPE              => $prefixed ? IndexType::MDI_PREFIXED : IndexType::MDI ,
            IndexField::FIELDS            => $this->fields ,
            IndexField::FIELD_VALUE_TYPES => $this->fieldValueTypes ,
        ] ;

        if ( $prefixed )                    { $data[ IndexField::PREFIX_FIELDS ] = $this->prefixFields ; }
        if ( $this->unique )                { $data[ IndexField::UNIQUE ]        = true ; }
        if ( $this->sparse )                { $data[ IndexField::SPARSE ]        = true ; }
        if ( $this->name         !== null ) { $data[ IndexField::NAME ]          = $this->name         ; }
        if ( $this->estimates    !== null ) { $data[ IndexField::ESTIMATES ]     = $this->estimates    ; }
        if ( $this->storedValues !== null ) { $data[ IndexField::STORED_VALUES ] = $this->storedValues ; }
        if ( $this->inBackground !== null ) { $data[ IndexField::IN_BACKGROUND ] = $this->inBackground ; }

        return $data ;
    }
}
