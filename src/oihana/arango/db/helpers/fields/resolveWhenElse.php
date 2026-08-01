<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\aqlValue;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\core\strings\key;

/**
 * Resolve the `Field::ELSE` branch of a conditional field into an AQL expression.
 *
 * Two forms:
 * - **Attribute reference** — `[ Field::PROPERTY => '<attr>' ]` → `doc.<attr>` (validated by
 *   {@see assertAttributeName()}), so the fallback can mirror another document attribute.
 * - **Literal** — anything else (a scalar, an array of scalars, or `null` — the default) is
 *   inlined via {@see aqlValue()}.
 *
 * ⚠️ A string literal is quoted by `aqlValue()` **unless it already looks like AQL** — which is
 * deliberate (it is what lets a condition compare two attributes, `[ 'price', 'gt',
 * 'doc.minPrice' ]`). A few real-world literals fall into that net: `'N/A'` and `'en/US'` have
 * the shape of a document handle (`collection/key`) and are emitted raw, which ArangoDB then
 * reads as code and refuses (error 1203). Declare an ambiguous literal — one containing a `/`
 * or a `.` — with {@see \oihana\core\strings\betweenQuotes()}, the explicit « this really is a
 * string » form, which passes through untouched:
 *
 * ```php
 * Field::ELSE => betweenQuotes( 'N/A' ) ,   // →  : 'N/A'
 * ```
 *
 * @param mixed $else The `Field::ELSE` descriptor (default `null`).
 * @param string $doc The document reference (default: `AQL::DOC`).
 *
 * @return string The AQL expression for the else branch (`null`, `0`, `'unknown'`, `doc.basePrice`, …).
 *
 * @throws UnsupportedOperationException
 * @throws ValidationException If the referenced attribute name is unsafe.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.3.0
 * @author Marc Alcaraz
 */
function resolveWhenElse( mixed $else = null , string $doc = AQL::DOC ): string
{
    if ( is_array( $else ) && isset( $else[ Field::PROPERTY ] ) )
    {
        $attribute = $else[ Field::PROPERTY ] ;
        assertAttributeName( $attribute ) ;
        return key( $attribute , $doc ) ;
    }

    return aqlValue( $else ) ;
}
