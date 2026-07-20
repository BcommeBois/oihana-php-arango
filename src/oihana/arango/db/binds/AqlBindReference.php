<?php

namespace oihana\arango\db\binds;

/**
 * A reference to an AQL bind variable by name — a placeholder for a value
 * supplied at query time, never inlined into the query text.
 *
 * Where a literal value is written straight into the compiled AQL (via
 * {@see \oihana\arango\db\helpers\aqlValue()}), a bind reference renders only
 * the token `@name`; the matching value lives in the query's single `bindVars`
 * map, contributed by the caller through the existing top-level bind mechanism
 * (`AQL::BINDS`). The reference registers **no** value and touches **no** bind
 * map: it only names the slot.
 *
 * A dedicated value object (detected by `instanceof`) is used rather than a
 * marker string: on the value side of a condition a plain string is already a
 * legitimate literal (a value may legitimately start with `@`), so a string
 * convention would be ambiguous — the object is not.
 *
 * Build one with {@see aqlBindRef()}, which validates the name first.
 *
 * @package oihana\arango\db\binds
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
final readonly class AqlBindReference
{
    /**
     * Creates a new AqlBindReference instance.
     *
     * @param string $name The bind variable name (validated by {@see aqlBindRef()}).
     */
    public function __construct( public string $name ) {}

    /**
     * Renders the reference as an AQL bind token, e.g. `@allowedRegions`.
     *
     * @return string The formatted bind variable token.
     */
    public function toAql() : string
    {
        return formatBindVariable( $this->name ) ;
    }
}
