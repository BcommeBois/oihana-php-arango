<?php

namespace oihana\arango\controllers\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of the model calls a controller announces to its lifecycle hooks.
 *
 * Travels in the init under {@see \oihana\arango\enums\Arango::OPERATION}, so
 * `beforeModelCall()` and `afterModelCall()` — one pair for every verb — can tell
 * which call they are serving. They could not before: a `PATCH` reaches
 * `beforeModelCall()` three times (probe, write, read-back), `getMethod()` answers
 * `PATCH` to all three, and the probe and the read-back carry the same init keys.
 *
 * **Named after what the model does, never after the HTTP verb** — that is precisely
 * the distinction the verb cannot make. `PUT` and `PATCH` share one call site and
 * split into {@see self::REPLACE} and {@see self::UPDATE}; `POST` announces
 * {@see self::INSERT} and then {@see self::GET}.
 *
 * ⚠ **It names the operation the caller asked for, not every round trip to the
 * database.** `list()` runs its count, its facet counts and its bounds under the same
 * init; `delete()` runs its existence probe under the init of the deletion, on purpose
 * — a probe and a write that could disagree on scope are worse than one announcement.
 *
 * ⚠ **Three read-shaped values, and they are not interchangeable.** {@see self::EXIST}
 * is a probe (the model's `exist()`, answering a boolean); {@see self::GET} is a read
 * of one document; a read that follows a write is a `GET` too, flagged with
 * {@see \oihana\arango\enums\Arango::AFTER_WRITE} rather than given a value of its own
 * — a hook scoping "every read" must not be able to forget it.
 *
 * @example
 * ```php
 * protected function beforeModelCall( ?Request $request , array &$init ) : void
 * {
 *     parent::beforeModelCall( $request , $init ) ;
 *
 *     if ( ( $init[ Arango::OPERATION ] ?? null ) === ModelOperation::INSERT )
 *     {
 *         // The real insertion — not the read that hands the document back.
 *     }
 * }
 * ```
 *
 * @package oihana\arango\controllers\enums
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
final class ModelOperation
{
    use ConstantsTrait ;

    /**
     * A per-group or whole-collection count — the model's `count()`.
     */
    public const string COUNT = 'count' ;

    /**
     * A removal — the model's `delete()`, or an edge removal.
     *
     * Covers the existence probe that precedes it: both run under one init so they
     * cannot disagree on the scope a hook poses.
     */
    public const string DELETE = 'delete' ;

    /**
     * An existence probe — the model's `exist()`, which answers a boolean and reads
     * no document. A hook that shapes a projection has nothing to do here; a hook
     * that poses a scope has everything to do here, since the probe is what turns an
     * out-of-scope document into a `404`.
     */
    public const string EXIST = 'exist' ;

    /**
     * The read of a single document — the model's `get()`.
     *
     * Also the read a controller runs **after** a write to hand the document back
     * through the projection, which is flagged with
     * {@see \oihana\arango\enums\Arango::AFTER_WRITE}: it is a `get` in every respect,
     * and a hook that scopes reads must reach it too.
     */
    public const string GET = 'get' ;

    /**
     * A creation — the model's `insert()`.
     */
    public const string INSERT = 'insert' ;

    /**
     * The most recent document — the model's `last()`.
     */
    public const string LAST = 'last' ;

    /**
     * A listing — the model's `list()`, and the counts and bounds it answers with.
     */
    public const string LIST = 'list' ;

    /**
     * A full overwrite — the model's `replace()`, reached through `PUT`.
     */
    public const string REPLACE = 'replace' ;

    /**
     * A graph traversal answering the vertices around one document.
     *
     * Neither {@see self::LIST} nor {@see self::GET}: the call goes to the edges
     * model, not to the controller's own, and no `FILTER` over the collection would
     * describe it.
     */
    public const string TRAVERSE = 'traverse' ;

    /**
     * The same traversal, answering only its first vertex.
     */
    public const string TRAVERSE_FIRST = 'traverseFirst' ;

    /**
     * A partial write — the model's `update()`, reached through `PATCH`.
     */
    public const string UPDATE = 'update' ;
}
