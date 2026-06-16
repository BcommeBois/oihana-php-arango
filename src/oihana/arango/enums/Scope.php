<?php

namespace oihana\arango\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The projection scope used by {@see Field::SCOPE} inside an edge sub-query.
 *
 * When a field of an edge definition (`AQL::EDGES`) is projected, it is, by
 * default, read from the **target vertex** of the traversal. Setting
 * `Field::SCOPE => Scope::EDGE` instead reads it from the **edge** itself,
 * so the relationship metadata (e.g. `created`, `weight`, `role`, `order`)
 * can be hoisted into the returned object alongside the vertex fields.
 *
 * The constant values are intentionally identical to {@see AQL::VERTEX} and
 * {@see AQL::EDGE}, so both forms are interchangeable in a definition:
 *
 * ```php
 * use oihana\arango\db\enums\AQL ;
 * use oihana\arango\enums\Scope ;
 *
 * Field::SCOPE => Scope::EDGE   // explicit and self-documenting
 * Field::SCOPE => AQL::EDGE     // avoids an extra `use` when AQL is already imported
 * ```
 *
 * @package oihana\arango\enums
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
class Scope
{
    use ConstantsTrait ;

    /**
     * Projects the field from the traversal edge (the relationship metadata).
     */
    public const string EDGE = 'edge' ;

    /**
     * Projects the field from the target vertex (the default).
     */
    public const string VERTEX = 'vertex' ;
}
