<?php

namespace oihana\arango\models\traits\joins;

use oihana\traits\ContainerTrait;

trait JoinsTrait
{
    use ContainerTrait ;

    /**
     * The 'joins' parameter key.
     */
    public const string JOINS = 'joins' ;

    /**
     * The list of all joins definitions to generates basic relations with documents.
     *
     * @example
     * ```php
     * $documents = new Documents
     * ([
     *     AQL::JOINS =>
     *     [
     *          // each entry maps a local reference field to the related model joined on a key
     *          Schema::ADDITIONAL_TYPE => [ AQL::MODEL => 'placeTypes' , AQL::KEY => Schema::_KEY ] ,
     *          Schema::CONTAINS_PLACE  => [ AQL::MODEL => 'places'     , AQL::KEY => Schema::_KEY ] ,
     *     ],
     *     AQL::FIELDS =>
     *     [
     *         Schema::_KEY               => Filter::DEFAULT ,
     *         Schema::NAME               => Filter::DEFAULT ,
     *         Schema::CREATED            => Filter::DATETIME ,
     *         Schema::MODIFIED           => Filter::DATETIME ,
     *         Schema::ADDITIONAL_TYPE    => Filter::JOIN ,
     *         Schema::CONTAINS_PLACE     => Filter::JOINS ,
     *         Schema::NUM_CONTAINS_PLACE => Filter::JOINS_COUNT ,
     *     ]
     * ]) ;
     *
     * $places = $documents->list([ AQL::LIMIT => 0 ]) ;
     *
     * echo( json_encode( $places , JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) ;
     * ```
     */
    public ?array $joins = null ;

    /**
     * Initialize the 'joins' definitions.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeJoins( array $init = [] ):static
    {
        $this->joins = $init[ self::JOINS ] ?? $this->joins  ;
        return $this ;
    }

    /**
     * Releases the 'joins' definitions.
     *
     * @return static
     */
    public function releasesJoins() :static
    {
        $this->joins = null ;
        return $this ;
    }
}