<?php

namespace oihana\arango\models\traits\documents;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;
use Throwable;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\PrepareDocumentTrait;
use oihana\arango\models\traits\ArangoTrait;

use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;

use oihana\models\notices\AfterInsert;
use oihana\models\notices\BeforeInsert;
use oihana\models\traits\signals\HasInsertSignals;

use function oihana\arango\db\operations\aqlInsert;

trait DocumentsInsertTrait
{
    use ArangoTrait   ,
        HasInsertSignals ,
        PrepareDocumentTrait ;

    /**
     * Insert a new document into the collection.
     * @param array{
     *     binds?      :array|null ,
     *     conditions? :array|null ,
     *     debug?      :bool|null ,
     *     doc?        :null|array|object|string ,
     *     excludes?   :array|null ,
     * } $init
     * The parameters to passed-in the function.
     * <ul>
     *      <li>doc : The document to insert in the collection.</li>
     *      <li>excludes : The list of all attributes to excludes in the new document</li>
     *      <li>binds The default variables to binds.</li>
     *      <li>relations The edges relations definitions to link after the insertion</li>
     * </ul>
     * @return ?object
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
    public function insert( array $init = [] ) :?object
    {
        $this->beforeInsert?->emit( new BeforeInsert
        (
            target  : $this ,
            context : $init
        )) ;

        // INSERT document INTO @@collection RETURN NEW
        $bindVars   = $init[ Arango::BINDS       ] ?? [] ;
        $conditions = $init[ Arango::CONDITIONS  ] ?? null ;
        $debug      = $init[ Arango::DEBUG       ] ?? false ;
        $doc        = $init[ Arango::DOC         ] ?? null ;
        $removeKeys = $init[ Arango::REMOVE_KEYS ] ?? null ;

        $docClause = $this->prepareDocumentClause
        (
            doc        : $doc ,
            operation  : Operation::INSERT ,
            binds    : $bindVars ,
            removeKeys : $removeKeys ,
            conditions : $conditions ,
        ) ;

        $query = aqlInsert
        ([
            AQL::BIND_COLLECTION => AQL::COLLECTION  ,
            AQL::COLLECTION      => $this->collection ,
            AQL::DOC             => $docClause
        ]
        , $bindVars , $this->getQueryID() ) ;

        if( $debug === true )
        {
            $this->debugQuery( __METHOD__ , $query , $bindVars ) ;
        }

        $document = $this->getObject( $query , $bindVars ) ;

        $this->afterInsert?->emit( new AfterInsert
        (
            data    : $document ,
            target  : $this     ,
            context : $init
        )) ;

        return $document ;
    }
}