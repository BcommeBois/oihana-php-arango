<?php

namespace oihana\arango\migrations\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The family of event recorded in the tracking collection, stored as the
 * `additionalType` of a {@see \oihana\arango\migrations\MigrationAction}.
 *
 * One collection, two families: the versioned migrations applied by the
 * `migrate` command, and the declarative apply events journaled by
 * `doctor --apply`. The migration runner only ever reads {@see MIGRATE}
 * rows to compute what is pending — {@see DOCTOR} rows are an audit trail,
 * never replayed.
 *
 * @package oihana\arango\migrations\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class MigrationKind
{
    use ConstantsTrait ;

    /**
     * A `doctor --apply` event — a declarative structure object created or
     * repaired. Recorded as a schema.org `CreateAction`. Audit only.
     */
    public const string DOCTOR = 'CreateAction' ;

    /**
     * A versioned migration applied by the `migrate` command. Recorded as a
     * schema.org `UpdateAction`. Drives the pending computation.
     */
    public const string MIGRATE = 'UpdateAction' ;
}
