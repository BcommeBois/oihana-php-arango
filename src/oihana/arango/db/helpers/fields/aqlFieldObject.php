<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\functions\isObject;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression for extracting an object or the first element of an array field.
 *
 * @param string $key   Logical key to use in the resulting AQL object.
 * @param string $value AQL field reference to evaluate (e.g. `'doc.authors'`).
 *
 * @return string AQL key/value snippet extracting the first array element.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldObject( string $key , string $value ): string
{
    return keyValue
    (
        $key ,
        ternary
        (
            isObject( $value ) ,
            $value ,
            ternary( isArray( $value ) , first( $value ) , AQL::NULL )
        )
    );
}