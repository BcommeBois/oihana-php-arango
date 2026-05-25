<?php

namespace oihana\arango\models\utils;

/**
 * Information about a parsed filter field path
 */
class FilterPath
{
    /**
     * Creates a new FilterPath instance.
     *
     * @param mixed       $type           The filter type (string FilterType/Filter constant or callable)
     * @param array       $path           The full path segments representation.
     * @param mixed       $typeConfig     The FilterType::STRING or nested config
     * @param string|null $relationRef    The reference to edges/joins config
     * @param array|null  $nestedFilters  The nested AQL::FILTERS definition.
     * @param array       $nestedEdges    The nested edges from target model
     * @param array       $nestedJoins    The nested joins from target model
     */
    public function __construct
    (
        string  $type                 ,
        array   $path                 ,
        mixed   $typeConfig           ,
        ?string $relationRef   = null ,
        ?array  $nestedFilters = null ,
        array   $nestedEdges   = []   ,
        array   $nestedJoins   = []   ,
    )
    {
        $this->type          = $type          ;
        $this->path          = $path          ;
        $this->nestedEdges   = $nestedEdges   ;
        $this->nestedFilters = $nestedFilters ;
        $this->nestedJoins   = $nestedJoins   ;
        $this->relationRef   = $relationRef   ;
        $this->typeConfig    = $typeConfig    ;
    }

    /**
     * @var array The full path segments representation.
     */
    public array $path ;

    /**
     * @var array Nested edges from target model
     */
    public array $nestedEdges = [] ;

    /**
     * @var array Nested joins from target model
     */
    public array $nestedJoins = [] ;

    /**
     * @var array|null The nested AQL::FILTERS definition.
     */
    public ?array $nestedFilters = null ;

    /**
     * @var string|null The reference to edges/joins config
     */
    public ?string $relationRef = null ;

    /**
     * @var mixed The filter type from configuration
     */
    public mixed $type ;

    /**
     * @var mixed The FilterType::STRING or nested config
     */
    public mixed $typeConfig ;
}