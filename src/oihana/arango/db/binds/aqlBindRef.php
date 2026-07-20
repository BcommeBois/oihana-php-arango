<?php

namespace oihana\arango\db\binds;

use oihana\exceptions\BindException;

/**
 * Declares a reference to an AQL bind variable by name — a placeholder for a
 * value supplied at query time, never inlined.
 *
 * Unlike {@see aqlBind()}, it registers no value and touches no bind map: it
 * only names the slot. The value of `@name` is contributed by the caller via
 * the existing top-level bind mechanism (`AQL::BINDS`), which merges into the
 * query's single `bindVars` map.
 *
 * The returned {@see AqlBindReference} is honored by the projection condition
 * compiler ({@see \oihana\arango\db\helpers\fields\buildWhenLeaf()}) on both the
 * attribute (left) and the value (right) side of a leaf, so it composes with
 * `Field::WHERE` and `Field::WHEN`.
 *
 * @param string $name The bind variable name, without the leading `@`.
 *
 * @return AqlBindReference The bind reference value object.
 *
 * @throws BindException If `$name` is not a valid ArangoDB bind variable name.
 *
 * @example
 * ```php
 * aqlBindRef( 'allowedRegions' )->toAql() ; // '@allowedRegions'
 * aqlBindRef( '1bad' ) ;                     // throws BindException
 * ```
 *
 * @package oihana\arango\db\binds
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
function aqlBindRef( string $name ) : AqlBindReference
{
    assertBindVariable( $name ) ;
    return new AqlBindReference( $name ) ;
}
