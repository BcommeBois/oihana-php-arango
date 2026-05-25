<?php

namespace oihana\arango\models\helpers\edges;

use ReflectionException;
use UnexpectedValueException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\models\Edges;
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
    $edges = getEdges( $definition[ AQL::MODEL ] ?? null , $container ) ;
    if( !( $edges instanceof Edges ) )
    {
        throw new UnexpectedValueException( __METHOD__ . ' failed, the edges model reference must be an instance of Edges.' ) ;
    }

    $edgeCollection = $edges->collection ;
    if( empty( $edgeCollection ) )
    {
        throw new UnexpectedValueException( __METHOD__ . ' failed, the edge collection not must be null or empty.' ) ;
    }

    $direction = Traversal::get( $definition[ AQL::DIRECTION ] ?? null , Traversal::OUTBOUND ) ;
    $varName   = $definition[ AQL::UNIQUE ] ?? $name ;

    // LET $name = LENGTH( FOR vertex IN OUTBOUND startVertex edgeCollection RETURN vertex )
    $expression = length( compile(
    [
        aqlTraversal
        ([
            AQL::DIRECTION       => $direction ,
            AQL::EDGE_COLLECTION => $edgeCollection ,
            AQL::START_VERTEX    => $startVertex ,
        ]) ,
        aqlReturn ( AQL::VERTEX  )
    ])) ;

    return aqlLet( $varName , $expression , useParentheses: true ) ;
}