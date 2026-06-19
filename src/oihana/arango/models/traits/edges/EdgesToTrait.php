<?php

namespace oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;
use oihana\arango\models\traits\edges\callbacks\OnDeleteVertex;

use oihana\enums\Char;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\core\container\resolveDependency;

/**
 * Provides utilities for initialize edge `to` vertex references in ArangoDB edge queries.
 *
 * This trait manages the source ('to') vertex models for edge collections.
 * It allows initializing these references from arrays or PSR-11.
 *
 * @author  Marc Alcaraz
 * @package oihana\arango\models\traits\aql
 * @version 1.0.0
 */
trait EdgesToTrait
{
    use OnDeleteVertex ;

    /**
     * The target document collection model (vertex where edges point to).
     * @var Documents|null
     */
    public Documents|null $to = null ;

    /**
     * Initialize the 'to' reference.
     *
     * The target document collection (vertex where edges point to).
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
    public function initializeTo
    (
        array|string|Documents|null $init      = null ,
        ?ContainerInterface         $container = null
    )
    :static
    {
        $this->unregisterTo() ;

        $to = match ( true )
        {
            is_array( $init ) && isset( $init[ AQL::TO ] )                           => $init[ AQL::TO ] ,
            $init instanceof Documents , is_string( $init ) && $init !== Char::EMPTY => $init ,
            default                                                                  => null  ,
        } ;

        if( is_string( $to ) )
        {
            $to = resolveDependency( $to , $container ) ;
        }

        $this->to = $to instanceof Documents ? $to : null ;

        $this->registerTo() ;

        return $this ;
    }

    /**
     * Release the 'to' reference.
     *
     * @return static
     */
    public function releaseTo() :static
    {
        $this->unregisterTo() ;
        $this->to = null ;
        return $this ;
    }

    /**
     * Register the `to` Documents signals.
     * @return $this
     */
    public function registerTo() :static
    {
        if ( $this->to instanceof Documents )
        {
            $this->to->afterDelete->connect( [ $this , self::ON_DELETE_VERTEX ] ) ;
        }
        return $this ;
    }

    /**
     * Unregister the `to` Documents signals.
     * @return $this
     */
    public function unregisterTo() :static
    {
        if ( $this->to instanceof Documents )
        {
            $this->to->afterDelete->disconnect( [ $this , self::ON_DELETE_VERTEX ] ) ;
        }
        return $this ;
    }
}