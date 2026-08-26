<?php

namespace oihana\arango\models\traits\aql\filters;

use Exception;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
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
use oihana\arango\enums\Filter;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\Edges;
use oihana\arango\exceptions\RequestValidationException;
use oihana\enums\Boolean;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\reflect\exceptions\ConstantException;
use oihana\traits\ContainerTrait;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerAwareTrait;

use function oihana\arango\db\binds\aqlBindCollection;
use function oihana\arango\db\functions\length;
use function oihana\arango\db\operators\logicalNot;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLimit;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\arango\db\helpers\resolveTraversalQuantifier;
use function oihana\arango\models\helpers\edges\getEdges;
use function oihana\arango\models\helpers\edges\resolveEdgeDirection;
use function oihana\arango\models\helpers\edges\resolveEdgeTarget;
use function oihana\arango\models\helpers\extractNestedRelations;
use function oihana\arango\models\helpers\isAuthorized;
use function oihana\arango\models\helpers\isPathAuthorized;
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
     * Prepare a hierarchical filter from the declarative `AQL::FILTERS` configuration.
     *
     * Entry point of the dotted-key grammar: the caller's `key` is split on `.` and each
     * segment is walked against the configuration of the level it belongs to, crossing
     * nested objects, array expansions, edges and joins until a leaf is reached.
     *
     * ```php
     * // ?filter={ "key":"address.city" , "val":"Paris" }
     * // -> doc.address.city == @value
     * ```
     *
     * @param array  $init   The filter parameters (`key`, `val`, `op`, `alt`, `quant`, …).
     * @param array  &$binds The bind variables, populated by reference.
     * @param string $docRef The document reference the condition is written against.
     * @param array  $auth   The caller's permission context, consulted by the leaf gate so a
     *                       locked field is neutralised to `false` rather than dropped.
     *
     * @return string|null The AQL condition, or `null` when the key is empty or the path is
     *                     not declared filterable.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws RequestValidationException When the request itself is refused (unknown operator,
     *                                    unusable quantifier).
     * @throws RuntimeException When the configuration names a relation or a model that cannot
     *                          be resolved.
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareHierarchicalFilter
    (
        array  $init   ,
        array  &$binds ,
        string $docRef = AQL::DOC ,
        array  $auth   = []
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
            segments      : $segments            ,
            filters       : $this->filters ?? [] ,
            init          : $init                ,
            binds         : $binds               ,
            docRef        : $docRef              ,
            auth          : $auth                ,
            currentFields : $this->fields ?? null ,
        );
    }

    /**
     * Build filter condition recursively through path segments.
     *
     * @param array      $segments      Remaining segments to process; the first is consumed here.
     * @param array      $filters       The `AQL::FILTERS` configuration of the current level.
     * @param array      $init          The original filter parameters.
     * @param array      &$binds        The bind variables, populated by reference.
     * @param string     $docRef        The document reference of the current level.
     * @param array      $parentPath    The accumulated path from parent segments, used for
     *                                  error reporting and for the full leaf key.
     * @param array      $currentEdges  The edges definitions in scope, empty to fall back to
     *                                  the model's own.
     * @param array      $currentJoins  The joins definitions in scope, empty to fall back to
     *                                  the model's own.
     * @param array      $auth          The caller's permission context.
     * @param array|null $currentFields The projection of the model being walked — it follows the
     *                                  relations, so a leaf is always gated against the fields
     *                                  of the model that actually holds it. `null` when the
     *                                  model declares no projection.
     * @param array      $fieldPath     The path relative to `$currentFields`, extended by each
     *                                  nested object and reset whenever a relation is crossed.
     *
     * @return string|null The AQL condition, or `null` when the segment is not declared
     *                     filterable or no handler could be found for the leaf.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws RequestValidationException When the request itself is refused.
     * @throws RuntimeException When a relation reference cannot be resolved.
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildFilterRecursive
    (
        array  $segments          ,
        array  $filters           ,
        array  $init              ,
        array  &$binds            ,
        string $docRef            ,
        array  $parentPath    = []   ,
        array  $currentEdges  = []   ,
        array  $currentJoins  = []   ,
        array  $auth          = []   ,
        ?array $currentFields = null ,
        array  $fieldPath     = []   ,
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

        // If it's the last segment, delegate to the leaf logic — unless the
        // segment is itself a relation (edge/join), in which case there is no
        // leaf field: it is a pure existence/absence check on the relation
        // (e.g. `members[*]` with `quant`), routed to the traversal builders.
        if ( $isLast )
        {
            return match( $segmentInfo->type )
            {
                Filter::EDGE, Filter::EDGES => $this->buildEdgeTraversal
                (
                    remainingSegments : []              ,
                    segmentInfo       : $segmentInfo    ,
                    init              : $init           ,
                    binds             : $binds          ,
                    docRef            : $docRef         ,
                    availableEdges    : $availableEdges ,
                    auth              : $auth           ,
                ),
                Filter::JOIN, Filter::JOINS => $this->buildJoinTraversal
                (
                    remainingSegments : []              ,
                    segmentInfo       : $segmentInfo    ,
                    init              : $init           ,
                    binds             : $binds          ,
                    docRef            : $docRef         ,
                    availableJoins    : $availableJoins ,
                    auth              : $auth           ,
                ),
                default => $this->buildLeafCondition( $segmentInfo , $init , $binds , $docRef , $currentFields , $fieldPath , $auth ),
            };
        }

        // Not last - must traverse structure
        return match( $segmentInfo->type )
        {
            Filter::DOCUMENT => $this->buildDocumentTraversal
            (
                $segments      ,
                $segmentInfo   ,
                $init          ,
                $binds         ,
                $docRef        ,
                $auth          ,
                $currentFields ,
                $fieldPath     ,
            ),
            Filter::ARRAY_EXPANSION => $this->buildArrayTraversal
            (
                $segments      ,
                $segmentInfo   ,
                $init          ,
                $binds         ,
                $docRef        ,
                $auth          ,
                $currentFields ,
                $fieldPath     ,
            ),
            Filter::EDGE, Filter::EDGES => $this->buildEdgeTraversal
            (
                remainingSegments : $segments       ,
                segmentInfo       : $segmentInfo    ,
                init              : $init           ,
                binds             : $binds          ,
                docRef            : $docRef         ,
                availableEdges    : $availableEdges ,
                auth              : $auth           ,
            ),
            Filter::JOIN, Filter::JOINS => $this->buildJoinTraversal
            (
                remainingSegments : $segments       ,
                segmentInfo       : $segmentInfo    ,
                init              : $init           ,
                binds             : $binds          ,
                docRef            : $docRef         ,
                availableJoins    : $availableJoins ,
                auth              : $auth           ,
            ),
            default => null
        };
    }

    /**
     * Build an array expansion traversal (`contactPoint[*].email`).
     *
     * The remaining segments are folded back into a single flat key so the array filter
     * can emit the inline expansion in one predicate.
     *
     * @param array      $remainingSegments The segments below the array, addressing the element
     *                                      sub-field.
     * @param FilterPath $segmentInfo       The parsed array segment.
     * @param array      $init              The original filter parameters.
     * @param array      &$binds            The bind variables, populated by reference.
     * @param string     $docRef            The document reference holding the array.
     * @param array      $auth              The caller's permission context.
     * @param array|null $currentFields     The projection of the model holding the array.
     * @param array      $fieldPath         The path relative to `$currentFields`.
     *
     * @return string|null The AQL condition, or `false` when the sub-field is refused by the
     *                     permission gate.
     *
     * @throws BindException
     * @throws RequestValidationException When the request itself is refused.
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildArrayTraversal
    (
        array      $remainingSegments   ,
        FilterPath $segmentInfo         ,
        array      $init                ,
        array      &$binds              ,
        string     $docRef              ,
        array      $auth          = []   ,
        ?array     $currentFields = null ,
        array      $fieldPath     = []   ,
    )
    : ?string
    {
        $currentKey = end($segmentInfo->path ) ;
        $cleanKey   = str_replace( Operator::ARRAY_EXPANSION , '' , $currentKey ) ;

        // Permission gate (Option B): the object-array leaf (`contactPoint[*].email`)
        // inherits the Field::REQUIRES of its exact sub-field in the current model's
        // projection. A refused sub-field neutralises the whole predicate to `false`
        // (never dropped) — the edge/join short-circuit then sinks the traversal.
        $leafRelative = implode( Char::DOT , [ ...$fieldPath , $cleanKey , ...$remainingSegments ] ) ;

        if ( !isPathAuthorized( $leafRelative , $currentFields , $auth ) )
        {
            return Boolean::FALSE ;
        }

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
     * Build a nested object traversal (`address.city`).
     *
     * A nested document stays inside the SAME model, so the projection is carried over
     * unchanged and only the relative field path is extended.
     *
     * @param array      $remainingSegments The segments below the object.
     * @param FilterPath $segmentInfo       The parsed object segment.
     * @param array      $init              The original filter parameters.
     * @param array      &$binds            The bind variables, populated by reference.
     * @param string     $docRef            The document reference holding the object.
     * @param array      $auth              The caller's permission context.
     * @param array|null $currentFields     The projection of the model holding the object.
     * @param array      $fieldPath         The path relative to `$currentFields`, extended with
     *                                      this object's key before recursing.
     *
     * @return string|null The AQL condition built from the segments below, or `null` when none
     *                     could be resolved.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws RequestValidationException When the request itself is refused.
     * @throws RuntimeException When a relation reference below cannot be resolved.
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildDocumentTraversal
    (
        array      $remainingSegments   ,
        FilterPath $segmentInfo         ,
        array      $init                ,
        array      &$binds              ,
        string     $docRef              ,
        array      $auth          = []   ,
        ?array     $currentFields = null ,
        array      $fieldPath     = []   ,
    )
    : ?string
    {
        $currentKey   = end($segmentInfo->path );
        $nestedDocRef = key( $currentKey , $docRef ) ;

        // A nested document stays in the SAME model: keep $currentFields and extend
        // the relative field path so the leaf gate sees the exact sub-field
        // (e.g. `address.city`) — not only the root segment.
        $fieldPath[] = str_replace( Operator::ARRAY_EXPANSION , Char::EMPTY , (string) $currentKey ) ;

        return $this->buildFilterRecursive
        (
            segments      : $remainingSegments ,
            filters       : $segmentInfo->nestedFilters ?? [] ,
            init          : $init,
            binds         : $binds ,
            docRef        : $nestedDocRef ,
            parentPath    : $segmentInfo->path , // Pass accumulated path
            auth          : $auth ,
            currentFields : $currentFields ,
            fieldPath     : $fieldPath ,
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
     * @param array $availableEdges The edges definitions in scope at this level.
     * @param array $auth The caller's permission context. The relation itself is gated here;
     *                    the target model's own projection then gates the leaf below.
     *
     * @return string|null The AQL condition for the edge traversal, `false` when the relation is
     *                     refused, or `null` when the inner condition could not be resolved.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException If edge configuration is invalid or not found.
     * @throws RequestValidationException When the request itself is refused — an unusable
     *                                    `quant`, or an operator this leaf cannot honour.
     * @throws RuntimeException When the edge or its model cannot be resolved.
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildEdgeTraversal
    (
        array      $remainingSegments ,
        FilterPath $segmentInfo       ,
        array      $init              ,
        array      &$binds            ,
        string     $docRef            ,
        array      $availableEdges    = [] ,
        array      $auth              = [] ,
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

        // Permission gate (relation level): a relation locked at its definition
        // (AQL::REQUIRES on the edge config, the same subject read by the projection
        // gate) cannot be filtered through — the whole traversal is neutralised to
        // `false`, so a relation hidden from the response stays unfilterable.
        if ( !isAuthorized( $edgeConfig , $auth ) )
        {
            return Boolean::FALSE ;
        }

        $edges = getEdges($edgeConfig[ AQL::MODEL ] ?? null , $this->container ) ;
        if ( !( $edges instanceof Edges ) )
        {
            $pathStr = implode( '.' , $segmentInfo->path ) ;
            throw new RuntimeException( "Invalid edge model '$relationRef' at path: $pathStr" ) ;
        }

        $edgeCollection = $edges->collection;
        $direction      = resolveEdgeDirection( $edgeConfig ) ;
        $vertexID       = uniqid( 'v_' ) ;

        // Get the target model to extract its edges/joins for next level
        $targetModel = resolveEdgeTarget( $edges , $direction ) ;
        $nextLevel   = extractNestedRelations
        (
            config      : $edgeConfig   ,
            targetModel : $targetModel  ,
        ) ;

        // The `quant` quantifier shapes the existence check: any (> 0, default),
        // none (== 0), or « at least n » (>= n, counted without LIMIT).
        $quantifier = resolveTraversalQuantifier( $init[ FilterParam::QUANT ] ?? null ) ;

        // Build the leaf condition for the remaining path, if any. With no
        // remaining segment the traversal is a pure existence/absence check
        // (no FILTER clause on the vertex).
        $innerCondition = null ;

        if ( !empty( $remainingSegments ) )
        {
            $innerCondition = $this->buildFilterRecursive
            (
                segments      : $remainingSegments                ,
                filters       : $segmentInfo->nestedFilters ?? [] ,
                init          : $init                             ,
                binds         : $binds                            ,
                docRef        : $vertexID                         ,
                parentPath    : $segmentInfo->path                ,
                currentEdges  : $nextLevel[ AQL::EDGES ]          ,
                currentJoins  : $nextLevel[ AQL::JOINS ]          ,
                auth          : $auth                             ,
                currentFields : $targetModel?->fields            , // switch to the target model's projection
                fieldPath     : []                               , // relative path resets across the relation
            );

            // Permission gate (Option B): a leaf refused inside the traversal
            // neutralises the WHOLE traversal to `false` — returned BEFORE the
            // quantifier negation below, so a refused leaf under `all`/`none` can
            // never become `NOT(false) = true` (an existence oracle).
            if ( $innerCondition === Boolean::FALSE )
            {
                return Boolean::FALSE ;
            }

            if ( !$innerCondition )
            {
                $pathStr = implode( '.' , $segmentInfo->path ) ;
                $this->logger->warning( "Failed to build inner condition for edge at path: $pathStr" ) ;
                return null ;
            }
        }

        // `all` → keep documents whose every linked vertex satisfies the leaf,
        // i.e. none violates it: negate the leaf and require zero matches. A leaf
        // condition is mandatory — there is nothing to satisfy otherwise.
        if ( $quantifier->negate )
        {
            if ( $innerCondition === null )
            {
                $pathStr = implode( '.' , $segmentInfo->path ) ;
                throw new RequestValidationException
                (
                    "The 'all' quantifier requires a condition to satisfy at path: $pathStr. " .
                    "Use 'none' to match documents with no related match."
                ) ;
            }

            $innerCondition = logicalNot( $innerCondition , true ) ;
        }

        $filter = $innerCondition !== null ? aqlFilter( [ $innerCondition ] ) : '' ;
        $limit  = $quantifier->useLimit    ? aqlLimit ( limit : 1 )           : '' ;

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
                $filter ,
                $limit  ,
                aqlReturn ( expression : 1 )
            ])),
            operator     : $quantifier->comparator ,
            rightOperand : $quantifier->threshold
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
     * @param array $availableJoins The joins definitions in scope at this level.
     * @param array $auth The caller's permission context. The relation itself is gated here;
     *                    the target model's own projection then gates the leaf below.
     *
     * @return string|null The AQL condition for the join traversal, `false` when the relation is
     *                     refused, or `null` when the inner condition could not be resolved.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws RequestValidationException When the request itself is refused — an unusable
     *                                    `quant`, or an operator this leaf cannot honour.
     * @throws RuntimeException When the join, its model or its collection cannot be resolved.
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildJoinTraversal
    (
        array      $remainingSegments ,
        FilterPath $segmentInfo       ,
        array      $init              ,
        array      &$binds            ,
        string     $docRef            ,
        array      $availableJoins    = [] ,
        array      $auth              = [] ,
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

        // Permission gate (relation level): a join locked at its definition
        // (AQL::REQUIRES) cannot be filtered through — neutralised to `false`.
        if ( !isAuthorized( $joinConfig , $auth ) )
        {
            return Boolean::FALSE ;
        }

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

        // The `quant` quantifier shapes the existence check: any (> 0, default),
        // none (== 0), or « at least n » (>= n, counted without LIMIT).
        $quantifier = resolveTraversalQuantifier( $init[ FilterParam::QUANT ] ?? null ) ;

        // Build the leaf condition for the remaining path, if any. With no
        // remaining segment the join is a pure existence/absence check — only
        // the structural key condition constrains the joined document.
        $innerCondition = null ;

        if ( !empty( $remainingSegments ) )
        {
            $innerCondition = $this->buildFilterRecursive
            (
                segments      : $remainingSegments                ,
                filters       : $segmentInfo->nestedFilters ?? [] ,
                init          : $init                             ,
                binds         : $binds                            ,
                docRef        : $joinDocRef                       ,
                parentPath    : $segmentInfo->path                ,
                currentEdges  : $nextLevel[ AQL::EDGES ]          ,
                currentJoins  : $nextLevel[ AQL::JOINS ]          ,
                auth          : $auth                             ,
                currentFields : $documents->fields               , // switch to the joined model's projection
                fieldPath     : []                               , // relative path resets across the relation
            );

            // Permission gate (Option B): a leaf refused inside the join
            // neutralises the WHOLE join to `false` — returned BEFORE the quantifier
            // negation below (same rationale as the edge traversal).
            if ( $innerCondition === Boolean::FALSE )
            {
                return Boolean::FALSE ;
            }

            if ( !$innerCondition )
            {
                return null;
            }
        }

        // `all` → keep documents whose every joined match satisfies the leaf,
        // i.e. none violates it: negate the leaf (the structural key condition
        // stays positive). A leaf condition is mandatory.
        if ( $quantifier->negate )
        {
            if ( $innerCondition === null )
            {
                $pathStr = implode( '.' , $segmentInfo->path ) ;
                throw new RequestValidationException
                (
                    "The 'all' quantifier requires a condition to satisfy at path: $pathStr. " .
                    "Use 'none' to match documents with no related match."
                ) ;
            }

            $innerCondition = logicalNot( $innerCondition , true ) ;
        }

        $keyCondition = predicate( key( $joinKey , $joinDocRef ) , Comparator::EQUAL , $sourceKey ) ;
        $conditions   = $innerCondition !== null ? [ $keyCondition , $innerCondition ] : [ $keyCondition ] ;
        $limit        = $quantifier->useLimit ? aqlLimit( limit : 1 ) : '' ;

        return betweenParentheses( predicate
        (
            leftOperand : length(compile
            ([
                aqlFor
                ([
                    AQL::DOC_REF => $joinDocRef,
                    AQL::IN      => aqlBindCollection( $collection , $binds )
                ]),
                aqlFilter ( $conditions ) ,
                $limit ,
                aqlReturn ( expression : 1 )
            ])),
            operator     : $quantifier->comparator ,
            rightOperand : $quantifier->threshold
        ));
    }

    /**
     * Build the leaf condition by delegating to the flat filter helpers.
     *
     * The last segment of the path names an actual field: its declared type selects the
     * helper that knows how to compare it, and a custom callable is honoured as-is.
     *
     * @param FilterPath $segmentInfo   The parsed leaf segment, carrying its declared type and
     *                                  its full path.
     * @param array      $init          The original filter parameters.
     * @param array      &$binds        The bind variables, populated by reference.
     * @param string     $docRef        The document reference the leaf belongs to.
     * @param array|null $currentFields The projection of the model holding the leaf.
     * @param array      $fieldPath     The path relative to `$currentFields`, completed here
     *                                  with the leaf key to form the gated path.
     * @param array      $auth          The caller's permission context.
     *
     * @return string|null The AQL condition, `false` when the field is refused by the permission
     *                     gate, or `null` when no handler matches the declared type.
     *
     * @throws RequestValidationException When the caller's own request is refused — the refusal
     *                                    is relayed to them, never swallowed into a dropped
     *                                    filter.
     */
    private function buildLeafCondition
    (
        FilterPath $segmentInfo         ,
        array      $init                ,
        array      &$binds              ,
        string     $docRef              ,
        ?array     $currentFields = null ,
        array      $fieldPath     = []   ,
        array      $auth          = []   ,
    )
    : ?string
    {
        // Get the current segment key (last element of path)
        $fieldKey = end( $segmentInfo->path ) ;

        // Remove array notation if present (e.g., "email[*]" -> "email")
        $fieldKey = str_replace( Operator::ARRAY_EXPANSION , '' , $fieldKey ) ;

        // Permission gate (Option B): the leaf inherits the Field::REQUIRES of the
        // exact sub-field in the CURRENT model's projection (the target model when a
        // relation was crossed). A refused leaf is neutralised to `false` — never
        // dropped — and, through the edge/join short-circuit, sinks the whole
        // traversal. The relative path (reset across each relation) lets a locked
        // sub-field be seen in depth (`address.city`, `employee[*].salary`).
        $relativePath = implode( Char::DOT , [ ...$fieldPath , $fieldKey ] ) ;

        if ( !isPathAuthorized( $relativePath , $currentFields , $auth ) )
        {
            return Boolean::FALSE ;
        }

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
        catch ( RequestValidationException $e )
        {
            // 🚨 A refusal addressed to the CALLER, not a failure to build.
            //
            // The catch below turns anything escaping a leaf into `null`, and a `null`
            // leaf drops the whole filter: the query leaves without it and the surface
            // answers the entire collection, in `200`. That is the right treatment for
            // a consumer's broken callable — no URL will ever fix it — and the exact
            // wrong one for a mistyped operator, which answered `400 Bad Request` at
            // the root and vanished in depth:
            //
            //   {"key":"name",         "op":"zzz"}  ->  400, naming the accepted codes
            //   {"key":"address.city", "op":"zzz"}  ->  the whole collection
            //
            // Same mistake, opposite answers, told apart by nothing but a dot. The
            // refusal is relayed so both depths answer alike.
            throw $e ;
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