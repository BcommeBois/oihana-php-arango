<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * How a document read from the database is turned into its schema object.
 *
 * - `CONSTRUCTOR` : `new $schema( $document )` — a shallow assignment. Every
 *   nested structure stays a raw array, whatever the schema declares.
 * - `REFLECTION`  : `Reflection::hydrate()` — the only path honouring the
 *   `#[HydrateAs]` / `#[HydrateWith]` attributes, down the whole tree.
 *
 * Declared once per model through the `AQL::HYDRATION` option ; absent means
 * `CONSTRUCTOR`, so a model that says nothing keeps its current behaviour.
 *
 * ⚠️ `REFLECTION` is stricter in two ways : a nested attribute the schema does
 * not declare is dropped, and a value that cannot be coerced raises a
 * `HydrationException` where the constructor silently accepted it.
 *
 * @package oihana\arango\db\enums
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
class Hydration
{
    use ConstantsTrait ;

    /**
     * Builds the object with its constructor — nested structures stay raw arrays.
     */
    public const string CONSTRUCTOR = 'constructor' ;

    /**
     * Builds the object by reflection — nested structures are typed too.
     */
    public const string REFLECTION = 'reflection' ;
}