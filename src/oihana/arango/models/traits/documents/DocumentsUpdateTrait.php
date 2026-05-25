<?php

namespace oihana\arango\models\traits\documents;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use InvalidArgumentException;
use ReflectionException;
use Throwable;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\Operation;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\PrepareDocumentTrait;
use oihana\arango\models\traits\ArangoTrait;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\http\Error409;

use oihana\models\notices\AfterUpdate;
use oihana\models\notices\BeforeUpdate;
use oihana\models\traits\signals\HasUpdateSignals;

use org\schema\constants\Schema;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operations\aqlUpdate;
use function oihana\arango\db\operators\equal;

use function oihana\core\date\now;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Provides helpers to build and execute **UPDATE** AQL operations on ArangoDB documents.
 *
 * This trait is responsible for partially updating existing documents in a collection using
 * dynamically generated AQL queries with bind variables.
 *
 * Unlike {@see DocumentsReplaceTrait}, which **replaces** the entire document and removes
 * all unspecified attributes, **UPDATE** only modifies the given attributes. Any attributes
 * that are not specified in the update document remain unchanged.
 *
 * ---
 *
 * **AQL Syntax**
 *
 * ```aql
 * // Update a document by key:
 * UPDATE @key WITH { ...updatedAttributes } IN @@collection [OPTIONS {...}]
 *
 * // Update using a FOR ... FILTER clause:
 * FOR doc IN @@collection
 *     FILTER doc._key == @key
 *     UPDATE doc WITH { ...updatedAttributes } IN @@collection [OPTIONS {...}]
 *     RETURN NEW
 * ```
 *
 * ---
 *
 * **Examples**
 *
 * ```php
 * // 1. Simple UPDATE by key:
 * $result = $this->update([
 *     Arango::DOC   => [ 'name' => 'Marc' ],
 *     Arango::VALUE => '2531394',
 *     Arango::BINDS => [ '@collection' => 'places' ]
 * ]);
 * // => UPDATE @key WITH { name:'Marc' } IN @@collection RETURN NEW
 *
 * // 2. UPDATE with excluded attributes:
 * $result = $this->update([
 *     Arango::DOC      => [ 'name' => 'Marc', 'created' => '...' ],
 *     Arango::EXCLUDES => [ 'created' ],
 *     Arango::VALUE    => '2531394',
 *     Arango::BINDS    => [ '@collection' => 'places' ]
 * ]);
 * // => The "created" field will NOT be updated
 *
 * // 3. UPDATE and return the OLD document instead of the NEW one:
 * $result = $this->update([
 *     Arango::DOC    => [ 'name' => 'Marc' ],
 *     Arango::VALUE  => '2531394',
 *     Arango::BINDS  => [ '@collection' => 'places' ],
 *     Arango::RETURN => Clause::OLD
 * ]);
 * // => UPDATE ... RETURN OLD
 *
 * // 4. Update only the "modified" timestamp using DATE_ISO8601():
 * $result = $this->updateDate(
 *     '2531394',
 *     [ Arango::BINDS => [ '@collection' => 'places' ] ],
 *     Prop::MODIFIED
 * );
 * // => UPDATE doc WITH { modified: DATE_ISO8601(DATE_NOW()) } IN @@collection RETURN NEW
 * ```
 *
 * ---
 *
 * **Features**
 *
 * - Dynamically builds optimized **UPDATE** AQL queries.
 * - Supports **bind variables** to prevent AQL injection.
 * - Allows excluding specific attributes from being updated.
 * - Supports **returning OLD or NEW documents** via {@see Clause}.
 * - Integrates with {@see PrepareDocumentTrait} to normalize documents.
 * - Uses {@see BindTrait} to manage AQL bind parameters.
 * - Provides a helper method {@see updateDate()} to quickly update timestamps.
 *
 * ---
 *
 * @package oihana\arango\models\traits\documents
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/update
 * @see DocumentsReplaceTrait
 * @see PrepareDocumentTrait
 * @see BindTrait
 * @see ArangoTrait
 */
trait DocumentsUpdateTrait
{
    use ArangoTrait ,
        BindTrait  ,
        HasUpdateSignals ,
        PrepareDocumentTrait ;

