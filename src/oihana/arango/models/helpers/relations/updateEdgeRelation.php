<?php

namespace oihana\arango\models\helpers\relations;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;
use Throwable;
use UnexpectedValueException;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;

use org\schema\constants\Schema;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\getEdges;
use function oihana\core\accessors\getKeyValue;
use function oihana\core\accessors\hasKeyValue;

/**
 * Handles the update of a single EDGE-type relation.
 *
 * @param string $name The relation property name.
 * @param array $options The relation definition options.
 * @param array|object|null $document The current document instance.
 * @param ContainerInterface|null $container Optional PSR-11 container used to resolve the Edges definitions
 * @param Documents|null $documents Optional Documents reference to find the Edges definitions.
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
 * @throws UnsupportedOperationException
 * @throws Throwable
 */
function updateEdgeRelation
(
    string              $name ,
    array               $options ,
    array|object|null   $document ,
    ?ContainerInterface $container = null ,
    ?Documents          $documents = null
)
: void
{
    // Case 1 : search in the controller payload's relation definition
    $edges = getEdges( $options , $container ) ;

    // Case 2 : fallback to model's 'edges' definitions
    if ( !$edges && $documents instanceof Documents )
    {
        $definitions = $documents->edges ?? [];
        $options     = [ ...( $definitions[ $name ] ?? [] ) , ...$options ] ;
        $edges       = getEdges($options[ Arango::MODEL ] ?? null , $container ) ;
    }

    if ( !$edges instanceof Edges )
    {
        throw new UnexpectedValueException( sprintf
        (
            'The "%s" relation failed: "Arango::EDGES" definition does not match a valid Edges model.',
            $name
        ));
    }

    $key = $options[ Arango::KEY ] ?? Schema::_KEY ;
    if ( !hasKeyValue( $document , $key ) )
    {
        throw new UnexpectedValueException( sprintf
        (
            'The "%s" relation failed: document property "%s" does not exist.',
            $name ,
            $key
        ));
    }

    $documentId = getKeyValue( $document , $key ) ;
    $direction  = ( $options[ Arango::DIRECTION ] ?? Traversal::OUTBOUND ) === Traversal::INBOUND ? Traversal::INBOUND : Traversal::OUTBOUND ;
    $isOutbound = $direction === Traversal::OUTBOUND ;
    $touch      = (bool) ( $options[ Arango::TOUCH  ] ?? false ) ;
    $unique     = (bool) ( $options[ Arango::UNIQUE ] ?? true  ) ;

    $updated = false ;

    if ( $unique )
    {
        // Clean existing edges
        if ( $isOutbound && $edges->existEdgeFrom( $documentId ) )
        {
            $edges->deleteEdgeFrom( $documentId ) ;
            $updated = true ;
        }
        else if ( !$isOutbound && $edges->existEdgeTo( $documentId ) )
        {
            $edges->deleteEdgeTo( $documentId ) ;
            $updated = true ;
        }
    }

    // Insert new edge if value provided

    $payloadId = $options[ Arango::VALUE ] ?? null ;
    if ( isset( $payloadId ) && $payloadId !== Char::EMPTY )
    {
        $from = $isOutbound ? $documentId : $payloadId ;
        $to   = $isOutbound ? $payloadId  : $documentId ;
        $edges->insertEdge( $from ,  $to ) ;
        $updated = true ;
    }

    if( $updated && $touch )
    {
        if( $isOutbound )
        {
            $edges->from->updateDate() ;
        }
        else
        {
            $edges->to->updateDate() ;
        }
    }
}