<?php

namespace oihana\arango\models\helpers\relations;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;
use Throwable;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\controllers\enums\AQLType;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Updates one or several relations for a given document.
 *
 * - For the moment the only relations of type {@see AQLType::EDGE} are currently supported.
 * - Each relation can define its model, direction, key, and target value.
 * - If the provided relation does not define a valid `Edges` model,
 *   the method will attempt to resolve it from the parent `Documents` model.
 *
 * Example:
 * ```php
 * updateRelations( $document ,
 * [
 *     'contains' =>
 *     [
 *         Arango::TYPE      => AQLType::EDGE,
 *         Arango::MODEL     => 'place_edges',
 *         Arango::DIRECTION => Traversal::OUTBOUND,
 *         Arango::KEY       => Schema::_KEY,
 *         Arango::VALUE     => 'place/12345',
 *     ],
 * ] , $container  , $documents ) ;
 * ```
 * @param array|object|null $document The document whose relations should be updated.
 * @param array|null $relations The associative array of relation definitions.
 * @param Documents|null $documents Optional Documents reference to find the Edges definitions.
 * @param ContainerInterface|null $container Optional PSR-11 container used to resolve the Edges definitions
 *
 * @throws ArangoException
 * @throws BindException
 * @throws ContainerExceptionInterface
 * @throws DateInvalidTimeZoneException
 * @throws DateMalformedStringException
 * @throws DependencyException
 * @throws Error409
 * @throws NotFoundException
 * @throws NotFoundExceptionInterface
 * @throws ReflectionException
 * @throws Throwable
 * @throws UnsupportedOperationException
 */
function updateRelations
(
    null|array|object   $document  = null ,
    ?array              $relations = []   ,
    ?Documents          $documents = null ,
    ?ContainerInterface $container = null ,
) :void
{
    if ( !isset( $document ) || !isset( $relations ) || count( $relations ) === 0 )
    {
        return ;
    }

    foreach ( $relations as $name => $options )
    {
        $type = $options[ Arango::TYPE ] ?? null ;
        if ( $type === AQLType::EDGE )
        {
            updateEdgeRelation
            (
                $name ,
                $options ,
                $document ,
                $container ,
                $documents
            ) ;
        }
    }
}