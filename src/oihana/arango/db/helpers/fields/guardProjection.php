<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\Logic;
use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\isObject;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\predicates;

/**
 * Wrap an already-built **structural** projection behind an optional guard, so the
 * field can yield `null` (or a `Field::ELSE` fallback) instead of an object rebuilt
 * out of nothing.
 *
 * A structural projection ({@see aqlFieldDocument()}) reconstructs an object attribute
 * by attribute. When the source attribute is missing, each line reads an attribute of a
 * nothing — which AQL resolves to `null` without error — and the object is emitted all
 * the same, so an empty slot comes back dressed:
 *
 * ```json
 * { "_key": null , "name": null , "url": "https://base/things/" }
 * ```
 *
 * Two markers, both opt-in, guard it:
 *
 * - {@see Field::NULLABLE} — the declared intent « no source, no object », compiled to
 *   an `IS_OBJECT()` test on `$source`. It is deliberately a **type** test and not a
 *   `!= null` comparison: an attribute that exists but is not an object (a string, a
 *   number) rebuilds the very same object of nulls, and the house style tests the type
 *   ({@see aqlFieldArray()}, {@see aqlFieldObject()}).
 * - {@see Field::WHEN} — the general mechanism, sharing the condition grammar of the
 *   scalar conditional projection ({@see buildWhenCondition()}). It is compiled against
 *   `$doc`, the **parent** reference, not the rebuilt sub-document: that is what keeps
 *   the read gate of {@see conditionReadsDeniedField()} correct without a line of its
 *   own, since it gates a `Field::WHEN` against the projection of the current level.
 *
 * Declared together they compose with `&&`. A single condition is emitted bare, a pair
 * between parentheses — so the guard never depends on the surrounding precedence.
 *
 * With neither marker the value is returned untouched: the emitted AQL of every existing
 * projection is unchanged, byte for byte.
 *
 * @param string                 $value   The already-built projection value (e.g. `{name:doc.thing.name}`).
 * @param array<array-key,mixed> $options The field definition (reads NULLABLE / WHEN / ELSE).
 * @param string                 $doc     The parent document reference the condition and the else branch read from.
 * @param string                 $source  The source attribute the projection is rebuilt from (e.g. `doc.thing`).
 *
 * @return string The value, guarded when asked for: `<cond> ? <value> : <else>`.
 *
 * @throws UnsupportedOperationException If the `Field::WHEN` descriptor is malformed.
 * @throws ValidationException           If a condition or else attribute name is unsafe.
 *
 * @example
 * ```php
 * guardProjection( '{name:doc.thing.name}' , [ Field::NULLABLE => true ] , 'doc' , 'doc.thing' ) ;
 * // IS_OBJECT(doc.thing) ? {name:doc.thing.name} : null
 *
 * guardProjection( '{email:doc.contact.email}' , [ Field::WHEN => [ 'visibility' , 'public' ] ] , 'doc' , 'doc.contact' ) ;
 * // doc.visibility == 'public' ? {email:doc.contact.email} : null
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\db\helpers\fields
 * @since   1.6.0
 */
function guardProjection( string $value , array $options , string $doc , string $source ) : string
{
    $conditions = [] ;

    if ( ( $options[ Field::NULLABLE ] ?? false ) === true )
    {
        $conditions[] = isObject( $source ) ;
    }

    $when = $options[ Field::WHEN ] ?? null ;
    if ( $when !== null )
    {
        $conditions[] = buildWhenCondition( $when , $doc ) ;
    }

    if ( count( $conditions ) === 0 )
    {
        return $value ;
    }

    // A lone condition is emitted bare — predicates() would parenthesize it, and the
    // guard of the overwhelmingly common case (Field::NULLABLE alone) reads better
    // without a pair of parentheses that carries no meaning.
    $condition = count( $conditions ) === 1
               ? $conditions[ 0 ]
               : predicates( $conditions , Logic::AND , true ) ;

    return ternary( $condition , $value , resolveWhenElse( $options[ Field::ELSE ] ?? null , $doc ) ) ;
}
