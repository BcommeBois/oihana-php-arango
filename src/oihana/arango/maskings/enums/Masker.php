<?php

namespace oihana\arango\maskings\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The attribute masking functions (the `type` of a masking rule).
 *
 * The names mirror the `arangodump` masking vocabulary so the **same** rule set
 * drives the native binary (Enterprise) and the portable PHP engine.
 *
 * @package oihana\arango\maskings\enums
 * @since 1.2.0
 * @author Marc Alcaraz
 */
class Masker
{
    use ConstantsTrait ;

    public const string CREDIT_CARD   = 'creditCard' ;
    public const string DATETIME      = 'datetime' ;
    public const string DECIMAL       = 'decimal' ;
    public const string EMAIL         = 'email' ;
    public const string INTEGER       = 'integer' ;
    public const string PHONE         = 'phone' ;
    public const string RANDOM        = 'random' ;
    public const string RANDOM_STRING = 'randomString' ;
    public const string XIFY_FRONT    = 'xifyFront' ;
    public const string ZIP           = 'zip' ;
}
