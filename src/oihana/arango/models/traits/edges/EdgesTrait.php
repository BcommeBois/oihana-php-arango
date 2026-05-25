<?php

namespace oihana\arango\models\traits\edges;

use oihana\traits\ContainerTrait;

trait EdgesTrait
{
    use ContainerTrait ;

    /**
     * The 'edges' parameter key.
     */
    public const string EDGES = 'edges' ;

    /**
     * The list of all edges definitions to generates the complex edge attributes
     * or resolve and create the Edges dependencies in the DI Container.
     *
     * @example
     *
     * 1 - Use an associative array to manage the attributes with an Edges relation.
     * ```php
     * $documents = new Documents
     * ([
     *     AQL::COLLECTION => Collections::PLACES ,
     *     AQL::DATABASE   => Databases::ARANGO ,
     *     AQL::SCHEMA     => Place::class ,
     *     AQL::EDGES      =>
     *     [
     *         Schema::ADDITIONAL_TYPE =>
     *         [
     *              AQL::MODEL => EdgesDefinition::PLACE_HAS_TYPE ,
     *         ]
     *         Schema::CONTAINED_IN_PLACE => // $to -> $from
     *         [
     *             AQL::MODEL     => EdgesDefinition::PLACE_CONTAINS_PLACE ,
     *             AQL::DIRECTION => Traversal::INBOUND ,
     *             AQL::SORT      => Prop::NAME ,
     *             AQL::ORDER     => Order::ASC ,
     *             AQL::SKIN      => Skin::DEFAULT
     *         ],
     *         Schema::CONTAINS_PLACE => // $from -> $to
     *         [
     *              AQL::MODEL  => EdgesDefinition::PLACE_CONTAINS_PLACE ,
     *              AQL::SORT  => Prop::NAME ,
     *              AQL::SKIN  => Skin::DEFAULT ,
     *              AQL::FIELDS => // custom fields
     *              [
     *                    Prop::_KEY     => Filter::DEFAULT  ,
     *                    Prop::NAME     => Filter::DEFAULT  ,
     *                    Prop::CREATED  => Filter::DATETIME ,
     *                    Prop::MODIFIED => Filter::DATETIME ,
     *              ]
     *         ]
     *         // Reference an other edges definition
     *         Schema::NUM_CONTAINS_PLACE => Schema::CONTAINS_PLACE
     *     ],
     *     AQL::FIELDS =>
     *     [
     *         Schema::_KEY               => Filter::DEFAULT ,
     *         Schema::NAME               => Filter::DEFAULT ,
     *         Schema::CREATED            => Filter::DATETIME ,
     *         Schema::MODIFIED           => Filter::DATETIME ,
     *         Schema::ADDITIONAL_TYPE    => Filter::EDGE ,
     *         Schema::CONTAINED_IN_PLACE => Filter::EDGES ,
     *         Schema::CONTAINS_PLACE     => Filter::EDGES ,
     *         Schema::NUM_CONTAINS_PLACE => Filter::EDGES_COUNT ,
     *     ]
     * ]) ;
     *
     * $places = $documents->list([ AQL::LIMIT => 0 ]) ;
     *
     * echo( json_encode( $places , JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) ;
     * ```
     *
     * 2 - Use an indexed array to resolves the Edges definition in the DI Container.
     * ```
     * $documents = new Documents
     * ([
     *     AQL::COLLECTION => Collections::PLACE_TYPES ,
     *     AQL::DATABASE   => Databases::ARANGO ,
     *     AQL::SCHEMA     => DefinedTerm::class ,
     *     AQL::EDGES      =>
     *     [
     *         EdgesDefinition::PLACE_HAS_TYPE ,
     *         // ... others Edges definitions
     *     ],
     * ])
     * ```
     *
     * 3 - Mix the Edges configurations and the Edges only resolving in the DI Container.
     * ```
     * $documents = new Documents
     * ([
     *     AQL::COLLECTION => Collections::PLACE_TYPES ,
     *     AQL::DATABASE   => Databases::ARANGO ,
     *     AQL::SCHEMA     => DefinedTerm::class ,
     *     AQL::EDGES      =>
     *     [
     *          AQL::RESOLVE =>
     *          [
     *               EdgesDefinition::PLACE_HAS_TYPE ,
     *               // ... others Edges definitions
     *          ] ,
     *
     *     ],
     *     Schema::CONTAINS_PLACE =>
     *     [
     *         AQL::MODEL => EdgesDefinition::PLACE_CONTAINS_PLACE ,
     *     ]
     * ])
     * ```
     */
    public ?array $edges = null ;

    /**
     * Initialize the 'edges' definitions.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeEdges( array $init = [] ) :static
    {
        $this->edges = $init[ self::EDGES ] ?? $this->edges ;
        return $this ;
    }

    /**
     * Releases the 'edges' definitions.
     *
     * @return static
     */
    public function releaseEdges() :static
    {
        $this->edges = null ;
        return $this ;
    }
}