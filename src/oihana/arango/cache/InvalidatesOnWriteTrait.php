<?php

namespace oihana\arango\cache;

use oihana\interfaces\Invalidable;

use oihana\models\traits\signals\HasDeleteSignals;
use oihana\models\traits\signals\HasInsertSignals;
use oihana\models\traits\signals\HasUpdateSignals;

use Psr\Container\ContainerInterface;

use oihana\arango\enums\Arango;

/**
 * Wires a model's write signals to the cached services it feeds.
 *
 * Declaring `Arango::INVALIDATES` on a model definition replaces the hand-rolled
 * decoration a caller would otherwise repeat around every model factory — a
 * copy-paste whose only failure mode is silent staleness.
 *
 * `Documents` composes this trait and calls {@see initializeInvalidations()} at the end of its constructor,
 * so every `Documents` / `Edges` honours the key with no wiring of its own.
 *
 * @package oihana\arango\cache
 * @author  Marc Alcaraz
 * @since   1.6.0
 */
trait InvalidatesOnWriteTrait
{
    use HasDeleteSignals ,
        HasInsertSignals ,
        HasUpdateSignals ;

    /**
     * Connects `afterInsert` / `afterUpdate` / `afterDelete` to the declared services.
     *
     * Called at the end of the `Documents` constructor, AFTER the signals exist —
     * `initializeDocumentsMethods()` creates them.
     *
     * The closure resolves each service from the container at EMISSION time, not
     * at boot: a dependent typically depends on this very model, and an eager
     * resolution here would be circular. By the time a write fires the signal,
     * the model is fully built.
     *
     * @param array<string,mixed> $init      The model init.
     * @param ContainerInterface  $container The DI container.
     *
     * @return static
     */
    public function initializeInvalidations( array $init , ContainerInterface $container ) : static
    {
        $services = $init[ Arango::INVALIDATES ] ?? null ;
        $services = is_array( $services ) ? $services : ( is_string( $services ) ? [ $services ] : [] ) ;

        if ( empty( $services ) )
        {
            return $this ;
        }

        $invalidate = static function() use ( $container , $services ) : void
        {
            foreach ( $services as $service )
            {
                if ( !is_string( $service ) || !$container->has( $service ) )
                {
                    continue ;
                }

                $dependent = $container->get( $service ) ;

                if ( $dependent instanceof Invalidable )
                {
                    $dependent->invalidate() ;
                }
            }
        } ;

        $this->afterInsert?->connect( $invalidate ) ;
        $this->afterUpdate?->connect( $invalidate ) ;
        $this->afterDelete?->connect( $invalidate ) ;

        return $this ;
    }
}