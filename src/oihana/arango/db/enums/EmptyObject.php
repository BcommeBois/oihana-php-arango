<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The empty AQL object literal, in its compact and spaced renderings.
 *
 * Used as the default document/expression when none is supplied, and as the
 * output of {@see \oihana\arango\db\helpers\aqlDocument()} for an empty input.
 *
 * @package oihana\arango\db\enums
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
class EmptyObject
{
    use ConstantsTrait ;

    /**
     * The compact empty object literal: `{}`.
     */
    public const string COMPACT = '{}' ;

    /**
     * The spaced empty object literal: `{ }`.
     */
    public const string SPACED = '{ }' ;
}
