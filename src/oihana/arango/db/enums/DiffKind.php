<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The kind of server object a {@see \oihana\arango\db\results\DiffReport}
 * is about — set by the structure-diff primitives so a mixed report list
 * (the `doctor` action) can label each line unambiguously.
 *
 * @package oihana\arango\db\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class DiffKind
{
    use ConstantsTrait ;

    /**
     * The report is about a collection (existence, type) — see
     * {@see \oihana\arango\db\traits\CollectionManagementTrait::collectionDiff()}.
     */
    public const string COLLECTION = 'collection' ;

    /**
     * The report is about the declared indexes of a collection — see
     * {@see \oihana\arango\db\traits\CollectionManagementTrait::indexesDiff()}.
     */
    public const string INDEXES = 'indexes' ;

    /**
     * The report is about an ArangoSearch View — see
     * {@see \oihana\arango\db\traits\ViewManagementTrait::viewDiff()}.
     */
    public const string VIEW = 'view' ;
}
