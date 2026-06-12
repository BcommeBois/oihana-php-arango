<?php

namespace oihana\arango\commands\traits;

use oihana\enums\Char;

/**
 * The migration settings of the `migrate` action of `command:arangodb`,
 * supplied via the command init keys
 * ({@see \oihana\arango\commands\enums\ArangoCommandParam}): where the
 * version files live, the namespace they are declared in, and the tracking
 * collection name.
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait ArangoMigrationsTrait
{
    /**
     * The tracking collection name (one per database).
     *
     * @var string
     */
    public string $migrationsCollection = 'migrations' ;

    /**
     * The acting agent (`user@host`) stamped on the tracking documents —
     * shared by the `migrate` runs and the `doctor --apply` journal.
     *
     * @return string
     */
    protected function agent() :string
    {
        return get_current_user() . '@' . gethostname() ;
    }

    /**
     * The current git commit hash, or null outside a git repository — the
     * history link stamped on every tracking document.
     *
     * @return string|null
     */
    protected function gitCommit() :?string
    {
        $hash = @exec( 'git rev-parse HEAD 2>/dev/null' , $unused , $code ) ;
        return $code === 0 && is_string( $hash ) && $hash !== Char::EMPTY ? $hash : null ;
    }

    /**
     * The directory holding the `Version*.php` migration files.
     *
     * @var string|null
     */
    public ?string $migrationsPath = null ;

    /**
     * The PHP namespace the version classes are declared in (e.g. `fr\bouney\migrations`).
     *
     * @var string
     */
    public string $migrationsNamespace = '' ;
}
