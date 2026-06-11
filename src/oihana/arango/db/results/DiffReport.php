<?php

namespace oihana\arango\db\results;

use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;

/**
 * The typed result of a declaration ↔ server comparison for one structure
 * object — a collection, the declared indexes of a collection, or an
 * ArangoSearch View ({@see DiffKind}).
 *
 * Produced by the diff primitives (`collectionDiff()` / `indexesDiff()` /
 * `viewDiff()`) and returned by their sync counterparts with `$applied`
 * set when something was actually created or updated. The model-level
 * `diagnose()` / `repair()` and the `doctor` command aggregate lists of
 * these reports.
 *
 * `$changes` carries one human-readable line per detected difference
 * (e.g. `places.fields.description : not indexed on the server`), empty
 * when the object is {@see DiffStatus::IN_SYNC}.
 *
 * @package oihana\arango\db\results
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
readonly class DiffReport
{
    /**
     * @param string             $name    The name of the object the report is about (View name, collection name, …).
     * @param string             $status  One of the {@see DiffStatus} constants.
     * @param array<int, string> $changes One line per difference, error or warning — empty when in sync.
     * @param bool               $applied Whether the sync counterpart actually created or updated the object.
     * @param string             $kind    The kind of object — one of the {@see DiffKind} constants.
     */
    public function __construct
    (
        public string $name ,
        public string $status ,
        public array  $changes = [] ,
        public bool   $applied = false ,
        public string $kind    = DiffKind::VIEW ,
    )
    {
    }

    /**
     * Returns true when the object matches its declaration ({@see DiffStatus::IN_SYNC}).
     *
     * @return bool
     */
    public function inSync() : bool
    {
        return $this->status === DiffStatus::IN_SYNC ;
    }
}
