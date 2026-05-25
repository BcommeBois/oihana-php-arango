<?php

namespace oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;
use oihana\arango\models\traits\edges\callbacks\OnDeleteVertex;

use oihana\enums\Char;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface ;

use function oihana\controllers\helpers\resolveDependency;

/**
 * Provides utilities for initialize edge `from` vertex references in ArangoDB edge queries.
 *
 * This trait manages the source ('from') vertex models for edge collections.
 * It allows initializing these references from arrays or PSR-11.
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\models\traits\aql
 * @version 1.0.0
 */
trait EdgesFromTrait
{
    use OnDeleteVertex ;

    /**
     * The source document collection model (vertex where edges originate).
     * @var Documents|null
     */
    public Documents|null $from = null ;

    /**
     * Initialize the 'from' reference.
     *
     * The source document collection (vertex where edges originate).
     *
     * @param array|string|Documents|null $init Can be :
     * - an associative array containing AQL::FROM key,
     * - a string service identifier (for PSR-11 container),
     * - a Documents instance,
     * - or null.
     *
     * @param ContainerInterface|null $container
     *
     * @return static
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function initializeFrom
    (
        array|string|Documents|null $init      = null ,
        ?ContainerInterface         $container = null
    )
    :static
    {
        $this->unregisterFrom() ;

        $from = match ( true )
        {
            is_array( $init ) && isset( $init[ AQL::FROM ] )                         => $init[ AQL::FROM ] ,
            $init instanceof Documents , is_string( $init ) && $init !== Char::EMPTY => $init ,
            default                                                                  => null  ,
        } ;

        if( is_string( $from ) )
        {
            $from = resolveDependency( $from , $container ) ;
        }

        $this->from = $from instanceof Documents ? $from : null ;

        $this->registerFrom() ;

        return $this ;
    }

    /**
     * Release the 'from' reference.
     *
     * @return static
     */
    public function releaseFrom() :static
    {
        $this->unregisterFrom() ;
        $this->from = null ;
        return $this ;
    }

    /**
     * Register the `from` Documents signals.
     * @return $this
     */
    public function registerFrom() :static
    {
        if ( $this->from instanceof Documents )
        {
            $this->from->afterDelete->connect( [ $this , self::ON_DELETE_VERTEX ] ) ;
        }
        return $this ;
    }

    /**
     * Unregister the `from` Documents signals.
     * @return $this
     */
    public function unregisterFrom() :static
    {
        if ( $this->from instanceof Documents )
        {
            $this->from->afterDelete->disconnect( [ $this , self::ON_DELETE_VERTEX ] ) ;
        }
        return $this ;
    }
}