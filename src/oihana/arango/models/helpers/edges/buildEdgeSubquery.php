<?php

namespace oihana\arango\models\helpers\edges;

use Exception;
use ReflectionException;
use UnexpectedValueException;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\options\TraversalOption;
use oihana\arango\db\enums\options\TraversalOrder;
use oihana\arango\db\enums\options\TraversalUniqueVertices;
use oihana\arango\db\enums\Traversal;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\functions\documents\merge;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\db\helpers\resolveSkinFields;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\arango\models\helpers\authorizeRelationFields;
use function oihana\arango\models\helpers\authorizeTargetFields;
use function oihana\arango\models\helpers\buildVariables;
use function oihana\core\strings\betweenBraces;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;
use function oihana\core\strings\randomKey;

/**
 * Builds the inner AQL edge traversal sub-query — everything an edge `LET`
 * wraps, already enclosed in parentheses but WITHOUT the leading `LET name =`.
 *
 * The returned string is the parenthesized traversal:
 * ```
 * ( FOR vertex, edge IN OUTBOUND doc edge_collection [<nested LETs>] [FILTER …] [SORT …] RETURN … )
 * ```
 * {@see buildEdgeVariable()} prefixes it with `LET name = ` for a regular edge,
 * while {@see buildPolymorphicEdgeVariable()} wraps several such sub-queries into
 * a single `APPEND( ( … ) , ( … ) )` array so the traversed edge collection can
 * vary with a discriminator field of the start vertex.
 *
 * Extracting this body from {@see buildEdgeVariable()} lets a polymorphic edge
 * reuse the whole traversal machinery (direction, depth, path metadata, skinning,
 * nested edges / joins, definition-level gating) per branch. The only addition
 * over the historical logic is `$extraConditions`: a list of ready-made AQL
 * predicates (typically the discriminator guard on the start vertex) emitted as a
 * `FILTER` right after the traversal. When empty, **no** `FILTER` is emitted, so
 * the output is byte-for-byte identical to the legacy edge sub-query.
 *
 * @param string|null            $name            The logical name of the relation (used to skin the projection).
 * @param array                  $definition      Configuration array for the traversal — same keys as
 *                                                {@see buildEdgeVariable()} (`AQL::MODEL`, `AQL::DIRECTION`,
 *                                                `AQL::EDGES`, `AQL::JOINS`, `AQL::SKIN`, `AQL::MAX_DEPTH`,
 *                                                `AQL::WITH_PATH`, `Arango::PROPERTY`, …).
 * @param string                 $startVertex     The AQL variable name of the starting vertex (default 'doc').
 * @param ContainerInterface|null $container      The DI container used to resolve models.
 * @param array                  $init            Optional associative array used for variable initialization.
 * @param array                  $extraConditions Ready-made AQL predicate strings emitted as a `FILTER` after
 *                                                the traversal (e.g. the discriminator guard of a polymorphic
 *                                                edge). Empty → no `FILTER` emitted (byte-identical output).
 *
 * @return string The parenthesized traversal sub-query (no leading `LET name =`).
 *
 * @throws Exception                   If the traversal direction is invalid.
 * @throws ContainerExceptionInterface If the Edges model cannot be resolved from the container.
 * @throws NotFoundExceptionInterface  If the Edges model cannot be resolved from the container.
 * @throws ReflectionException
 * @throws UnexpectedValueException    If $name is empty, the model is invalid, or the collection is not set.
 */
