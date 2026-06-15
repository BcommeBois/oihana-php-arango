<?php

namespace oihana\arango\clients\analyzer\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Case-folding strategy applied by the `norm` and `text` analyzers,
 * carried as the {@see AnalyzerField::CASE} property of the payload sent
 * to `POST /_api/analyzer`.
 *
 * @example
 * ```php
 * use oihana\arango\clients\analyzer\enums\CaseFolding;
 *
 * new NormAnalyzer( locale : 'fr' , case : CaseFolding::LOWER ) ; // instead of 'lower'
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/analyzers/#text
 *
 * @package oihana\arango\clients\analyzer\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class CaseFolding
{
    use ConstantsTrait ;

    /** Lower-case the input. Server default for the `norm` and `text` analyzers. */
    public const string LOWER = 'lower' ;

    /** Leave the casing untouched. */
    public const string NONE = 'none' ;

    /** Upper-case the input. */
    public const string UPPER = 'upper' ;
}
