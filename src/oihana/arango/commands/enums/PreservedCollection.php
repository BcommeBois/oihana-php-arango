<?php

namespace oihana\arango\commands\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The name of the collection where the `preserve` action sets documents aside.
 *
 * The name is **derived**, never chosen : `<collection>__preserved`. That is what
 * lets `reinject` find the documents set aside without being told where they are.
 * The double underscore keeps a collision with a real collection unlikely.
 *
 * @package oihana\arango\commands\enums
 * @since 1.10.0
 * @author Marc Alcaraz
 */
class PreservedCollection
{
    use ConstantsTrait ;

    /**
     * The suffix appended to a collection name to name its backup.
     */
    public const string SUFFIX = '__preserved' ;

    /**
     * The name of the backup of a collection.
     *
     * @param string $collection The collection whose documents are set aside.
     * @return string The backup name, `<collection>__preserved`.
     */
    public static function backupOf( string $collection ) :string
    {
        return $collection . self::SUFFIX ;
    }

    /**
     * Indicates whether a collection name is the name of a backup.
     *
     * @param string $collection The collection name to test.
     * @return bool True when the name ends with {@see SUFFIX}.
     */
    public static function isBackup( string $collection ) :bool
    {
        return str_ends_with( $collection , self::SUFFIX ) ;
    }
}
