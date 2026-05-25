<?php

namespace oihana\arango\commands\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of the available options in the DocumentsCommand class.
 *
 * @package oihana\robots\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class DocumentsCommandOption
{
    use ConstantsTrait ;

    /**
     * The 'optimized' option.
     */
    public const string OPTIMIZED          = 'optimized' ;
    public const string OPTIMIZED_SHORTCUT = 'o' ;
}