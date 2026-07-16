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
use function oihana\arango\models\helpers\edges\buildPolymorphicEdgeVariable;
use function oihana\arango\models\helpers\joins\buildJoinVariable;
use function oihana\arango\models\helpers\joins\buildPolymorphicJoinVariable;
use function oihana\core\strings\key;

/**
 * Builds all fields variables definitions (edges, joins, etc.).
 *
 * Permission gating happens at TWO composable levels — either one can
 * drop the relation (logical AND across levels, logical OR inside a
 * declared subjects list):
 *
 * - **Field-level** — the FIELDS entry (the relation marker) declares
 *   `Field::REQUIRES`: gates this projection of the relation in this
 *   parent. Combined with the symmetric drop in `aqlFields()`, the
 *   field disappears from both the AQL projection and the response —
 *   no key in the JSON, no orphan reference in the AQL.
 * - **Definition-level** — the edge/join definition itself declares
 *   `AQL::REQUIRES`: gates the relation wherever the definition is
 *   used. The symmetric field-side drop is handled upstream by
 *   {@see authorizeRelationFields()} at every point where a prepared
 *   fields array meets its registries.
 *
 * The check is uniform: edges, joins, and edge-counts all share the
 * same gating contract. A `Filter::EDGES_COUNT` companion is gated
 * exactly the same way as the others — it is simply not gated by
 * default because no `Field::REQUIRES` is declared on it. To gate
 * the count, declare `Field::REQUIRES` on the count entry itself
 * (or `AQL::REQUIRES` on the shared definition to gate every usage).
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
                // A wrapped reference can carry its own relations : the sub-edges /
                // sub-joins declared under Field::EDGES / Field::JOINS start from the
                // wrapped vertex (the current $docRef) and nest under the wrapped key.
                // We simply recurse with the wrapped fields + their edges/joins maps
                // and the SAME $docRef as traversal root, so the whole top-level
                // relation machinery (EDGE / EDGES / EDGES_COUNT / JOIN / JOINS,
                // gating, sub-projections, …) applies verbatim and the matching `LET`
                // lands in the enclosing FOR scope. Field::RAW embeds the whole
                // reference as-is (key: ref) — no place to graft a relation — so it is
                // skipped here (aqlFieldWrap rejects RAW + EDGES/JOINS).
                if ( ( $field[ Field::RAW ] ?? false ) === true )
                {
                    break ;
                }

                $subFields = $field[ Field::FIELDS ] ?? [] ;
                $subEdges  = $field[ Field::EDGES  ] ?? [] ;
                $subJoins  = $field[ Field::JOINS  ] ?? [] ;

                if ( !empty( $subFields ) && ( !empty( $subEdges ) || !empty( $subJoins ) ) )
                {
                    buildVariables( $variables , $subFields , $subEdges , $subJoins , $container , $docRef , $init ) ;
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
                $definition = prepareRelationDefinition( $edges , $key , $field , $unique , $init ) ;
                if( $definition === null )
                {
                    break ;
                }

                $property = $field[ Field::PROPERTY ] ?? null ;
                if( isset( $property ) )
                {
                    $definition[ Field::PROPERTY ] = $property ; // special property case
                }

                // A polymorphic edge (Arango::MAP + Arango::DISCRIMINATOR) picks
                // its traversed collection from a discriminator field of the start
                // vertex, so it routes to a dedicated builder; a regular edge keeps
                // its single fixed collection. EDGES_COUNT stays on the count
                // builder (polymorphic count is not supported yet).
                $variables[] = $filter == Filter::EDGES_COUNT
                             ? buildEdgeCountVariable( $key , $definition , $docRef , $container )
                             : ( isPolymorphic( $definition )
                                 ? buildPolymorphicEdgeVariable( $key , $definition , $docRef , $container , $init )
                                 : buildEdgeVariable( $key , $definition , $docRef , $container , $init ) ) ;
                break ;
            }

            case Filter::JOIN :
            case Filter::JOINS :
            // case Filter::JOINS_COUNT :
            {
                $definition = prepareRelationDefinition( $joins , $key , $field , $unique , $init ) ;
                if( $definition === null )
                {
                    break ;
                }

                // A polymorphic join (Arango::MAP + Arango::DISCRIMINATOR) picks
                // its target collection from a discriminator field of the parent,
                // so it routes to a dedicated builder; a regular join keeps its
                // single fixed collection.
                $variables[] = isPolymorphic( $definition )
                             ? buildPolymorphicJoinVariable( $key , $definition , $docRef , $container , $init , $filter == Filter::JOINS )
                             : buildJoinVariable           ( $key , $definition , $docRef , $container , $init , $filter == Filter::JOINS ) ;
                break ;
            }
        }
    }
}
