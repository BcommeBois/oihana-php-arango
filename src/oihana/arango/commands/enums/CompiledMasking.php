<?php

namespace oihana\arango\commands\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The structural keys of the **compiled** masking structure produced by
 * {@see ArangoMaskingTrait::compileMaskings()}.
 *
 * The compiled structure is, per collection,
 * `{ type: <mode>, maskings: [ { path, type, …options } ] }`:
 *  - `type` is the collection-level {@see oihana\masking\enums\MaskingMode} ;
 *  - `maskings` is the list of attribute rules handed to
 *    {@see oihana\masking\maskDocument()} (each rule keyed by
 *    {@see oihana\masking\enums\MaskingRule}).
 */
class CompiledMasking
{
    use ConstantsTrait ;

    /**
     * The 'maskings' key — the list of attribute masking rules of a collection.
     */
    public const string MASKINGS = 'maskings' ;

    /**
     * The 'type' key — the collection-level masking mode
     * (a {@see oihana\masking\enums\MaskingMode} value).
     */
    public const string TYPE = 'type' ;
}
