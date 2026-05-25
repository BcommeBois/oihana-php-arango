<?php

namespace oihana\arango\models\traits\documents;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\queries\UpsertQueryTrait;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;

trait DocumentsRepsertTrait
{
    use UpsertQueryTrait;

    // UPSERT searchExpression
    // INSERT insertExpression
    // REPLACE replaceExpression
    // IN collection
    // OPTIONS { ... }
    // RETURN NEW

    /**
     * Repsert a document into the collection (replace or insert).
     *
     * @param array<string, mixed> $init The optional parameters to passed-in the function.
     *
     * Detail of the options :
     * - binds : The binding variables to use in the aql connector.
     * - collection : The name of the collection or use the collection property of the model.
     * - filter : The alternative filterExpression, this syntax for UPSERT operations allows you to use more flexible filter conditions beyond equality matches to look up documents.
     * - search : The 'searchExpression' contains the document to be looked for. It must be an object literal (UPSERT { <key>: <value>, ... } ...) without dynamic attribute names. In case no such document can be found in collection, a new document is inserted into the collection as specified in the insertExpression.
     * - insert : The document to insert in the collection if the document not exist.
     * - replace : The document to replace in the collection.
     * - overwrite : The overwriting mode "REPLACE" or "UPDATE" (default)
     * - options : The optional upsert options definition array or object.
     *
     * @return array<string, mixed>|object|null
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function repsert( array $init = [] ) : mixed
    {
        $bindVars = $init[ Arango::BINDS ] ?? [] ;
        $raw      = $init[ Arango::RAW   ] ?? false ;
        $query    = $this->buildUpsertQuery( AQL::REPLACE , $init , $bindVars ) ;
        return $this->getObject( query: $query , bindVars: $bindVars , raw: $raw ) ;
    }
}