    /**
     * Partially updates a document in the collection.
     *
     * This method generates and executes an AQL `UPDATE` operation on the targeted document.
     * Only the attributes specified in the `doc` array or object are modified; any other
     * attributes in the existing document remain unchanged. Immutable system attributes
     * (such as `_key`, `_id`, `_rev`) are never overwritten.
     *
     * **AQL Syntax**
     *
     * ```aql
     * // Update using a key:
     * UPDATE @key WITH { ...updatedAttributes } IN @@collection [OPTIONS {...}]
     *
     * // Update using a FOR ... FILTER clause:
     * FOR doc IN @@collection
     *   FILTER doc._key == @key
     *   UPDATE doc WITH { ...updatedAttributes } IN @@collection [OPTIONS {...}]
     *   RETURN NEW
     * ```
     *
     * **Initialization Options (`$init`)**
     *
     * @param array{
     *     doc?: array|object|null,              // The document attributes to update.
     *     key?: string|null,                    // The attribute used as key for filtering (default: '_key').
     *     excludes?: array<string>|null,        // Attributes to exclude from the update.
     *     value?: mixed,                        // The value of the key to identify the document.
     *     prefix?: string|null,                 // Variable prefix for the key, default "doc" → "doc.key".
     *     binds?: array|null,                   // Optional bind variables for AQL.
     *     options?: array|string|object|null,  // Options for the UPDATE operation (keepNull, mergeObjects, ignoreRevs, etc.).
     *     return?: string|null                  // Clause::NEW (default) or Clause::OLD.
     * } $init
     *
     * **Examples**
     *
     * ```php
     * // 1. Update a document by key:
     * $result = $this->update
     * ([
     *     Arango::DOC   => [ 'name' => 'Marc' ],
     *     Arango::VALUE => '2531394',
     *     Arango::BINDS => [ '@collection' => 'places' ]
     * ]);
     *
     * // 2. Update excluding certain fields:
     * $result = $this->update
     * ([
     *     Arango::DOC      => [ 'name' => 'Marc', 'created' => '...' ],
     *     Arango::EXCLUDES => [ 'created' ],
     *     Arango::VALUE    => '2531394'
     * ]);
     *
     * // 3. Update and return the OLD document:
     * $result = $this->update
     * ([
     *     Arango::DOC    => [ 'name' => 'Marc' ],
     *     Arango::VALUE  => '2531394',
     *     Arango::RETURN => Clause::OLD
     * ]);
     * ```
     *
     * @return ?object The updated document, or `null` if no document matched the key.
     *
     * @throws ArangoException If the database request fails.
     * @throws BindException If an error occurs while binding variables.
     * @throws ContainerExceptionInterface If the DI container throws an exception.
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException If a DI dependency cannot be resolved.
     * @throws Error409
     * @throws NotFoundException If a DI dependency is missing.
     * @throws NotFoundExceptionInterface If the DI container cannot locate a dependency.
     * @throws ReflectionException If reflection fails when preparing the document.
     * @throws UnsupportedOperationException
     * @throws Throwable
     */
    public function update( array $init = [] ) : ?object
    {
        $this->beforeUpdate?->emit( new BeforeUpdate
        (
            target  : $this ,
            context : $init
        )) ;

        $document = $this->executeWriteOperation( $init ) ;

        $this->afterUpdate?->emit( new AfterUpdate
        (
            data    : $document ,
            target  : $this ,
            context : $init
        )) ;

        return $document ;
    }

    /**
     * Update a single date property in a document with the current date .
     *
     * By default, it updates the `modified` property with the current timestamp.
     *
     * @param array  $init     Additional options like binds, return clause, etc.
     * @param string $property The document property to update (default: Schema::MODIFIED).
     *
     * @return object|null The updated document.
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
     * @throws Throwable
     */
    public function updateDate
    (
        array   $init     = [] ,
        string  $property = Schema::MODIFIED
    )
    : ?object
    {
        return $this->executeWriteOperation( [ ...$init , Arango::DOC => [ $property => now() ] ] ) ;
    }

