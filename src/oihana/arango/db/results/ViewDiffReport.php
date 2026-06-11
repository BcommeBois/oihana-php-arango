<?php

namespace oihana\arango\db\results;

use oihana\arango\db\enums\ViewDiffStatus;

/**
 * The typed result of a View declaration ↔ server comparison — what
 * {@see \oihana\arango\db\traits\ViewManagementTrait::viewDiff()} returns,
 * and what `viewSync()` returns after acting on it.
 *
 * `$changes` carries one human-readable line per detected difference
 * (e.g. `places.fields.description : not indexed on the server`), empty
 * when the View is {@see ViewDiffStatus::IN_SYNC}.
 *
 * @package oihana\arango\db\results
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
readonly class ViewDiffReport
{
    /**
     * @param string             $name    The View name the report is about.
     * @param string             $status  One of the {@see ViewDiffStatus} constants.
     * @param array<int, string> $changes One line per difference, error or warning — empty when in sync.
     * @param bool               $applied Whether `viewSync()` actually created or updated the View.
     */
    public function __construct
    (
        public string $name ,
        public string $status ,
        public array  $changes = [] ,
        public bool   $applied = false ,
    )
    {
    }

    /**
     * Returns true when the View matches its declaration ({@see ViewDiffStatus::IN_SYNC}).
     *
     * @return bool
     */
    public function inSync() : bool
    {
        return $this->status === ViewDiffStatus::IN_SYNC ;
    }
}
