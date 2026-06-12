<?php

namespace oihana\arango\migrations\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The lifecycle status of a migration run, stored as the `actionStatus` of
 * its {@see \oihana\arango\migrations\MigrationAction} tracking document.
 *
 * The values are plain strings (not schema.org status class names) so the
 * tracking collection stays directly queryable from AQL / the API.
 *
 * @package oihana\arango\migrations\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class MigrationStatus
{
    use ConstantsTrait ;

    /**
     * The migration is running (`up()` started, not finished yet). A row
     * left in this state signals a run that crashed mid-flight.
     */
    public const string ACTIVE = 'active' ;

    /**
     * The migration finished successfully.
     */
    public const string COMPLETED = 'completed' ;

    /**
     * The migration failed — its `up()` threw. The run stopped here and the
     * error is kept on the tracking document.
     */
    public const string FAILED = 'failed' ;
}
