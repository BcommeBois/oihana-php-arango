<?php

namespace oihana\arango\models\helpers;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\exceptions\BindException;
use oihana\reflect\exceptions\ConstantException;

use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\buildEdgeCountVariable;
use function oihana\arango\models\helpers\edges\buildEdgeVariable;
use function oihana\arango\models\helpers\joins\buildJoinVariable;
use function oihana\core\strings\key;

/**
 * Builds all fields variables definitions (edges, joins, etc.).
 *
 * Field-level gating: when a field declares `Field::REQUIRES` and the
 * request-scoped authorizer denies it, the matching `LET` variable is
 * **not** emitted. Combined with the symmetric drop in `aqlFields()`,
 * the field disappears from both the AQL projection and the response
 * — no key in the JSON, no orphan reference in the AQL.
 *
 * The check is uniform: edges, joins, and edge-counts all share the
 * same gating contract. A `Filter::EDGES_COUNT` companion is gated
 * exactly the same way as the others — it is simply not gated by
 * default because no `Field::REQUIRES` is declared on it. To gate
 * the count, declare `Field::REQUIRES` on the count entry itself.
 *
 * @param array                   $variables
 * @param array                   $fields
 * @param ?array                  $edges
 * @param ?array                  $joins
 * @param ContainerInterface|null $container
 * @param string                  $docRef
 * @param array                   $init
 *
 * @return void
 *
 * @throws BindException
 * @throws ConstantException
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws ReflectionException
 */
function buildVariables
(
    array               &$variables = [] ,
    array               $fields     = [] ,
    ?array              $edges      = [] ,
    ?array              $joins      = [] ,
    ?ContainerInterface $container  = null ,
    string              $docRef     = AQL::DOC ,
    array               $init       = []
)
: void
{
    if( empty( $fields ) )
    {
        return ;
    }

    foreach( $fields as $key => $field )
    {
        $filter = $field[ Field::FILTER ] ?? null ;
        $unique = $field[ Field::UNIQUE ] ?? null ;

        switch( $filter )
        {
            case Filter::WRAP :
            {
                // A wrapped reference can carry its own relations : the sub-edges
                // declared under Field::EDGES start from the wrapped vertex (the
                // current $docRef) and nest under the wrapped key. We simply recurse
                // with the wrapped fields + their edges map and the SAME $docRef as
                // traversal root, so the whole top-level edge machinery (EDGE /
                // EDGES / EDGES_COUNT, gating, sub-projections, …) applies verbatim
                // and the matching `LET` lands in the enclosing FOR scope. Field::RAW
                // embeds the whole reference as-is (key: ref) — no place to graft a
                // sub-edge — so it is skipped here (aqlFieldWrap rejects RAW + EDGES).
                if ( ( $field[ Field::RAW ] ?? false ) === true )
                {
                    break ;
                }

                $subFields = $field[ Field::FIELDS ] ?? [] ;
                $subEdges  = $field[ Field::EDGES  ] ?? [] ;

                if ( !empty( $subFields ) && !empty( $subEdges ) )
                {
                    buildVariables( $variables , $subFields , $subEdges , [] , $container , $docRef , $init ) ;
                }
                break ;
            }

            case Filter::DOCUMENT :
            {
                $subFields = $field[ Field::FIELDS ] ?? [] ;
                $subEdges  = $field[ Field::EDGES  ] ?? [] ;
                $subJoins  = $field[ Field::JOINS  ] ?? [] ;

                if ( !empty( $subFields ) )
                {
                    buildVariables
                    (
                        $variables ,
                        $subFields ,
                        $subEdges ,
                        $subJoins ,
                        $container ,
                        key( $field[ Field::NAME ] ?? $key , $docRef ) ,
                        $init
                    ) ;
                }
                break ;
            }

            case Filter::EDGES       :
            case Filter::EDGE        :
            case Filter::EDGES_COUNT :
            {
                $definition = $edges[ $key ] ?? null ;
                if( is_string( $definition ) )
                {
                    // shortcut reference -> use an other edges definition
                    $definition = $edges[ $definition ] ?? null ;
                }

                if( !isset( $definition ) )
                {
                    break ;
                }

                // Field-level gating: the check is driven by the field
                // definition itself (the FIELDS entry we are iterating),
                // not by the edge definition. This is uniform across
                // edges, joins and counts — gating is opt-in via
                // `Field::REQUIRES` on the field, regardless of the
                // filter type. A `Filter::EDGES_COUNT` is therefore
                // gated only when the count entry explicitly declares
                // `Field::REQUIRES`; without it, the count is always
                // visible (default behavior, since knowing the
                // cardinality is rarely a leak).
                if( !isAuthorized( $field , $init ) )
                {
                    break ;
                }

                $definition[ Field::UNIQUE ] = $unique ;

                $property = $field[ Field::PROPERTY ] ?? null ;
                if( isset( $property ) )
                {
                    $definition[ Field::PROPERTY ] = $property ; // special property case
                }

                $variables[] = $filter == Filter::EDGES_COUNT
                    ? buildEdgeCountVariable ( $key , $definition , $docRef , $container )
                    : buildEdgeVariable      ( $key , $definition , $docRef , $container , $init ) ;
                break ;
            }

            case Filter::JOIN :
            case Filter::JOINS :
            // case Filter::JOINS_COUNT :
            {
                $definition = $joins[ $key ] ?? null ;

                if( is_string( $definition ) )
                {
                    // shortcut reference -> use an other joins definition
                    $definition = $joins[ $definition ] ?? null ;
                }

                if( !isset( $definition ) )
                {
                    break ;
                }

                // Same gating contract as the edge branch above: the
                // check reads `Field::REQUIRES` on the field definition,
                // not on the join definition. A denied projection skips
                // both the `LET` emission and the matching key in
                // `aqlFields`, so the join is fully dropped from the
                // response (key absent — not null, not empty array).
                if( !isAuthorized( $field , $init ) )
                {
                    break ;
                }

                $definition[ Field::UNIQUE ] = $unique ;

                $variables[] = buildJoinVariable
                (
                    $key ,
                    $definition ,
                    $docRef ,
                    $container ,
                    $init ,
                    $filter == Filter::JOINS
                ) ;
                break ;
            }
        }
    }
}
