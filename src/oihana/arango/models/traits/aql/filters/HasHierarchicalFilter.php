<?php

namespace oihana\arango\models\traits\aql\filters;

use Exception;
use oihana\exceptions\UnsupportedOperationException;
use org\schema\constants\Schema;
use ReflectionException;
use RuntimeException;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\db\enums\Operator;
use oihana\arango\models\enums\filters\FilterType;
use oihana\arango\models\utils\FilterPath;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Filter;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\Edges;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\reflect\exceptions\ConstantException;
use oihana\traits\ContainerTrait;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerAwareTrait;

use function oihana\arango\db\binds\aqlBindCollection;
use function oihana\arango\db\functions\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLimit;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\arango\models\helpers\edges\getEdges;
use function oihana\arango\models\helpers\extractNestedRelations;
use function oihana\arango\models\helpers\parseFilterSegment;
use function oihana\core\callables\resolveCallable;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\predicate;

trait HasHierarchicalFilter
{
    use ContainerTrait   ,
        LoggerAwareTrait ;

    /**
     * Prepare hierarchical filter from declarative configuration
     *
     * @param array $init
     * @param array $binds
     * @param string $docRef
     *
     * @return string|null
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    protected function prepareHierarchicalFilter
    (
        array  $init   ,
        array  &$binds ,
        string $docRef = AQL::DOC
    )
    : ?string
    {
        $filterKey = $init[ FilterParam::KEY ] ?? null ;

        if ( !$filterKey )
        {
            return null;
        }

        $segments = explode(Char::DOT , $filterKey ) ;

        return $this->buildFilterRecursive
        (
            segments   : $segments            ,
            filters    : $this->filters ?? [] ,
            init       : $init                ,
            binds    : $binds                 ,
            docRef     : $docRef              ,
        );
    }

    /**
     * Build filter condition recursively through path segments.
     *
     * @param array $segments Remaining segments to process.
     * @param array $filters Current level filter configuration.
     * @param array $init Original filter parameters.
     * @param array   &$binds Bind variables array.
     * @param string $docRef Current document reference.
     * @param array $parentPath Accumulated path from parent segments.
     * @param array $currentEdges The current edges definitions.
     * @param array $currentJoins The current joins definitions.
     *
     * @return string|null
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    private function buildFilterRecursive
    (
        array  $segments          ,
        array  $filters           ,
        array  $init              ,
        array  &$binds            ,
        string $docRef            ,
        array  $parentPath   = [] ,
        array  $currentEdges = [] ,
        array  $currentJoins = [] ,
    )
    : ?string
    {
        // Unreachable via the public path: the entry splits a non-empty key and
        // every recursive call only happens for a non-last (non-empty) segment.
        // @codeCoverageIgnoreStart
        if ( empty( $segments ) )
        {
            return null ;
        }
        // @codeCoverageIgnoreEnd

        $currentSegment = array_shift( $segments ) ;
        $isLast         = empty( $segments ) ;

        // Use current edges/joins or fall back to model's edges/joins
        $availableEdges = !empty( $currentEdges ) ? $currentEdges : ( $this->edges ?? [] ) ;
        $availableJoins = !empty( $currentJoins ) ? $currentJoins : ( $this->joins ?? [] ) ;

        // Parse current segment
        $segmentInfo = parseFilterSegment
        (
            segment    : $currentSegment  ,
            filters    : $filters         ,
            edges      : $availableEdges  ,
            joins      : $availableJoins  ,
            parentPath : $parentPath      ,
            container  : $this->container ,
        );

        if ( !$segmentInfo )
        {
            $attemptedPath = implode('.' , [ ...$parentPath , $currentSegment ] ) ;
            $this->logger->warning( sprintf( 'Filter segment not allowed: %s' ,  $attemptedPath ) );
            return null;
        }

        // If it's the last segment, delegate to existing prepareFilter logic
        if ( $isLast )
        {
            return $this->buildLeafCondition( $segmentInfo , $init , $binds , $docRef ) ;
        }

        // Not last - must traverse structure
        return match( $segmentInfo->type )
        {
            Filter::DOCUMENT => $this->buildDocumentTraversal
            (
                $segments    ,
                $segmentInfo ,
                $init        ,
                $binds    ,
                $docRef      ,
            ),
            Filter::ARRAY_EXPANSION => $this->buildArrayTraversal
            (
                $segments    ,
                $segmentInfo ,
                $init        ,
                $binds    ,
                $docRef      ,
            ),
            Filter::EDGE, Filter::EDGES => $this->buildEdgeTraversal
            (
                remainingSegments : $segments    ,
                segmentInfo       : $segmentInfo ,
                init              : $init        ,
                binds           : $binds    ,
                docRef            : $docRef      ,
                availableEdges    : $availableEdges  ,
            ),
            Filter::JOIN, Filter::JOINS => $this->buildJoinTraversal
            (
                remainingSegments : $segments    ,
                segmentInfo       : $segmentInfo ,
                init              : $init        ,
                binds           : $binds    ,
                docRef            : $docRef      ,
                availableJoins    : $availableJoins  ,
            ),
            default => null
        };
    }

    /**
     * Build array expansion traversal.
     *
     * @param array $remainingSegments
     * @param FilterPath $segmentInfo
     * @param array $init
     * @param array $binds
     * @param string $docRef
     *
     * @return string|null
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    private function buildArrayTraversal
    (
        array      $remainingSegments ,
        FilterPath $segmentInfo       ,
        array      $init              ,
        array      &$binds            ,
        string     $docRef            ,
    )
    : ?string
    {
        $currentKey = end($segmentInfo->path ) ;
        $cleanKey   = str_replace( Operator::ARRAY_EXPANSION , '' , $currentKey ) ;

        $nestedPath = implode(Char::DOT , $remainingSegments ) ;
        $fullPath   = $cleanKey . Operator::ARRAY_EXPANSION . Char::DOT . $nestedPath ;

        // Create init for array field — forward `alt` so the inline expansion
        // condition (CURRENT.<field>) is wrapped like the flat filters.
        $arrayInit =
        [
            FilterParam::KEY => $fullPath ,
            FilterParam::VAL => $init[ FilterParam::VAL ] ?? null,
            FilterParam::OP  => $init[ FilterParam::OP  ] ?? null,
            FilterParam::ALT => $init[ FilterParam::ALT ] ?? null,
        ];

        // Forward the `quant` element-axis quantifier only on a single-level
        // object array (one `[*]`). Multi-level traversal is out of scope: the
        // level the quantifier binds to would be ambiguous, so it keeps the
        // legacy ANY behaviour (existential LENGTH(...) > 0).
        if ( isset( $init[ FilterParam::QUANT ] ) && substr_count( $fullPath , Operator::ARRAY_EXPANSION ) === 1 )
        {
            $arrayInit[ FilterParam::QUANT ] = $init[ FilterParam::QUANT ] ;
        }

        return $this->prepareFilterArray( $arrayInit , $binds , $docRef ) ;
    }

    /**
     * Build document traversal (nested object)
     *
     * @param array $remainingSegments
     * @param FilterPath $segmentInfo
     * @param array $init
     * @param array $binds
     * @param string $docRef
     *
     * @return string|null
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    private function buildDocumentTraversal
    (
        array      $remainingSegments ,
        FilterPath $segmentInfo       ,
        array      $init              ,
        array      &$binds            ,
        string     $docRef
    )
    : ?string
    {
        $currentKey   = end($segmentInfo->path );
        $nestedDocRef = key( $currentKey , $docRef ) ;

        return $this->buildFilterRecursive
        (
            segments   : $remainingSegments ,
            filters    : $segmentInfo->nestedFilters ?? [] ,
            init       : $init,
            binds    : $binds ,
            docRef     : $nestedDocRef ,
            parentPath : $segmentInfo->path  // Pass accumulated path
        );
    }

    /**
     * Build edge traversal
     *
     * @param array $remainingSegments The remaining path segments to process.
     * @param FilterPath $segmentInfo The current segment information with nested relations.
     * @param array $init The original filter parameters.
     * @param array $binds The bind variables array.
     * @param string $docRef The current document reference.
     * @param array $availableEdges
     * @return string|null The AQL condition for edge traversal, or null on failure.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException If edge configuration is invalid or not found.
     * @throws UnsupportedOperationException
     */
    private function buildEdgeTraversal
    (
        array      $remainingSegments ,
        FilterPath $segmentInfo       ,
        array      $init              ,
        array      &$binds            ,
        string     $docRef            ,
        array      $availableEdges    = [] ,
    )
    : ?string
    {
        $relationRef = $segmentInfo->relationRef ;

        $edgeConfig = $availableEdges[ $relationRef ] ?? null ;

        // Unreachable: parseFilterSegment already validated that $relationRef
        // exists in this same edges map (it throws otherwise) before the segment
        // is classified as an edge and routed here.
        // @codeCoverageIgnoreStart
        if ( !$edgeConfig )
        {
            $pathStr = implode( '.' , $segmentInfo->path ) ;
            throw new RuntimeException( "Edge '$relationRef' not found for path: $pathStr");
        }
        // @codeCoverageIgnoreEnd

        $edges = getEdges($edgeConfig[ AQL::MODEL ] ?? null , $this->container ) ;
        if ( !( $edges instanceof Edges ) )
        {
            $pathStr = implode( '.' , $segmentInfo->path ) ;
            throw new RuntimeException( "Invalid edge model '$relationRef' at path: $pathStr" ) ;
        }

        $edgeCollection = $edges->collection;
        $direction      = $edgeConfig[ AQL::DIRECTION ] ?? Traversal::OUTBOUND ;
        $vertexID       = uniqid( 'v_' ) ;

        // Get the target model to extract its edges/joins for next level
        $targetModel = $direction === Traversal::INBOUND ? $edges->from : $edges->to ;
        $nextLevel   = extractNestedRelations
        (
            config      : $edgeConfig   ,
            targetModel : $targetModel  ,
        ) ;

        // Build condition for remaining path recursively
        $innerCondition = $this->buildFilterRecursive
        (
            segments     : $remainingSegments                ,
            filters      : $segmentInfo->nestedFilters ?? [] ,
            init         : $init                             ,
            binds      : $binds                            ,
            docRef       : $vertexID                         ,
            parentPath   : $segmentInfo->path                ,
            currentEdges : $nextLevel[ AQL::EDGES ]          ,
            currentJoins : $nextLevel[ AQL::JOINS ]          ,
        );

        if ( !$innerCondition )
        {
            $pathStr = implode( '.' , $segmentInfo->path ) ;
            $this->logger->warning( "Failed to build inner condition for edge at path: $pathStr" ) ;
            return null ;
        }

        return betweenParentheses( predicate
        (
            leftOperand : length( compile
            ([
                aqlTraversal
                (
                    [
                        AQL::VERTEX_REF      => $vertexID,
                        AQL::EDGE_COLLECTION => $edgeCollection,
                        AQL::DIRECTION       => $direction,
                        AQL::START_VERTEX    => $docRef
                    ] ,
                    $binds
                ),
                aqlFilter ( [ $innerCondition ] ) ,
                aqlLimit  ( limit      : 1      ) ,
                aqlReturn ( expression : 1      )
            ])),
            operator     : Comparator::GREATER_THAN,
            rightOperand : 0
        ));
    }

