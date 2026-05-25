<?php

namespace oihana\arango\db\binds;

use oihana\enums\Char;
use oihana\exceptions\BindException;
use function oihana\core\strings\prepend;

/**
 * Binds a value to an AQL variable.
 *
 * If `$to` is not provided, a unique variable name will be automatically `queryId` (e.g. `query_123456`).
 *
 * If `$isCollection` is `true`, the variable will be prefixed with `@@`
 * (used for collection binding in AQL). Otherwise, it uses a single `@`.
 *
 * @param mixed       $value        The value to bind (e.g. scalar, array, object).
 * @param array       &$binds       The array of all existing bindings. It is updated by reference.
 * @param string|null $to           The bind variable name (without `@`). If `null`, one is auto-generated.
 * @param string|null $toPrefix     The optional prefix to prepend the variable name.
 * @param bool        $isCollection Whether the binding targets a collection (`@@`) or a value (`@`).
 *
 * @return string The formatted AQL bind variable (e.g. `'@userId'` or `'@@collection'`).
 *
 * @throws BindException If the provided variable name is invalid according to ArangoDB naming rules.
 *
 * @example
 * ```php
 * $binds = [];
 *
 * // Manual variable name
 * $var = aqlBind('John', $binds, 'userId') ;
 * // $var   => '@userId'
 * // $binds => [ 'userId' => 'John' ]
 *
 * // Auto-generated variable name
 * $var = aqlBind(42, $binds) ;
 * // $var   => '@q_123456'
 * // $binds => [ 'userId' => 'John', 'q_123456' => 42 ]
 * ```
 *
 * @package oihana\arango\db\binds
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function aqlBind
(
    mixed   $value ,
    array   &$binds       = [] ,
    ?string $to           = null ,
    ?string $toPrefix     = null ,
    bool    $isCollection = false
)
:string
{
    assertBindVariable( $to ) ;
    if ( $to == null )
    {
        $to = mt_rand( 100000 , 999999 ) ;
        $to = prepend( $to, $toPrefix ?? 'q' , Char::UNDERLINE ) ;
    }
    $binds[ ( $isCollection ? Char::AT_SIGN : Char::EMPTY ) . $to ] = $value ;
    return formatBindVariable( $to , $isCollection ) ;
}