<?php

namespace tests\oihana\arango\cache\mocks;

use oihana\arango\cache\InvalidatesOnWriteTrait;

/**
 * A bare host for InvalidatesOnWriteTrait — a stand-in for the model that
 * would carry it in production, reduced to its write signals.
 */
final class InvalidatesOnWriteHost
{
    use InvalidatesOnWriteTrait ;

    /**
     * Creates a new InvalidatesOnWriteHost.
     *
     * @param bool $initializeSignals Whether to create the write signals — `false`
     *                                reproduces a host wired before its signals exist.
     */
    public function __construct( bool $initializeSignals = true )
    {
        if ( $initializeSignals )
        {
            $this->initializeInsertSignals()
                 ->initializeUpdateSignals()
                 ->initializeDeleteSignals() ;
        }
    }
}
