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
 * Two guarantees on the name:
 * - an **auto-generated** name is drawn until it is free, so it can never land on a
 *   slot already taken (the single draw allowed a birthday collision, and the loser
 *   was overwritten in silence);
 * - an **explicit** name already bound to a *different* value raises. Rebinding the
 *   same value drops nothing and stays allowed. The comparison is strict, so `1` and
 *   `'1'` count as different — AQL compares a number and a string differently.
 *
 * @throws BindException If the provided variable name is invalid according to ArangoDB
 *                       naming rules, or is already bound to a different value.
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

    $prefix = $isCollection ? Char::AT_SIGN : Char::EMPTY ;

    if ( $to == null )
    {
        // Draw until the slot is free: the name is arbitrary, so retrying costs
        // nothing and removes the birthday collision the single draw allowed.
        do
        {
            $to = prepend( mt_rand( 100000 , 999999 ) , $toPrefix ?? 'q' , Char::UNDERLINE ) ;
        }
        while ( array_key_exists( $prefix . $to , $binds ) ) ;
    }
    elseif ( array_key_exists( $prefix . $to , $binds ) && $binds[ $prefix . $to ] !== $value )
    {
        // Rebinding the *same* value drops nothing, so it stays allowed. A different
        // one would silently replace the first and quietly change what the query asks.
        throw new BindException( sprintf
        (
            'The bind variable "%s" is already bound to a different value: rebinding it would silently drop the first one.' ,
            $prefix . $to
        )) ;
    }

    $binds[ $prefix . $to ] = $value ;
    return formatBindVariable( $to , $isCollection ) ;
}