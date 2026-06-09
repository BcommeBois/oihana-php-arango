<?php

namespace oihana\arango\db\results;

use oihana\arango\db\enums\ExplainField;

/**
 * A single index that the optimizer chose to use for a query, as reported by an
 * `IndexNode` of an {@see ExplainResult}.
 *
 * This is the typed answer to "which indexes does my query actually use?".
 *
 * @package oihana\arango\db\results
 * @since   1.1.0
 * @author  Marc Alcaraz
 */
readonly class IndexUse
{
    /**
     * @param string            $name                The index name.
     * @param string            $type                The index type (`primary`, `persistent`, `geo`, `vector`, …).
     * @param string|null       $collection          The collection the index belongs to (when known).
     * @param array<int,string> $fields              The document attributes covered by the index.
     * @param bool              $unique              Whether the index enforces uniqueness.
     * @param bool              $sparse              Whether the index is sparse.
     * @param float|null        $selectivityEstimate The optimizer's selectivity estimate (0 … 1), when available.
     */
    public function __construct
    (
        public string  $name ,
        public string  $type ,
        public ?string $collection          = null ,
        public array   $fields              = [] ,
        public bool    $unique              = false ,
        public bool    $sparse              = false ,
        public ?float  $selectivityEstimate = null ,
    )
    {
    }

    /**
     * Builds an {@see IndexUse} from a raw index entry of an `IndexNode`, with the
     * owning collection threaded in from the node.
     *
     * @param array<string,mixed> $index      A single entry of the node's `indexes` array.
     * @param string|null         $collection The node's collection, if known.
     */
    public static function fromArray( array $index , ?string $collection = null ) : self
    {
        return new self
        (
            name                : (string) ( $index[ ExplainField::NAME ] ?? '' ) ,
            type                : (string) ( $index[ ExplainField::TYPE ] ?? '' ) ,
            collection          : $collection ,
            fields              : array_values( (array) ( $index[ ExplainField::FIELDS ] ?? [] ) ) ,
            unique              : (bool) ( $index[ ExplainField::UNIQUE ] ?? false ) ,
            sparse              : (bool) ( $index[ ExplainField::SPARSE ] ?? false ) ,
            selectivityEstimate : isset( $index[ ExplainField::SELECTIVITY_ESTIMATE ] )
                                ? (float) $index[ ExplainField::SELECTIVITY_ESTIMATE ]
                                : null ,
        ) ;
    }
}
