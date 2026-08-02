<?php

namespace oihana\arango\db\helpers\fields;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use function oihana\arango\db\functions\documents\translate;
use function oihana\arango\db\functions\isObject;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression for a translated document field.
 *
 * This helper constructs an expression suitable for inclusion in a `RETURN { ... }` block.
 * It returns the translated value of a field using the `TRANSLATE()` function if a language
 * code is provided. Otherwise, it falls back to the original field value.
 *
 * Behavior:
 * - `$key` is used as the key in the resulting AQL object.
 * - `$doc` is the document variable or expression containing the field.
 * - `$keyName` allows specifying a different property name in the document; defaults to `$key`.
 * - `$lang` is the optional language code for translation; if null, the original value is returned.
 *
 * Note: the language is the FOURTH argument; the third is `$keyName`.
 *
 * Example usage:
 * ```php
 * // Translate the "title" field to French (lang is the 4th argument)
 * aqlFieldTranslate('title', 'doc', null, 'fr');
 * // Produces: title:IS_OBJECT(doc.title) ? TRANSLATE("fr",doc.title,"") : null
 *
 * // No translation (lang null) → original field
 * aqlFieldTranslate('description', 'doc');
 * // Produces: description:doc.description
 *
 * // Different property name ($keyName) AND a language
 * aqlFieldTranslate('label', 'doc', 'name', 'en');
 * // Produces: label:IS_OBJECT(doc.name) ? TRANSLATE("en",doc.name,"") : null
 * ```
 *
 * Why the `IS_OBJECT()` guard:
 *
 * `TRANSLATE()` looks the language up **in a document**, so it needs its second argument to
 * be one. Handed anything else — an absent attribute, or a plain string left over from a
 * pre-i18n record — it returns `null` **and raises an AQL warning** (`1542, invalid argument
 * type in call to function 'TRANSLATE()'`), once per row concerned. That warning is not
 * cosmetic: a query run with the `failOnWarning` cursor option **fails outright** (HTTP 400),
 * so a single un-translated document could break a whole listing.
 *
 * The guard asks the question first and yields `null` itself, which is exactly what the
 * unguarded form returned — **no projected value changes**, the warning simply stops being
 * raised. The three outcomes, measured against a real server:
 *
 * | Stored               | Projected | Warning (before) |
 * |----------------------|-----------|------------------|
 * | `{ "fr": "Bonjour" }`| `Bonjour` | —                |
 * | `{ "en": "Hi" }`     | `""`      | —                |
 * | absent / not an object | `null`  | ⚠ raised         |
 *
 * ⚠ Note the middle row, unchanged by this guard: a document that **has** the attribute but
 * not the requested language yields the empty string, not `null` — the `""` third argument
 * of `TRANSLATE()`. Two shapes of « no text », two values; a consumer testing for emptiness
 * must accept both.
 *
 * @param string $key The logical key to use in the AQL return object.
 * @param string $doc The document variable or alias (default: `AQL::DOC`).
 * @param string|null $keyName Optional property name in the document if different from `$key`.
 * @param string|array|null $lang Optional language code for translation; if null, returns the original field.
 *
 * @return string AQL key/value snippet for the translated field.
 *
 * @package oihana\arango\db\helpers\fields
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldTranslate
(
    string            $key ,
    string            $doc     = AQL::DOC ,
    ?string           $keyName = null ,
    null|string|array $lang    = null ,
)
: string
{
    $lang = is_array( $lang ) ? ( $lang[ Arango::LANG ] ?? null ) : $lang ;

    if( $lang === null )
    {
        return aqlFieldDefault( $key , $doc , $keyName ) ;
    }

    $source = key( $keyName ?? $key , $doc ) ; // $doc.$key

    return keyValue( $key , ternary
    (
        condition  : isObject( $source ) ,
        trueValue  : translate
        (
            betweenDoubleQuotes( $lang ) , // "$lang"
            $source ,
            betweenDoubleQuotes() ,        // ""
        ) ,
        falseValue : AQL::NULL
    ) ) ;
}