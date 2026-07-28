<?php

namespace oihana\arango\models\helpers\edges;

use Exception;
use ReflectionException;
use UnexpectedValueException;

use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

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
use function oihana\arango\db\helpers\fields\buildWhenCondition;
use function oihana\arango\db\helpers\resolveSkinFields;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\arango\db\operators\logicalNot;
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
 * A definition may also declare `AQL::WHERE` — a condition in the `Field::WHEN`
 * grammar, compiled against the **traversed vertex** and appended to
 * `$extraConditions`. It restricts WHICH vertices the relation projects, wherever
 * the definition is used, so a consumer masking part of a collection is not
 * contradicted by the relation of a served document:
 * ```
 * ( FOR vertex, edge IN OUTBOUND doc coll FILTER vertex.id NOT IN @hiddenTerms … )
 * ```
 * Its value may hold an `aqlBindRef()`, so the retained set is decided at query
 * time; the contract is the one `Field::WHERE` already carries on a `Filter::MAP`
 * (a bind bound to `[]` retains nothing, an absent bind fails the query — never
 * "no filter"). It **composes** with the polymorphic guard rather than replacing
 * it, and is orthogonal to `AQL::REQUIRES` / `Field::REQUIRES`, which decide
 * whether the relation is projected at all.
 *
 * `AQL::WHERE` filters the traversal's OUTPUT; on a ranged relation
 * (`AQL::MAX_DEPTH`) the walk still descends through a masked vertex, so its
 * descendants keep being projected. `AQL::PRUNE` stops the walk itself — `true`
 * reuses the `AQL::WHERE` predicate negated ("hide it and its descent"), or a
 * condition of its own when stopping is not hiding. The two are emitted together
 * because `PRUNE` stops *after* visiting: the vertex it stops on is still returned
 * unless the `FILTER` removes it.
 *
 * @param string|null            $name            The logical name of the relation (used to skin the projection).
 * @param array                  $definition      Configuration array for the traversal — same keys as
 *                                                {@see buildEdgeVariable()} (`AQL::MODEL`, `AQL::DIRECTION`,
 *                                                `AQL::EDGES`, `AQL::JOINS`, `AQL::SKIN`, `AQL::MAX_DEPTH`,
 *                                                `AQL::WITH_PATH`, `AQL::WHERE`, `AQL::PRUNE`,
 *                                                `Arango::PROPERTY`, …).
 * @param string                 $startVertex     The AQL variable name of the starting vertex (default 'doc').
 * @param ContainerInterface|null $container      The DI container used to resolve models.
 * @param array                  $init            Optional associative array used for variable initialization.
 * @param array                  $extraConditions Ready-made AQL predicate strings emitted as a `FILTER` after
 *                                                the traversal (e.g. the discriminator guard of a polymorphic
 *                                                edge). Empty → no `FILTER` emitted (byte-identical output).
 *
 * @return string The parenthesized traversal sub-query (no leading `LET name =`).
 *
 * @throws Exception                     If the traversal direction is invalid.
 * @throws ContainerExceptionInterface   If the Edges model cannot be resolved from the container.
 * @throws NotFoundExceptionInterface    If the Edges model cannot be resolved from the container.
 * @throws ReflectionException
 * @throws UnexpectedValueException      If $name is empty, the model is invalid, the collection is not set,
 *                                       or `AQL::PRUNE => true` has no `AQL::WHERE` condition to negate.
 * @throws UnsupportedOperationException If an `AQL::WHERE` / `AQL::PRUNE` condition descriptor is malformed.
 * @throws ValidationException           If an `AQL::WHERE` / `AQL::PRUNE` condition attribute name is unsafe.
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
    $skin = $definition[ AQL::SKIN   ] ?? $init[ AQL::SKIN ] ?? null ;

    // AQL::SKIN_FIELDS lets a definition declare distinct projections per
    // skin in a single place, e.g. role() flat for Skin::DEFAULT and a rich
    // role([...]) for Skin::FULL. Falls back on the '*' bucket then on the
    // legacy AQL::FIELDS shape — fully retro-compatible.
    $fields = resolveSkinFields( $definition , $skin ) ;

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

    // AQL::WHERE restricts WHICH traversed vertices are projected. It reuses the
    // Field::WHEN condition grammar (buildWhenCondition), compiled against the
    // traversed VERTEX — not the start vertex — and a condition value may be an
    // AqlBindReference (aqlBindRef) so the retained set is decided by a bind
    // supplied at query time: an absent bind fails the query (fail-closed), never
    // silently widens it.
    $where          = $definition[ AQL::WHERE ] ?? null ;
    $whereCondition = $where !== null ? buildWhenCondition( $where , $vertexRef ) : null ;

    // AQL::PRUNE stops the WALK, where AQL::WHERE only filters its OUTPUT. The
    // distinction only shows on a ranged traversal (AQL::MAX_DEPTH): a `FILTER`
    // removes a masked vertex from the result, but the walk still descends THROUGH
    // it, so its own descendants keep being projected. Measured live on
    // `a → b(masked) → c` plus `a → e → f`: the FILTER alone yields `c, e, f` —
    // `c` leaks — while PRUNE cuts that branch and yields `e, f`.
    //
    // The two work TOGETHER and neither replaces the other: PRUNE stops the walk
    // *after* visiting the vertex, so the vertex it stops on is still returned
    // unless the FILTER removes it.
    //
    // - `true`      → reuse the AQL::WHERE predicate, negated. The common intent
    //                 ("hide it AND its descent"), and impossible to desynchronize
    //                 from the filter since there is a single declaration.
    // - a condition → its own stop condition, in the same grammar, for when
    //                 "stop descending" is not "hide". Accepted rather than treated
    //                 as truthy, which would silently ignore what was written.
    $prune = $definition[ AQL::PRUNE ] ?? null ;

    if ( $prune === true )
    {
        if ( $whereCondition === null )
        {
            throw new UnexpectedValueException
            (
                __FUNCTION__ . ' failed, AQL::PRUNE => true has no AQL::WHERE condition to negate.'
            ) ;
        }

        $prune = logicalNot( $whereCondition , true ) ;
    }
    else if ( $prune !== null )
    {
        $prune = buildWhenCondition( $prune , $vertexRef ) ;
    }

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
        AQL::PRUNE           => $prune ,
        AQL::OPTIONS         =>
        [
            TraversalOption::ORDER           => TraversalOrder::BFS ,
            TraversalOption::UNIQUE_VERTICES => TraversalUniqueVertices::GLOBAL ,
        ]
    ]) ;

    // The AQL::WHERE predicate is APPENDED to the injected ones, so a polymorphic
    // branch guard keeps its head position and the two cumulate instead of
    // replacing one another.
    if ( $whereCondition !== null )
    {
        $extraConditions = [ ...$extraConditions , $whereCondition ] ;
    }

    // The discriminator guard, the AQL::WHERE predicate (and any other injected
    // condition) are emitted as a single FILTER right after the traversal. Empty →
    // no FILTER, so the output stays byte-for-byte identical to the legacy edge
    // sub-query.
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
