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
use oihana\arango\enums\Arango;
use oihana\arango\models\Edges;

use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\db\helpers\resolveSkinFields;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\arango\models\helpers\buildVariables;
use function oihana\core\strings\betweenBraces;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\key;
use function oihana\core\strings\randomKey;

/**
 * Builds a single AQL 'LET' subquery string for a specific edge relation.
 *
 * This method generates a complete traversal subquery, enclosed in parentheses,
 * which is assigned to a 'LET' variable. It handles direction, filtering,
 * sorting, and shaping of the results.
 *
 * Example output:
 * `LET myFriends = ( FOR v, e IN OUTBOUND 'users/123' friends_edge ... RETURN v.name )`
 *
 * @param string|null $name The logical name for this variable (e.g., 'friends', 'comments').
 *                          This is used as the AQL 'LET' variable name.
 *
 * @param array $definition   Configuration array for the traversal. Expected keys:
 * - `AQL::MODEL`: (string) The class name of the Edges model.
 * - `AQL::DIRECTION`: (string|null) Traversal direction (OUTBOUND, INBOUND).
 * - `AQL::UNIQUE`: (string|null) Optional AQL variable name, overrides $name.
 * - `AQL::EDGES`: (array) Further edge definitions for nested queries.
 * - `AQL::JOINS`: (array) Join definitions for the target model.
 * - `AQL::SKIN`: (string|null) A 'skin' name to select specific fields.
 * - `AQL::SORT`: (string|array|null) Sort definition (see getSortEdgeVariableExpression).
 *
 * @param string              $startVertex  The AQL variable name of the starting vertex (default 'doc').
 * @param ?ContainerInterface $container    The DI Container reference.
 * @param array               $init         Optional associative array definitions.
 *
 * @return string The complete AQL 'LET' statement.
 *
 * @throws Exception                   If Traversal direction is invalid.
 * @throws ContainerExceptionInterface If the Edges model cannot be resolved from the container.
 * @throws NotFoundExceptionInterface  If the Edges model cannot be resolved from the container.
 * @throws ReflectionException
 * @throws UnexpectedValueException    If $name is empty, the model is invalid, or the collection is not set.
 */
function buildEdgeVariable
(
    ?string             $name        ,
    array               $definition  = [] ,
    string              $startVertex = AQL::DOC ,
    ?ContainerInterface $container   = null ,
    array               $init        = []
)
: string
{
    if( empty( $name ) )
    {
        throw new UnexpectedValueException( __METHOD__ . ' failed, the name of the edge variable not must be null or empty.' ) ;
    }

    $model = getEdges( $definition[ AQL::MODEL ] ?? null , $container ) ;
    if( !( $model instanceof Edges ) )
    {
        throw new UnexpectedValueException( __METHOD__ . ' failed, the edges model reference must be an instance of Edges.' ) ;
    }

    $edgeCollection = $model->collection ;
    if( empty( $edgeCollection ) )
    {
        throw new UnexpectedValueException( __METHOD__ . ' failed, the edge collection not must be null or empty.' ) ;
    }

    $direction = Traversal::get( $definition[ AQL::DIRECTION ] ?? null , Traversal::OUTBOUND ) ;
    $documents = $direction == Traversal::INBOUND ? $model->from : $model->to ;

    $edgeRef   = randomKey( AQL::EDGE   );
    $vertexRef = randomKey( AQL::VERTEX );

    $definitionEdges = $definition[ Arango::EDGES  ] ?? [] ;
    $definitionJoins = $definition[ Arango::JOINS  ] ?? [] ;
    // The edge def can pin a fixed skin (e.g. Skin::MAIN to break a cycle) ;
    // otherwise we fall back on the request-level skin propagated through
    // $init so a sub-edge projection can vary with `?skin=...` (the
    // sub-fields opt in via Field::SKINS markers).
    $skin            = $definition[ Arango::SKIN   ] ?? $init[ Arango::SKIN ] ?? null ;
    // AQL::SKIN_FIELDS lets a definition declare distinct projections per
    // skin in a single place, e.g. role() flat for Skin::DEFAULT and a rich
    // role([...]) for Skin::FULL. Falls back on the '*' bucket then on the
    // legacy AQL::FIELDS shape — fully retro-compatible.
    $fields          = resolveSkinFields( $definition , $skin ) ;
    $varName         = $definition[ Arango::UNIQUE ] ?? $name ;

    $subVariables = [] ;

    $for = aqlTraversal
    ([
        AQL::VERTEX_REF      => $vertexRef   ,
        AQL::EDGE_REF        => $edgeRef     ,
        AQL::DIRECTION       => $direction   ,
        AQL::START_VERTEX    => $startVertex ,
        AQL::EDGE_COLLECTION => $edgeCollection  ,
        AQL::OPTIONS         =>
        [
            TraversalOption::ORDER           => TraversalOrder::BFS ,
            TraversalOption::UNIQUE_VERTICES => TraversalUniqueVertices::GLOBAL ,
        ]
    ]) ;

    $sort = sortEdgeVariable( $definition , $vertexRef , $edgeRef ) ;

    $property = $definition[ Arango::PROPERTY ] ?? null ;

    if( $property !== null )
    {
        $return = aqlReturn( key( $property , $vertexRef ) ) ;
    }
    else
    {
        $fields = $documents->prepareQueryFields( $fields , $skin , $name ) ;

        if( is_array( $fields ) && count( $fields ) > 0 )
        {
            $targetEdges = !empty( $definitionEdges ) ? $definitionEdges : ( $documents->edges ?? [] );
            $targetJoins = !empty( $definitionJoins ) ? $definitionJoins : ( $documents->joins ?? [] );

            buildVariables( $subVariables , $fields , $targetEdges , $targetJoins , $container , $vertexRef , $init ) ;

            $return = aqlReturn( betweenBraces( aqlFields( $fields , $vertexRef , $container , $init ) ) ) ;
        }
        else
        {
            $return = aqlReturn( $vertexRef ) ;
        }
    }


    // let $varName = ( FOR $vertex, $edge IN OUTBOUND|INBOUND $startVertex [...variables] $edgeCollection [SORT ...] RETURN $vertex|$variables )
    return aqlLet( $varName , betweenParentheses( [ $for , $subVariables , $sort  , $return ] ) );
}