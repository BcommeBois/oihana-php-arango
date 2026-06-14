<?php

namespace oihana\arango\commands\rotation;

use DateTimeImmutable;

/**
 * A dump archive entry — the unit the rotation engine reasons about.
 *
 * Hydrated from an `$init` array keyed by the class constants, e.g.
 * `new Archive([ Archive::PATH => …, Archive::BUCKET => …, Archive::DATE => …, Archive::SIZE => … ])`.
 *
 * @package oihana\arango\commands\rotation
 * @since 1.2.0
 * @author Marc Alcaraz
 */
class Archive
{
    /**
     * Creates a new Archive.
     * @param array|object|null $init Initial values keyed by the class constants.
     */
    public function __construct( array|object|null $init = null )
    {
        $init = (array) ( $init ?? [] ) ;

        if( isset( $init[ self::PATH ] ) )
        {
            $this->path   = (string) $init[ self::PATH ] ;
        }

        if( isset( $init[ self::BUCKET ] ) )
        {
            $this->bucket = (string) $init[ self::BUCKET ] ;
        }

        if( isset( $init[ self::DATE ] ) )
        {
            $this->date   = $init[ self::DATE ] ;
        }

        if( isset( $init[ self::SIZE ] ) )
        {
            $this->size = (int) $init[ self::SIZE ] ;
        }
    }

    /**
     * The `bucket` property name — the archive suffix signature.
     */
    public const string BUCKET = 'bucket' ;

    /**
     * The `date` property name — the date embedded in the archive name.
     */
    public const string DATE = 'date' ;

    /**
     * The `path` property name — the absolute archive path.
     */
    public const string PATH = 'path' ;

    /**
     * The `size` property name — the archive size in bytes.
     */
    public const string SIZE = 'size' ;

    /**
     * The bucket the archive belongs to (its suffix signature).
     */
    public string $bucket = '' ;

    /**
     * The date embedded at the start of the archive file name.
     */
    public ?DateTimeImmutable $date = null ;

    /**
     * The absolute archive path.
     */
    public string $path = '' ;

    /**
     * The archive size, in bytes.
     */
    public int $size = 0 ;
}
