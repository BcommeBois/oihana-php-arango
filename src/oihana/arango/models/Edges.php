<?php

namespace oihana\arango\models;

use DI\Container;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\models\traits\edges\EdgesDeleteTrait;
use oihana\arango\models\traits\edges\EdgesInsertTrait;
use oihana\arango\models\traits\edges\EdgesPurgeTrait;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\collection\enums\CollectionType ;
use oihana\arango\models\traits\edges\EdgesCountTrait;
use oihana\arango\models\traits\edges\EdgesExistTrait;
use oihana\arango\models\traits\edges\EdgesGetTrait;
use ReflectionException;

/**
 * Represents a collection of ArangoDB edges connecting vertices.
 *
 * This model extends {@see Documents} and provides additional
 * methods to manage edges between vertices (`from` and `to`).
 *
 * It supports:
 * - Automatic initialization of vertex references (`from` and `to`).
 * - Counting edges based on vertex filters.
 * - AQL query generation for counting edges with bind variables.
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
class Edges extends Documents
{
    /**
     * Creates a new Edges instance.
     *
     * @param Container $container The DI Container reference.
     * @param array $init The options of the Edges model.
     * <ul>
     *   <li>'alters'     - An associative array of transformation rules used to alter or enrich the document properties returned by the model.</li>
     *   <li>'binds'      - The bindvars array definitions</li>
     *   <li>'collection' - The name of the ArangoDB Document collection to manage</li>
     *   <li>'database'   - The arangoDB database reference</li>
     *   <li>'from'       - The source Documents model reference (vertex where edges originate).</li>
     *   <li>'to'         - The target Documents model reference (vertex where edges point to)</li>
     * </ul>
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ReflectionException
     *
     * @example
     * ```php
     * $edges = new Edges( $container,
     * [
     *     'collection' => 'user_follows',
     *     'from'       => $usersModel,
     *     'to'         => $usersModel,
     * ]);
     * ```
     */
    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init , CollectionType::EDGE );
        $this->initializeVertices( $init , $container ) ;
    }

    /**
     * Invoked when the Edges instance is removed.
     */
    public function __destruct()
    {
        $this->releaseVertices() ;
    }

    use EdgesPurgeTrait  ,
        // --------------
        EdgesCountTrait  ,
        EdgesDeleteTrait ,
        EdgesExistTrait  ,
        EdgesGetTrait    ,
        EdgesInsertTrait ;
}