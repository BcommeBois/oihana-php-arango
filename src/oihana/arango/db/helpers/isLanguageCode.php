<?php

namespace oihana\arango\db\helpers;

/**
 * Checks whether a value is a language code safe to interpolate into a query.
 *
 * A language tag is **not** bound: it names an attribute of the stored
 * translations object (`alternateName.fr`), and an attribute name cannot be a
 * bind parameter. It is therefore written verbatim into the query string, and
 * must be proven harmless first — this helper is the proof, and the language
 * counterpart of {@see isAttributeName()}.
 *
 * A valid tag is a two or three letter primary subtag, optionally followed by
 * dash-separated subtags (region, script, variant): `fr`, `en`, `pt-BR`,
 * `zh-Hant-TW`. The primary subtag is lowercase — callers normalise before
 * asking, so `FR` is rejected rather than silently accepted under two spellings.
 *
 * ⚠ A tag carrying a dash is valid here but **cannot** be reached through AQL
 * dot notation (`doc.alternateName.pt-BR` reads as a subtraction). The caller
 * emits the bracket form for it; this helper only says the tag is safe.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\isLanguageCode;
 *
 * isLanguageCode( 'fr' );         // true
 * isLanguageCode( 'pt-BR' );      // true
 * isLanguageCode( 'zh-Hant-TW' ); // true
 * isLanguageCode( 'FR' );         // false (normalise first)
 * isLanguageCode( 'f' );          // false (too short)
 * isLanguageCode( 'fr"' );        // false
 * isLanguageCode( 'fr fr' );      // false
 * isLanguageCode( '' );           // false
 * isLanguageCode( 42 );           // false (not a string)
 * ```
 *
 * @param mixed $value The value to check.
 *
 * @return bool True when `$value` is a safe language tag.
 *
 * @package oihana\arango\db\helpers
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function isLanguageCode( mixed $value ): bool
{
    if ( !is_string( $value ) || $value === '' )
    {
        return false ;
    }

    return (bool) preg_match( '/^[a-z]{2,3}(-[A-Za-z0-9]{1,8})*$/' , $value ) ;
}
