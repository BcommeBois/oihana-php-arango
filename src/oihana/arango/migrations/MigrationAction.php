<?php

namespace oihana\arango\migrations ;

use org\schema\actions\UpdateAction ;

/**
 * The tracking document of a migration run — one row per applied version in
 * the `migrations` collection of a database.
 *
 * Modelled as a schema.org {@see UpdateAction} (the act of changing the
 * state of the database) so the tracking collection is a real, queryable
 * schema.org dataset rather than an opaque bookkeeping table. The inherited
 * vocabulary carries everything a run needs:
 *
 * - `_key` / `identifier` : the migration version (e.g. `20260612090000_AddPlaceKind`) ;
 * - `name` / `description` : human labels ;
 * - `actionStatus`        : the run lifecycle ({@see enums\MigrationStatus}) ;
 * - `startTime` / `endTime` : ISO timestamps ;
 * - `agent`               : who ran it (`user@host`) ;
 * - `error`               : the failure message + trace, when it failed ;
 * - `result`              : a short summary of what the run did ;
 * - `instrument`          : the library version that ran it ;
 * - `additionalType`      : the event family ({@see enums\MigrationKind}) —
 *   `UpdateAction` for a versioned migration, `CreateAction` for a
 *   `doctor --apply` journal entry.
 *
 * The only field schema.org has no equivalent for is added here:
 *
 * @package oihana\arango\migrations
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class MigrationAction extends UpdateAction
{
    /**
     * The `gitCommit` property key.
     */
    public const string GIT_COMMIT = 'gitCommit' ;

    /**
     * The hash of the git commit the migration (or doctor apply) was run
     * from — the history link between the database and the source tree.
     * `null` when the working directory is not a git repository.
     *
     * @var string|null
     */
    public ?string $gitCommit = null ;
}
