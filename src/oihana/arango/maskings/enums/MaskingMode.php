<?php

namespace oihana\arango\maskings\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The document-level masking modes (the per-collection `type`).
 *
 *  - `exclude`   : the collection is ignored entirely ;
 *  - `structure` : only the collection metadata is kept, no document data ;
 *  - `masked`    : structure and data, with the attribute rules applied ;
 *  - `full`      : a complete dump, no masking.
 *
 * @package oihana\arango\maskings\enums
 * @since 1.2.0
 * @author Marc Alcaraz
 */
class MaskingMode
{
    use ConstantsTrait ;

    public const string EXCLUDE   = 'exclude' ;
    public const string FULL      = 'full' ;
    public const string MASKED    = 'masked' ;
    public const string STRUCTURE = 'structure' ;
}
