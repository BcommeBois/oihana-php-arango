<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\keyValue;

/**
 * Generate an AQL key/value expression for a conditional scalar field (`Field::WHEN`).
 *
 * Guards the projected value behind a condition — the SQL `CASE WHEN … THEN … ELSE …`
 * shape, rendered as an AQL ternary:
 *
 * ```
 * price: doc.visibility == 'public' ? doc.price : null
 * ```
 *
 * The key is **always present**; only the value switches to the `else` branch (default
 * `null`) when the condition is false — it never omits the key (that would require a
 * `MERGE`, intentionally out of scope). The condition is compiled by
 * {@see buildWhenCondition()} (inline, no bind variables) and the else branch by
 * {@see resolveWhenElse()}.
 *
 * The `$then` expression is the already-built scalar projection — the bare field
 * reference (`doc.price`) or, when the field also declares `Field::ALTERS`, the
 * transformed value (`LOWER(TRIM(doc.price))`). The caller ({@see aqlFields()}) builds it.
 *
 * @param string $key  The output key/label (possibly already double-quoted).
 * @param string $then The value projected when the condition holds (e.g. `doc.price`).
 * @param mixed  $when The `Field::WHEN` condition descriptor.
 * @param mixed  $else The `Field::ELSE` descriptor (literal or `[ Field::PROPERTY => '<attr>' ]`; default `null`).
 * @param string $doc  The document reference for the condition/else operands (default: `AQL::DOC`).
 *
 * @return string The AQL key/value snippet, e.g. `price:doc.visibility == 'public' ? doc.price : null`.
 *
 * @throws UnsupportedOperationException If the condition descriptor is malformed.
 * @throws ValidationException           If a condition or else attribute name is unsafe.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.3.0
 * @author Marc Alcaraz
 */
function aqlFieldConditional
(
    string $key ,
    string $then ,
    mixed  $when ,
    mixed  $else = null ,
    string $doc  = AQL::DOC ,
)
: string
{
    $condition = buildWhenCondition( $when , $doc ) ;
    $otherwise = resolveWhenElse( $else , $doc ) ;

    // <condition> ? <then> : <else>
    return keyValue( $key , ternary( $condition , $then , $otherwise ) ) ;
}
