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
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\Operation;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\PrepareDocumentTrait;
use oihana\arango\models\traits\ArangoTrait;

use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;

use oihana\models\notices\AfterReplace;
use oihana\models\notices\BeforeReplace;
use oihana\models\traits\signals\HasReplaceSignals;

use function oihana\arango\db\operations\aqlUpdate;

/**
 * Provides helpers to build and execute **REPLACE** AQL operations on ArangoDB documents.
 *
 * This trait is responsible for fully replacing existing documents in a collection using
 * a dynamically generated AQL query with bind variables.
 *
 * Unlike the {@see aqlUpdate} function, which performs a **partial update** and only modifies the
 * specified attributes, **REPLACE** removes **all existing attributes** of the document,
 * except for immutable system attributes (`_key`, `_id`, `_rev`), and replaces them with
 * the attributes from the provided document.
 *
 * ---
 *
 * **AQL Syntax**
 *
 * ```aql
 * // Basic replace using a document key:
 * REPLACE @key WITH { ...newAttributes } IN @@collection [OPTIONS {...}]
 *
 * // Replace using a FOR ... FILTER clause:
 * FOR doc IN @@collection
 *     FILTER doc._key == @key
 *     REPLACE { ...newAttributes } IN @@collection [OPTIONS {...}]
 *     RETURN NEW
 * ```
 *
 * ---
 *
 * **Examples**
 *
 * 1. Simple replace by key:
 * ```php
 * $result = $this->replace
 * ([
 *     Arango::DOC   => [ 'name' => 'Marc', 'city' => 'Marseille' ],
 *     Arango::VALUE => '2531394',
 *     Arango::BINDS => [ '@collection' => 'places' ]
 * ]);
 * // => REPLACE @key WITH { name:'Marc', city:'Marseille' } IN @@collection RETURN NEW
 * ```
 * 2. Replace with excluded attributes:
 * ```php
 * $result = $this->replace
 * ([
 *     Arango::DOC      => [ 'name' => 'Marc', 'city' => 'Marseille', 'created' => '...' ],
 *     Arango::EXCLUDES => [ 'created' ],
 *     Arango::VALUE    => '2531394',
 *     Arango::BINDS    => [ '@collection' => 'places' ]
 * ]);
 * // => The "created" field will NOT be replaced
 * ```
 * 3. Replace and return the OLD document:
 * ```php
 * $result = $this->replace
 * ([
 *     Arango::DOC    => [ 'name' => 'Marc' ],
 *     Arango::VALUE  => '2531394',
 *     Arango::BINDS  => [ '@collection' => 'places' ],
 *     Arango::RETURN => Clause::OLD
 * ]);
 * // => REPLACE ... RETURN OLD
 * ```
 *
 * ---
 *
 * **Features**
 *
 * - Dynamically builds an optimized **REPLACE** AQL query.
 * - Supports **bind variables** to prevent injection.
 * - Allows excluding specific attributes from the replacement document.
 * - Supports **returning OLD or NEW documents** via {@see Clause}.
 * - Integrates with {@see PrepareDocumentTrait} to prepare and normalize documents.
 * - Uses {@see BindTrait} to manage AQL bind parameters.
 *
 * ---
 *
 * @package oihana\arango\models\traits\documents
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace
 * @see PrepareDocumentTrait
 * @see BindTrait
 * @see ArangoTrait
 */
trait DocumentsReplaceTrait
{
    use DocumentsUpdateTrait ,
        HasReplaceSignals ;

    /**
     * Replaces an existing document in a collection with a new one.
     *
     * This operation **removes all existing attributes** of the document, except immutable system attributes
     * (such as `_key`, `_id`, `_rev`), and sets the given attributes provided in `$doc`.
     *
     * It uses an AQL query similar to:
     *
     * ```aql
     * FOR doc IN @@collection
     *   FILTER doc._key == @key
     *   REPLACE { ...fillableDocument, modified: DATE_ISO8601(DATE_NOW()) } IN @@collection
     *   RETURN NEW
     * ```
     *
     * **Example usage:**
     *
     * ```php
     * $result = $this->replace
     * ([
     *     Arango::DOC    => [ 'name' => 'Marc', 'city' => 'Marseille' ],
     *     Arango::VALUE  => '2531394',
     *     Arango::BINDS  => [ '@collection' => 'places' ],
     * ]);
     *
     * echo $result->name; // "Marc"
     * ```
     *
     * @param array{
     *     doc?: array|object|null,            // The document definition to replace the existing one.
     *     key?: string|null,                  // The field name to use as a key for filtering (defaults to Prop::_KEY).
     *     value?: mixed,                      // The value of the document key to target in the collection.
     *     binds?: array|null,                 // Bind variables to pass into the AQL query.
     *     excludes?: array<string>|null,      // List of attributes to exclude from the replacement document.
     *     options?: array|string|object|null, // Options for the REPLACE operation (e.g., keepNull, ignoreRevs...).
     *     prefix?: string|null,               // The variable prefix to use for the key, defaults to "doc".
     *     return?: string|null                // Whether to return Clause::NEW (default) or Clause::OLD.
     * } $init The initialization options for the REPLACE operation.
     *
     * @return ?object The replaced document, or `null` if no matching document was found.
     *
     * @throws ArangoException If the database request fails.
     * @throws BindException If an error occurs when preparing bind variables.
     * @throws ContainerExceptionInterface If the DI container fails.
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException If a dependency cannot be resolved via DI.
     * @throws Error409
     * @throws NotFoundException If a dependency is missing in the container.
     * @throws NotFoundExceptionInterface If the DI container cannot locate a dependency.
     * @throws ReflectionException If reflection fails when preparing the document.
     * @throws UnsupportedOperationException
     * @throws Throwable
     */
    public function replace( array $init = [] ) : ?object
    {
        $this->beforeReplace?->emit( new BeforeReplace
        (
            target  : $this ,
            context : $init
        ));

        $document = $this->executeWriteOperation( $init , Operation::REPLACE ) ;

        $this->afterReplace?->emit( new AfterReplace
        (
            data    : $document ,
            target  : $this ,
            context : $init
        )) ;

        return $document ;
    }
}