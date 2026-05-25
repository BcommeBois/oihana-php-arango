<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Defines the type of operation performed during an UPSERT in ArangoDB.
 *
 * This class provides constants to indicate whether a document was:
 * - inserted (`INSERT`)
 * - updated (`UPDATE`)
 * - replaced (`REPLACE`)
 *
 * These values can be used, for example, in conjunction with `Clause::WITH_STATUS`
 * to return the type of operation in an UPSERT query:
 *
 * Example usage:
 * ```php
 * $query = $this->upsert([
 *     'search' => [['foo', 'bar']],
 *     'insert' => [['foo', 'bar']],
 *     'update' => [['foo', 'baz']],
 *     'return' => Clause::WITH_STATUS
 * ]);
 *
 * // The RETURN clause will produce:
 * // RETURN { doc: NEW, type: OLD ? UpsertType::UPDATE : UpsertType::INSERT }
 * ```
 *
 * @package oihana\arango\db\enums
 */
class UpsertType
{
    use ConstantsTrait ;

    /**
     * Document was inserted because no match was found.
     */
    public const string INSERT = 'insert' ;

    /**
     * Document was replaced (entire document overwrite).
     */
    public const string REPLACE = 'replace' ;

    /**
     * Document was updated (partial update of existing fields).
     */
    public const string UPDATE = 'update'  ;
}