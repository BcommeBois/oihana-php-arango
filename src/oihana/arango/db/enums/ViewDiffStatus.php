<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The status of a View declaration ↔ server comparison, as reported by
 * {@see \oihana\arango\db\results\ViewDiffReport::$status} — the answer to
 * "is the View on the server still what the model declares?".
 *
 * Produced by {@see \oihana\arango\db\traits\ViewManagementTrait::viewDiff()}
 * (and consumed by `viewSync()`, which only acts on `MISSING` and `DRIFTED`).
 *
 * @package oihana\arango\db\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class ViewDiffStatus
{
    use ConstantsTrait ;

    /**
     * The server View diverges from the declaration: a declared field is not
     * indexed, an analyzer differs, or the server indexes something that is
     * no longer declared. `viewSync()` repairs it with `updateProperties()`.
     */
    public const string DRIFTED = 'drifted' ;

    /**
     * The server View matches the declaration — nothing to do.
     */
    public const string IN_SYNC = 'inSync' ;

    /**
     * The comparison is meaningless: the declaration is malformed (no view
     * name, no searched field), a dependency is broken (collection or
     * analyzer not found on the server), or a same-name entity of another
     * type exists. Never created nor synchronized automatically.
     */
    public const string INVALID = 'invalid' ;

    /**
     * The View is declared but does not exist on the server.
     * `viewSync()` creates it from the declaration.
     */
    public const string MISSING = 'missing' ;

    /**
     * The server could not be queried (no database, connection failure) —
     * the report carries the underlying error message.
     */
    public const string UNREACHABLE = 'unreachable' ;
}
