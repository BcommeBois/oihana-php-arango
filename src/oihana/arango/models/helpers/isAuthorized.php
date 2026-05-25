<?php

namespace oihana\arango\models\helpers;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;

/**
 * Decides whether a field projection is allowed for the current request.
 *
 * Reads the optional `Field::REQUIRES` permission subject(s) declared on the
 * field definition, then defers the actual decision to a backend-agnostic
 * `Closure(string $subject): bool` injected through `$init[Arango::AUTHORIZER]`.
 *
 * The framework remains agnostic of the underlying authorization layer
 * (Casbin, OPA, custom, ...) — the consumer is responsible for binding the
 * callable to a real enforcer and a request-scoped user identifier.
 *
 * Resolution rules:
 * - No `Field::REQUIRES` declared on the definition → `true` (no gating).
 * - `Field::REQUIRES` resolves to an empty list → `true` (no gating).
 * - No `Arango::AUTHORIZER` injected, or value is not callable → `true`
 *   (authorization layer disabled, fail open).
 * - One or more subjects declared → `true` if **at least one** subject is
 *   granted by the callable (logical OR).
 *
 * @param array<array-key,mixed> $definition Field definition.
 *                                           Reads `Field::REQUIRES`.
 * @param array<array-key,mixed> $init       The request-level init array.
 *                                           Reads `Arango::AUTHORIZER`.
 *
 * @return bool `true` when the projection is allowed, `false` when every
 *              declared subject was refused.
 *
 * @example Single subject
 * ```php
 * $definition[ Field::REQUIRES ] = 'users.roles:list' ;
 * isAuthorized( $definition , [ Arango::AUTHORIZER => fn() => true ] ) ; // true
 * isAuthorized( $definition , [ Arango::AUTHORIZER => fn() => false ] ) ; // false
 * ```
 *
 * @example OR over a list
 * ```php
 * $definition[ Field::REQUIRES ] = [ 'users.roles:list' , 'users.roles:admin' ] ;
 * $init[ Arango::AUTHORIZER ]    = fn( string $s ) : bool => $s === 'users.roles:admin' ;
 * isAuthorized( $definition , $init ) ; // true (admin matched)
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.0.0
 */
function isAuthorized( array $definition , array $init = [] ) : bool
{
    $requires = $definition[ Field::REQUIRES ] ?? null ;

    if ( $requires === null )
    {
        return true ;
    }

    $subjects = is_array( $requires )
              ? array_values( array_filter( $requires , 'is_string' ) )
              : ( is_string( $requires ) ? [ $requires ] : [] ) ;

    if ( count( $subjects ) === 0 )
    {
        return true ;
    }

    $authorizer = $init[ Arango::AUTHORIZER ] ?? null ;

    if ( !is_callable( $authorizer ) )
    {
        return true ;
    }

    return array_any( $subjects , fn( $subject ) => $authorizer( $subject ) === true ) ;
}
