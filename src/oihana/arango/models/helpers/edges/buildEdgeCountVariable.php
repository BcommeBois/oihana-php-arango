<?php

namespace oihana\arango\models\helpers\edges;

use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\BindException;
use oihana\reflect\exceptions\ConstantException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlTraversal;
use function oihana\core\strings\compile;

/**
 * Generates a string of multiple AQL 'LET' statements for calculate
 * the number of edges of a specific document.
 *
 * @param string|null $name
 * @param array $definition
 * @param string $startVertex
 * @param ContainerInterface|null $container
 *
 * @return string|null
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws ReflectionException
 * @throws BindException
 * @throws ConstantException
 */
function buildEdgeCountVariable
(
    ?string             $name        ,
    array               $definition  = [] ,
    string              $startVertex = AQL::DOC ,
    ?ContainerInterface $container   = null ,
)
:?string
{
    [ , $edgeCollection , $direction ] = resolveEdgeContext( $definition , $container ) ;

    $varName = $definition[ AQL::UNIQUE ] ?? $name ;

    // The inner count loop must NOT reuse the shared 'vertex' name: when this count
    // is projected through a vertex traversal (Edges::getVertices()), the outer loop
    // is already named 'vertex', which would trigger an "assigned multiple times" AQL
    // error. We derive a unique inner variable from $varName (itself a randomKey in
    // the live flow), keeping it deterministic so the $name of the LET never moves.
    $innerVertex = ( $varName ?: AQL::VERTEX ) . '_v' ;

    // LET $name = LENGTH( FOR <name>_v IN OUTBOUND startVertex edgeCollection RETURN <name>_v )
    $expression = length( compile(
    [
        aqlTraversal
        ([
            AQL::DIRECTION       => $direction ,
            AQL::EDGE_COLLECTION => $edgeCollection ,
            AQL::START_VERTEX    => $startVertex ,
            AQL::VERTEX_REF      => $innerVertex ,
        ]) ,
        aqlReturn ( $innerVertex )
    ])) ;

    return aqlLet( $varName , $expression , useParentheses: true ) ;
}