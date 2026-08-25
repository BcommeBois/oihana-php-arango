<?php

namespace oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\models\Edges;

use oihana\reflect\exceptions\ConstantException;

/**
 * Resolves the common edge-traversal context shared by the edge variable builders.
 *
 * This helper centralizes the preamble duplicated by {@see buildEdgeVariable()} and
 * {@see buildEdgeCountVariable()}: it resolves the {@see Edges} model from the
 * definition (via {@see getEdges()}), validates it, reads its non-empty collection
 * name and normalizes the traversal direction.
 *
 * @param array                   $definition The relation definition. Expected keys:
 *  - `AQL::MODEL`     : the Edges model (instance, array or container id).
 *  - `AQL::DIRECTION` : the traversal direction (defaults to {@see Traversal::OUTBOUND}).
 *                       Resolved through {@see resolveEdgeDirection()}, so an unknown
 *                       keyword is refused rather than folded back on the default.
 * @param ContainerInterface|null $container  Optional DI container used to resolve the model.
 *
 * @return array{0: Edges, 1: string, 2: string} A triplet `[ $model , $edgeCollection , $direction ]`.
 *
 * @throws ConstantException        If the declared traversal direction is not a `Traversal` keyword.
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws UnexpectedValueException If the model is not an {@see Edges} instance or its collection is empty.
 */
function resolveEdgeContext( array $definition = [] , ?ContainerInterface $container = null ) : array
{
    $model = getEdges( $definition[ AQL::MODEL ] ?? null , $container ) ;
    if( !( $model instanceof Edges ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the edges model reference must be an instance of Edges.' ) ;
    }

    $edgeCollection = $model->collection ;
    if( empty( $edgeCollection ) )
    {
        throw new UnexpectedValueException( __FUNCTION__ . ' failed, the edge collection not must be null or empty.' ) ;
    }

    $direction = resolveEdgeDirection( $definition ) ;

    return [ $model , $edgeCollection , $direction ] ;
}
