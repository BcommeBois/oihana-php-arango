<?php

namespace tests\oihana\arango\auth\mocks;

use Psr\Log\AbstractLogger;

use Stringable;

/**
 * A PSR-3 logger that records every line it receives.
 *
 * @package tests\oihana\arango\auth\mocks
 * @author  Marc Alcaraz
 */
class SpyLogger extends AbstractLogger
{
    /**
     * The recorded lines, as `[ level , message ]`.
     *
     * @var array<int, array{0:string,1:string}>
     */
    public array $records = [] ;

    /**
     * Records one line.
     *
     * @param mixed              $level   The level.
     * @param string|Stringable  $message The message.
     * @param array<mixed>       $context The context, ignored.
     */
    public function log( $level , string|Stringable $message , array $context = [] ) :void
    {
        $this->records[] = [ (string) $level , (string) $message ] ;
    }

    /**
     * The messages recorded at a level.
     *
     * @param string $level The level.
     *
     * @return array<int, string>
     */
    public function messages( string $level ) :array
    {
        return array_values( array_map( fn( array $record ) => $record[ 1 ] , array_filter( $this->records , fn( array $record ) => $record[ 0 ] === $level ) ) ) ;
    }
}
