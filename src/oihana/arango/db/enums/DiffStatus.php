<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The status of a declaration ↔ server comparison, as reported by
 * {@see \oihana\arango\db\results\DiffReport::$status} — the answer to
 * "is the server object still what the model declares?", for a collection,
 * the indexes of a collection or an ArangoSearch View ({@see DiffKind}).
 *
 * Produced by the diff primitives (`collectionDiff()` / `indexesDiff()` /
 * `viewDiff()`); the sync counterparts only act on `MISSING` and `DRIFTED`.
 *
 * @package oihana\arango\db\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class DiffStatus
{
    use ConstantsTrait ;

    /**
     * The server object diverges from the declaration: a declared field or
     * index is missing, a definition differs, or the server carries
     * something that is no longer declared. The sync counterpart repairs it
     * (`updateProperties()` for a View; indexes are immutable — repairing a
     * drifted index means drop + recreate, only done when forced).
     */
    public const string DRIFTED = 'drifted' ;

    /**
     * The server object matches the declaration — nothing to do.
     */
    public const string IN_SYNC = 'inSync' ;

    /**
     * The comparison is meaningless: the declaration is malformed, a
     * dependency is broken (collection or analyzer not found on the
     * server), or a same-name entity of another type exists. Never created
     * nor synchronized automatically.
     */
    public const string INVALID = 'invalid' ;

    /**
     * The object is declared but does not exist on the server.
     * The sync counterpart creates it from the declaration.
     */
    public const string MISSING = 'missing' ;

    /**
     * The server could not be queried (no database, connection failure) —
     * the report carries the underlying error message.
     */
    public const string UNREACHABLE = 'unreachable' ;
}