function buildEdgeSubquery
(
    ?string             $name            ,
    array               $definition      = [] ,
    string              $startVertex     = AQL::DOC ,
    ?ContainerInterface $container       = null ,
    array               $init            = [] ,
    array               $extraConditions = [] ,
)
: string
{
    if( empty( $name ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the name of the edge variable not must be null or empty.' ) ;
    }

    [ $model , $edgeCollection , $direction ] = resolveEdgeContext( $definition , $container ) ;

    $documents = $direction == Traversal::INBOUND ? $model->from : $model->to ;

    $edgeRef   = randomKey( AQL::EDGE   );
    $vertexRef = randomKey( AQL::VERTEX );

    $definitionEdges = $definition[ AQL::EDGES  ] ?? [] ;
    $definitionJoins = $definition[ AQL::JOINS  ] ?? [] ;
    // The edge def can pin a fixed skin (e.g. Skin::MAIN to break a cycle) ;
    // otherwise we fall back on the request-level skin propagated through
    // $init so a sub-edge projection can vary with `?skin=...` (the
    // sub-fields opt in via Field::SKINS markers).
    $skin            = $definition[ AQL::SKIN   ] ?? $init[ AQL::SKIN ] ?? null ;
    // AQL::SKIN_FIELDS lets a definition declare distinct projections per
    // skin in a single place, e.g. role() flat for Skin::DEFAULT and a rich
    // role([...]) for Skin::FULL. Falls back on the '*' bucket then on the
    // legacy AQL::FIELDS shape — fully retro-compatible.
    $fields          = resolveSkinFields( $definition , $skin ) ;

    // Depth range (hierarchies): a self-referential relation (e.g. a thesaurus)
    // can project descendants/ancestors up to AQL::MAX_DEPTH in a single traversal.
    // Absent → depth 1 (FOR v IN OUTBOUND …), strictly identical to the legacy AQL.
    // AQL::MIN_DEPTH alone is rejected: ArangoDB requires a bounded range, and an
    // unbounded traversal over a self-referential edge would risk a runaway cycle.
    // AQL::MAX_DEPTH alone defaults the lower bound to 1 (the natural "1..N").
    $minDepth = $definition[ AQL::MIN_DEPTH ] ?? null ;
    $maxDepth = $definition[ AQL::MAX_DEPTH ] ?? null ;

    if ( $minDepth !== null && $maxDepth === null )
    {
        throw new UnexpectedValueException
        (
            __FUNCTION__ . ' failed, a ranged edge projection requires AQL::MAX_DEPTH (an unbounded traversal is not allowed).'
        ) ;
    }

    if ( $maxDepth !== null && $minDepth === null )
    {
        $minDepth = 1 ;
    }

    $property = $definition[ AQL::PROPERTY ] ?? null ;

    // Path metadata (hierarchy reconstruction): AQL::WITH_PATH opts in to a `path`
    // traversal variable and injects, into the projected object, the immediate parent
    // key (AQL::_PARENT → `_parent`) and the traversal depth (AQL::_DEPTH →
    // `_depth`). buildTree() reconstructs a nested children[] tree from these. Off by
    // default → no path variable emitted, AQL unchanged. A scalar PROPERTY projection
    // carries no object, so it ignores AQL::WITH_PATH (and emits no path variable).
    $withPath = ( $definition[ AQL::WITH_PATH ] ?? false ) === true && $property === null ;
    $pathRef  = $withPath ? randomKey( AQL::PATH ) : null ;
    $pathMeta = $withPath
              ? compile(
                [
                    keyValue( AQL::_PARENT , key( Schema::_KEY , key( AQL::VERTICES . '[-2]' , $pathRef ) ) ) ,
                    keyValue( AQL::_DEPTH  , length( key( AQL::EDGES , $pathRef ) ) ) ,
                ] , ', ' )
              : null ;

    $subVariables = [] ;

    $for = aqlTraversal
    ([
        AQL::VERTEX_REF      => $vertexRef   ,
        AQL::EDGE_REF        => $edgeRef     ,
        AQL::PATH_REF        => $pathRef     ,
        AQL::DIRECTION       => $direction   ,
        AQL::START_VERTEX    => $startVertex ,
        AQL::EDGE_COLLECTION => $edgeCollection  ,
        AQL::MIN_DEPTH       => $minDepth ,
        AQL::MAX_DEPTH       => $maxDepth ,
        AQL::OPTIONS         =>
        [
            TraversalOption::ORDER           => TraversalOrder::BFS ,
            TraversalOption::UNIQUE_VERTICES => TraversalUniqueVertices::GLOBAL ,
        ]
    ]) ;

    // The discriminator guard (and any other injected predicate) is emitted as a
    // FILTER on the start vertex, right after the traversal. Empty → no FILTER, so
    // the output stays byte-for-byte identical to the legacy edge sub-query.
    $filter = $extraConditions !== [] ? aqlFilter( $extraConditions ) : null ;

    $sort = sortEdgeVariable( $definition , $vertexRef , $edgeRef ) ;

    if( $property !== null )
    {
        // Scalar projection: no object to carry the path metadata → AQL::WITH_PATH ignored.
        $return = aqlReturn( key( $property , $vertexRef ) ) ;
    }
    else
    {
        $fields = $documents->prepareQueryFields( $fields , $skin , $name ) ;

        // An ad-hoc AQL::FIELDS on the definition replaces the target's $fields, so
        // re-apply the target model's own Field::REQUIRES (T6): a field masked from
        // reading stays masked through the relation.
        $fields = authorizeTargetFields( $fields , $documents , $init ) ;

        if( is_array( $fields ) && count( $fields ) > 0 )
        {
            $targetEdges = !empty( $definitionEdges ) ? $definitionEdges : ( $documents->edges ?? [] );
            $targetJoins = !empty( $definitionJoins ) ? $definitionJoins : ( $documents->joins ?? [] );

            // Definition-level gating: purge the relation markers whose nested
            // definition is denied BEFORE the `LET` walk (buildVariables) and the
            // projection walk (aqlFields), which share this fields array.
            $fields = authorizeRelationFields( $fields , $targetEdges , $targetJoins , $init ) ;

            buildVariables( $subVariables , $fields , $targetEdges , $targetJoins , $container , $vertexRef , $init ) ;

            $object = aqlFields( $fields , $vertexRef , $container , $init , $edgeRef ) ;
            if( $pathMeta !== null )
            {
                $object = compile( [ $object , $pathMeta ] , ', ' ) ; // append _parent / _depth
            }

            $return = aqlReturn( betweenBraces( $object ) ) ;
        }
        else
        {
            // Whole vertex: graft the path metadata with MERGE so the projected
            // document gains the _parent / _depth keys.
            $return = $pathMeta !== null
                    ? aqlReturn( merge( [ $vertexRef , betweenBraces( $pathMeta ) ] ) )
                    : aqlReturn( $vertexRef ) ;
        }
    }

    // ( FOR $vertex, $edge IN OUTBOUND|INBOUND $startVertex [...variables] $edgeCollection [FILTER ...] [SORT ...] RETURN $vertex|$variables )
    return betweenParentheses( [ $for , $subVariables , $filter , $sort , $return ] ) ;
}
