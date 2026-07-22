<?php

namespace oihana\arango\models;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\collection\enums\CollectionType;
use oihana\arango\models\traits\aql\FieldsTrait;
use oihana\arango\models\traits\AQLQueryTrait;
use oihana\arango\models\traits\DoctorTrait;
use oihana\arango\models\traits\documents\DocumentsMethodsTrait;
use oihana\arango\models\traits\DocumentsArrayTrait;
use oihana\arango\models\interfaces\ArangoDocumentsModel;
use oihana\exceptions\ValidationException;
use oihana\models\traits\BindsTrait;
use oihana\models\traits\SchemaTrait;
use oihana\traits\ConfigTrait;
use oihana\traits\ContainerTrait;
use oihana\traits\QueryIDTrait;
use oihana\traits\ToStringTrait;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;

/**
 * Represents a high-level ArangoDB Documents Model.
 *
 * This class provides a unified abstraction layer to interact with an ArangoDB document collection.
 * It manages schema definitions, query bindings, filters, joins, facets, sorting, and transformation rules.
 *
 * It also integrates with a dependency injection container to dynamically resolve and initialize
 * model components such as the database connection, logger, configuration, and mock/debug options.
 *
 * Typical usage involves defining a set of model options describing how the collection should be
 * initialized and how the documents should be retrieved, transformed, or persisted.
 *
 * Example:
 * ```php
 * $database = new ArangoDB
 * ([
 *      ArangoConfig::DATABASE   => "xyz" ,
 *      ArangoConfig::ENDPOINT   => "tcp://127.0.0.1:8529" ,
 *      ArangoConfig::USER       => "root" ,
 *      ArangoConfig::PASSWORD   => "your-secure-password" ,
 *      ArangoConfig::BATCH_SIZE => 50000 ,
 *      ArangoConfig::CONNECTION => "Keep-Alive" ,
 *      ArangoConfig::TIMEOUT    => 25 ,
 *      ArangoConfig::TYPE       => "Basic"
 * ]);
 *
 * $documents = new Documents( $container,
 * [
 *     'database'   => $database , // the
 *     'collection' => 'places',
 *     'lazy'       => true , // By default, creates the collection if not exist.
 *     'indexes'    => // auto-generate the collection's indexes when the collection is created.
 *     [
 *         new PersistentIndexOptions
 *         ([
 *             IndexOptions::NAME   => 'id' ,
 *             IndexOptions::FIELDS => [ Prop::ID ] ,
 *             IndexOptions::UNIQUE => true ,
 *         ])
 *     ],
 *     'alters' =>
 *     [
 *         Prop::URL => [ Alter::URL , Paths::PLACES , Prop::_KEY ]
 *     ],
 *     'filters' =>
 *     [
 *         Prop::CREATED  => FilterType::DATE ,
 *         Prop::MODIFIED => FilterType::DATE ,
 *         Prop::NAME     => FilterType::STRING ,
 *     ],
 * ]);
 * ```
 *
 * @package oihana\arango\models
 *
 * @see ArangoDocumentsModel
 */
class Documents implements ArangoDocumentsModel
{
    /**
     * Creates a new Documents instance.
     *
     * @param Container $container The DI Container reference.
     * @param array $init The options of the Documents model :
     * <ul>
     *   <li>'alters'     - An associative array of transformation rules used to alter or enrich the document properties returned by the model.</li>
     *   <li>'collection' - The name of the ArangoDB Document collection to manage</li>
     *   <li>'database'   - The ArangoDB database reference or its definition in the DI Container.</li>
     *   <li>'edges'      - All the edges definitions of the collection</li>
     *   <li>'facets'     - The facet definitions to register</li>
     *   <li>'fields'     - The optional fields definitions to returns in the get/list methods.</li>
     *   <li>'fillable'   - The fillable definitions to register</li>
     *   <li>'filters'    - The filter definitions to register</li>
     *   <li>'groupable'  - The optional whitelist/mapping (`urlKey => fieldPath`) of groupable dimensions for `?groupBy=` / `?group=`.</li>
     *   <li>'indexes'    - The definition of the indexes to auto-register when the collection is created (if not exist)</li>
     *   <li>'joins'      - The joins definitions to register</li>
     *   <li>'lazy'       - Indicates if the model create the collection if not exit.</li>
     *   <li>'mock'       - Indicates if the methods return a mock value (debug mode only)</li>
     *   <li>'options'    - Indicates the ArangoDB collection options to initialize the lazy new collection</li>
     *   <li>'searchable' - Indicates the search definitions.</li>
     *   <li>'sortable'   - Defines the sortable strategies for a map of specific properties.</li>
     *   <li>'type'       - Indicates the type of the collection - document (default) or edge.</li>
     * </ul>
     * @param int $type The default type of the collection (Default {@seeollection::TYPE_DOCUMENT} -> 'document' [2] )T
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function __construct
    (
        Container $container ,
        array     $init = [] ,
        int       $type = CollectionType::DOCUMENT
    )
    {
        $this->container = $container  ;

        $this->initializeLogger           ( $init , $container )
             ->initializeDatabase         ( $init , $container ) # First of all
             ->initializeCollection       ( $init , $type      ) # After the database
             ->initializeActivable        ( $init )
             ->initializeAlters           ( $init )
             ->initializeArrays           ( $init )
             ->initializeBinds            ( $init )
             ->initializeBounds           ( $init )
             ->initializeConfig           ( $init , $container )
             ->initializeConditions       ( $init )
             ->initializeDebug            ( $init )
             ->initializeEdges            ( $init )
             ->initializeFacets           ( $init )
             ->initializeFillable         ( $init )
             ->initializeFilters          ( $init )
             ->initializeGroupable        ( $init )
             ->initializeJoins            ( $init )
             ->initializeFields           ( $init )
             ->initializeSkinFields       ( $init )
             ->initializeMock             ( $init )
             ->initializeQueryID          ( $init )
             ->initializeSchema           ( $init )
             ->initializeSearchable       ( $init )
             ->initializeSearchOperator   ( $init )
             ->initializeSearchSeparators ( $init )
             ->initializeView             ( $init ) # After the collection and the searchable fields
             ->initializeSortDefault      ( $init )
             ->initializeSortable         ( $init )
             ->initializeDocumentsMethods () ;
    }

    use
    AQLQueryTrait ,
    BindsTrait ,
    ConfigTrait ,
    ContainerTrait ,
    DoctorTrait ,
    DocumentsArrayTrait ,
    DocumentsMethodsTrait,
    FieldsTrait ,
    QueryIDTrait ,
    SchemaTrait ,
    ToStringTrait ;
}