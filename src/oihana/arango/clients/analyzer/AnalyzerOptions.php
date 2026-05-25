<?php

namespace oihana\arango\clients\analyzer ;

/**
 * Common contract for every analyzer definition consumable by
 * {@see \oihana\arango\clients\Database::createAnalyzer()} and
 * {@see Analyzer::create()}.
 *
 * Implementations are expected to be immutable value objects whose
 * single responsibility is to serialise the type-specific fragment
 * of a `POST /_api/analyzer` body — namely the `type` discriminator
 * and the `properties` wrapper. The `name` and `features` fields are
 * carried by the caller, not by the value object.
 *
 * Example implementations: {@see IdentityAnalyzer}, {@see TextAnalyzer},
 * {@see NormAnalyzer}, {@see StemAnalyzer}.
 *
 * @package oihana\arango\clients\analyzer
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
interface AnalyzerOptions
{
    /**
     * Returns the `{ type, properties }` fragment of a
     * `POST /_api/analyzer` body corresponding to this analyzer
     * definition.
     *
     * Implementations MUST set the `type` field (typically through
     * {@see \oihana\arango\clients\analyzer\enums\AnalyzerType}) and
     * MAY set the `properties` field. When `properties` is omitted
     * the server applies its built-in defaults for the given
     * analyzer type.
     *
     * @return array<string, mixed>
     */
    public function toArray() : array ;
}
