<?php

namespace oihana\arango\models\traits\documents\callbacks;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;
use Throwable;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\documents\DocumentsInsertTrait;
use oihana\arango\models\traits\documents\DocumentsReplaceTrait;
use oihana\arango\models\traits\documents\DocumentsUpdateTrait;

use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;

use oihana\signals\notices\Payload;

use function oihana\arango\models\helpers\relations\updateRelations;

trait OnUpdateRelations
{
    use DocumentsInsertTrait  ,
        DocumentsReplaceTrait ,
        DocumentsUpdateTrait  ;

    /**
     * The 'onUpdateRelations' method name.
     */
    public const string ON_UPDATE_RELATIONS = 'onUpdateRelations' ;

    /**
     * Invoked when a document is inserted, replaced or updated in a Documents
     * to update the document's relations (edges).
     *
     * @param Payload $payload The payload notice.
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws Error409
     * @throws ArangoException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws Throwable
     */
    public function onUpdateRelations( Payload $payload ):void
    {
        updateRelations
        (
            document  : $payload->data ,
            relations : $payload->context[ Arango::RELATIONS ] ?? null ,
            documents : $payload->target  ,
            container : $this->container
        ) ;
    }

    /**
     * Register the onUpdateRelations callbacks
     * to handles the insert/replace/update methods.
     * @return static
     */
    public function registerUpdateRelations() :static
    {
        $callback = [ $this , self::ON_UPDATE_RELATIONS ] ;
        $this->afterInsert?->connect  ( $callback ) ;
        $this->afterReplace?->connect ( $callback ) ;
        $this->afterUpdate?->connect  ( $callback ) ;
        return $this ;
    }

    /**
     * Unregister the onUpdateRelations callbacks.
     * @return static
     */
    public function unregisterUpdateRelations() :static
    {
        $callback = [ $this , self::ON_UPDATE_RELATIONS ] ;
        $this->afterInsert?->disconnect  ( $callback ) ;
        $this->afterReplace?->disconnect ( $callback ) ;
        $this->afterUpdate?->disconnect  ( $callback ) ;
        return $this ;
    }
}