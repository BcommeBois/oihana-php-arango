<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\enums\Char;
use function oihana\core\strings\compile;

/**
 * Builds an AQL `SEARCH` clause for a query.
 *
 * The SEARCH operation guarantees the use of View indexes for an efficient execution plan.
 * Using FILTER on Views does **not** utilize indexes and filtering is done as a post-processing step.
 *
 * Example:
 * ```php
 * use function oihana\arango\db\operations\aqlSearch;
 *
 * echo aqlSearch([ AQL::SEARCH => "ANALYZER(PHRASE(doc.text, 'search phrase'), 'text_en')" ]) . PHP_EOL;
 * // SEARCH ANALYZER(PHRASE(doc.text, 'search phrase'), 'text_en')
 *
 * echo aqlSearch(); // ''
 * ```
 *
 * @param  array $init  Array containing the key `AQL::SEARCH` with the expression to search.
 * @return string       The compiled AQL SEARCH clause, or an empty string if no search expression is provided.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/search
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * author   Marc Alcaraz
 */
function aqlSearch( array $init = [] ): string
{
    $search = compile($init[ AQL::SEARCH ] ?? null ) ; // Warning: AQL::SEARCH ('search') !== Operation::SEARCH ('SEARCH')
    return $search !== Char::EMPTY ? Operation::SEARCH . Char::SPACE . $search : Char::EMPTY ;
}

// TODO finalize the search operation with options and analyzer