    /**
     * The main internal function to update or replace a document in a collection.
     *
     * This method builds and executes an AQL `UPDATE` or `REPLACE` query for a given document.
     * It supports bind variables, exclusion of certain fields, custom options, and returning
     * either the NEW or OLD document after the operation.
     **Supported `$init` options**
     *
     * @param array{
     *     value?    : mixed|null,                // The value of the key to identify the document.
     *     doc?      : array|object|string|null,  // The document to update/replace (associative array, object, or AQL string).
     *     key?      : string|null,               // The document attribute used as key for filtering (default: '_key').
     *     prefix?   : string|null,               // Variable prefix for the key, default: "doc" → "doc.key".
     *     binds?    : array|null,                // Optional bind variables for AQL.
     *     excludes? : array<string>|null,        // Attributes to exclude from the update/replace.
     *     options?  : array|string|object|null,  // Options for the UPDATE operation:
     *     return?   : string|null,               // Clause::NEW (default) or Clause::OLD.
     *     debug?    : bool|null                  // If true, prints the compiled AQL query for debugging.
     * } $init
     *
     * @param string $operation The type of operation: `Operation::UPDATE` or `Operation::REPLACE`.
     *
     * @return object|null Returns the updated or replaced document, or `null` if no matching document was found.
     *
     * @throws InvalidArgumentException If `$operation` is not `UPDATE` or `REPLACE`.
     * @throws ArangoException If the database request fails.
     * @throws BindException If an error occurs while binding variables.
     * @throws ContainerExceptionInterface If the DI container throws an exception.
     * @throws DateInvalidTimeZoneException If a date/time operation fails due to an invalid timezone.
     * @throws DateMalformedStringException If a date/time string cannot be parsed.
     * @throws DependencyException If a DI dependency cannot be resolved.
     * @throws NotFoundException If a DI dependency is missing.
     * @throws NotFoundExceptionInterface If the DI container cannot locate a dependency.
     * @throws ReflectionException If reflection fails when preparing the document.
     */
    protected function executeWriteOperation( array $init , string $operation = Operation::UPDATE ): ?object
    {
        if( $operation !== Operation::UPDATE && $operation !== Operation::REPLACE )
        {
            throw new InvalidArgumentException
            (
                $operation . ' failed, the `operation` argument must be `UPDATE` or `REPLACE`.'
            ) ;
        }

        $binds      = $init[ Arango::BINDS       ] ?? [] ;
        $conditions = $init[ Arango::CONDITIONS  ] ?? [] ;
        $debug      = $init[ Arango::DEBUG       ] ?? false ;
        $doc        = $init[ Arango::DOC         ] ?? null ;
        $ensure     = $init[ Arango::ENSURE      ] ?? null ;
        $removeKeys = $init[ Arango::REMOVE_KEYS ] ?? null ;
        $options    = $init[ Arango::OPTIONS     ] ?? null ;
        $return     = $init[ Arango::RETURN      ] ?? Clause::NEW ;

        $key    = key($init[ Arango::KEY ] ?? Schema::_KEY, $init[ Arango::PREFIX ] ?? AQL::DOC ) ;
        $value  = $this->bind($init[ Arango::VALUE ] ?? null , $binds , AQL::KEY ) ;

        // conditions => [] to disabled the null compression.
        $docClause = $this->prepareDocumentClause
        (
            $doc ,
            $operation ,
            $binds ,
            $removeKeys ,
            $conditions ,
            $ensure
        ) ;

        $for    = aqlFor    ( [ AQL::IN => [AQL::IN => $this->bindCollection( $binds ) ] ] ) ;
        $filter = aqlFilter ( equal( $key , $value ) ) ;
        $write  = aqlUpdate ( [ AQL::WITH => $docClause , AQL::OPTIONS => $options ] , $operation ) ;
        $return = aqlReturn ($return === Clause::OLD ? Clause::OLD : Clause::NEW ) ;

        $query = compile( [ $for , $filter , $write , $return ] ) ;

        if( $debug === true )
        {
            $this->debugQuery( __METHOD__ , $query , $binds ) ;
        }

        return $this->getObject( $query , $binds ) ;
    }
}