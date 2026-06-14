<?php

namespace oihana\arango\commands\enums;

/**
 * The canonical pattern of a dump archive file name.
 *
 * Shape: `{ISO date}-{database}[-partial][-{label}].tar[.gz[.enc]]`, e.g.
 * `2026-06-01T14:30:00-mydb-partial-pre-migration.tar.gz.enc`.
 *
 * Single source of truth shared by the dump listing, the restore lookup and the
 * rotation engine.
 *
 * @package oihana\arango\commands\enums
 * @since 1.2.0
 * @author Marc Alcaraz
 */
class ArchivePattern
{
    /**
     * The regular expression matching a dump archive file name.
     */
    public const string REGEXP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-.+\.tar(\.gz(\.enc)?)?$/' ;
}
