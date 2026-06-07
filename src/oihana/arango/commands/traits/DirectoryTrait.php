<?php

namespace oihana\arango\commands\traits;

use oihana\arango\commands\enums\ArangoCommandParam;

/**
 * Provides the dump/restore directory property and its initializer.
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
trait DirectoryTrait
{
    /**
     * The dump/restore directory.
     * @var ?string
     */
    public ?string $directory = null ;

    /**
     * Initializes the dump/restore directory from an init array.
     * @param array $init The init definition, possibly containing {@see ArangoCommandParam::DIRECTORY}.
     * @return static
     */
    public function initializeDirectory( array $init = [] ) :static
    {
        $this->directory = $init[ ArangoCommandParam::DIRECTORY ] ?? $this->directory ;
        return $this ;
    }
}