    /**
     * Build join traversal
     *
     * @param array $remainingSegments The remaining path segments to process.
     * @param FilterPath $segmentInfo The current segment information with nested relations.
     * @param array $init The original filter parameters.
     * @param array $binds The bind variables array.
     * @param string $docRef The current document reference.
     * @param array $availableJoins
     * @return string|null The AQL condition for join traversal, or null on failure.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    private function buildJoinTraversal
    (
        array      $remainingSegments ,
        FilterPath $segmentInfo       ,
        array      $init              ,
        array      &$binds            ,
        string     $docRef            ,
        array      $availableJoins    = [] ,
    )
    : ?string
    {
        $relationRef = $segmentInfo->relationRef;
        $joinConfig = $availableJoins[ $relationRef ] ?? null ;

        // Unreachable: parseFilterSegment already validated that $relationRef
        // exists in this same joins map (it throws otherwise) before the segment
        // is classified as a join and routed here.
        // @codeCoverageIgnoreStart
        if ( !$joinConfig )
        {
            $pathStr = implode( '.' , $segmentInfo->path ) ;
            throw new RuntimeException("Join '$relationRef' not found for path: $pathStr");
        }
        // @codeCoverageIgnoreEnd

        $joinKey = $joinConfig[ AQL::KEY   ] ?? Schema::_KEY ;
        $model   = $joinConfig[ AQL::MODEL ] ?? null ;

        if ( !$model )
        {
            $pathStr = implode( '.' , $segmentInfo->path ) ;
            throw new RuntimeException("No model for join: $relationRef at path: $pathStr" ) ;
        }

        $documents  = $this->container->get( $model ) ;
        $collection = $documents?->collection;

        if ( !$collection )
        {
            $pathStr = implode( '.' , $segmentInfo->path ) ;
            throw new RuntimeException("Cannot resolve collection: $model at path: $pathStr");
        }

        $joinDocRef = uniqid('join_' ) ;
        $currentKey = end( $segmentInfo->path ) ;
        $sourceKey  = key( $currentKey , $docRef ) ;

        // Get the target model to extract its edges/joins for next level
        $nextLevel = extractNestedRelations
        (
            config      : $joinConfig  ,
            targetModel : $documents   ,
        ) ;

        // Build condition for remaining path recursively
        $innerCondition = $this->buildFilterRecursive
        (
            segments     : $remainingSegments                ,
            filters      : $segmentInfo->nestedFilters ?? [] ,
            init         : $init                             ,
            binds      : $binds                            ,
            docRef       : $joinDocRef                       ,
            parentPath   : $segmentInfo->path                ,
            currentEdges : $nextLevel[ AQL::EDGES ]          ,
            currentJoins : $nextLevel[ AQL::JOINS ]          ,
        );

        if ( !$innerCondition )
        {
            return null;
        }

        return betweenParentheses( predicate
        (
            leftOperand : length(compile
            ([
                aqlFor
                ([
                    AQL::DOC_REF => $joinDocRef,
                    AQL::IN      => aqlBindCollection( $collection , $binds )
                ]),
                aqlFilter
                ([
                    predicate( key( $joinKey , $joinDocRef ) , Comparator::EQUAL , $sourceKey ) ,
                    $innerCondition
                ]),
                aqlLimit  ( limit      : 1 ) ,
                aqlReturn ( expression : 1 )
            ])),
            operator     : Comparator::GREATER_THAN ,
            rightOperand : 0
        ));
    }

    /**
     * Build leaf condition by delegating to existing filter logic
     *
     * @param FilterPath $segmentInfo The segment information with type and path.
     * @param array      $init        The original filter parameters.
     * @param array      $binds       The bind variables array.
     * @param string     $docRef      The current document reference.
     *
     * @return string|null The AQL condition string, or null on failure.
     */
    private function buildLeafCondition
    (
        FilterPath $segmentInfo ,
        array      $init        ,
        array      &$binds      ,
        string     $docRef      ,
    )
    : ?string
    {
        // Get the current segment key (last element of path)
        $fieldKey = end( $segmentInfo->path ) ;

        // Remove array notation if present (e.g., "email[*]" -> "email")
        $fieldKey = str_replace( Operator::ARRAY_EXPANSION , '' , $fieldKey ) ;

        // Create filter init for this field
        $fieldInit =
        [
            ...$init ,
            FilterParam::KEY => $fieldKey ,
        ];

        try
        {
            // Case 1: Simple FilterType (string, number, date, bool, array)
            if ( is_string( $segmentInfo->type ) && FilterType::includes( $segmentInfo->type ) )
            {
                return match( $segmentInfo->type )
                {
                    FilterType::ARRAY  => $this->prepareFilterArray   ( $fieldInit , $binds , $docRef ) ,
                    FilterType::BOOL   => $this->prepareFilterBoolean ( $fieldInit , $binds , $docRef ) ,
                    FilterType::DATE   => $this->prepareFilterDate    ( $fieldInit , $binds , $docRef ) ,
                    FilterType::NUMBER => $this->prepareFilterNumber  ( $fieldInit , $binds , $docRef ) ,
                    FilterType::STRING => $this->prepareFilterString  ( $fieldInit , $binds , $docRef ) ,
                    default            => null
                };
            }

            // Case 2: Custom filter (callable)
            // The callable is stored in segmentInfo->type
            $customFilter = resolveCallable( $segmentInfo->type ) ;

            if ( $customFilter !== null )
            {
                return $customFilter( $fieldInit , $binds , $docRef ) ;
            }

            // No handler found
            $pathStr = implode('.' , $segmentInfo->path ) ;
            $this->logger->warning( sprintf
            (
                "No handler found for filter at path: %s (type: %s)" ,
                $pathStr ,
                is_string($segmentInfo->type) ? $segmentInfo->type : gettype($segmentInfo->type)
            ));

            return null ;
        }
        catch ( Exception $e )
        {
            $pathStr = implode('.' , $segmentInfo->path ) ;
            $this->logger->error( sprintf( "Failed to build filter for path: %s" , $pathStr ) ,
            [
                'error' => $e->getMessage() ,
                'type'  => $segmentInfo->type ,
                'field' => $fieldKey ,
            ]);
            return null ;
        }
    }
}