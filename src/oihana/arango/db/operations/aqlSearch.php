<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operation;
use oihana\arango\db\options\SearchOptions;
use oihana\enums\Char;

use ReflectionException;
use function oihana\arango\db\functions\search\analyzer;
use function oihana\core\strings\compile;

/**
 * Builds an AQL `SEARCH` clause for a query, with optional Analyzer wrapping
 * and an optional `OPTIONS` object.
 *
 * The SEARCH operation guarantees the use of View indexes for an efficient execution plan.
 * Using FILTER on Views does **not** utilize indexes and filtering is done as a post-processing step.
 *
 * `$init` keys:
 * - **`AQL::SEARCH`** — the search expression. Without it everything else is
 *   ignored and an empty string is returned.
 * - **`AQL::ANALYZER`** *(optional)* — an Analyzer name; the expression is
 *   wrapped in `ANALYZER(expr, "name")` via {@see analyzer()}, setting the
 *   Analyzer for the expression and its nested functions.
 * - **`AQL::SEARCH_OPTIONS`** *(optional)* — the `SEARCH … OPTIONS { … }`
 *   object (`collections`, `conditionOptimization`, `countApproximate`,
 *   `parallelism`), accepted as an associative array (hydrated into
 *   {@see SearchOptions}, unknown keys dropped, null properties omitted),
 *   a `SearchOptions` instance, any `JsonSerializable`/plain object, or a
 *   pre-encoded JSON string — the same tolerance as {@see aqlOptions()}.
 *
 * Not to be confused with `AQL::OPTIONS`, the `FOR`-level options
 * (`indexHint`, `useCache`, … — see {@see \oihana\arango\db\options\ForOptions}):
 * a `FOR` over a collection takes `AQL::OPTIONS`, a `SEARCH` against a View
 * takes `AQL::SEARCH_OPTIONS`. {@see aqlFor()} forwards its whole `$init`
 * here, so all three keys work through it directly.
 *
 * Example:
 * ```php
 * use oihana\arango\db\enums\AQL;
 * use oihana\arango\db\enums\ConditionOptimization;
 * use function oihana\arango\db\operations\aqlSearch;
 *
 * echo aqlSearch([ AQL::SEARCH => 'PHRASE(doc.text, "search phrase", "text_en")' ]) . PHP_EOL;
 * // SEARCH PHRASE(doc.text, "search phrase", "text_en")
 *
 * echo aqlSearch
 * ([
 *     AQL::SEARCH         => 'PHRASE(doc.text, "search phrase")' ,
 *     AQL::ANALYZER       => 'text_en' ,
 *     AQL::SEARCH_OPTIONS => [ 'conditionOptimization' => ConditionOptimization::NONE ] ,
 * ]) . PHP_EOL;
 * // SEARCH ANALYZER(PHRASE(doc.text, "search phrase"),"text_en") OPTIONS {"conditionOptimization":"none"}
 *
 * echo aqlSearch(); // ''
 * ```
 *
 * @param array $init Array containing the key `AQL::SEARCH` with the expression to search,
 *                     and optionally `AQL::ANALYZER` and `AQL::SEARCH_OPTIONS`.
 * @return string      The compiled AQL SEARCH clause, or an empty string if no search expression is provided.
 *
 * @throws ReflectionException
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/search
 * @see SearchOptions
 * @see analyzer()
 * @see aqlFor()
 *
 * @package oihana\arango\db\operations
 * @since   1.0.0
 * author   Marc Alcaraz
 */
function aqlSearch( array $init = [] ): string
{
    $search = compile($init[ AQL::SEARCH ] ?? null ) ; // Warning: AQL::SEARCH ('search') !== Operation::SEARCH ('SEARCH')

    if ( $search === Char::EMPTY )
    {
        return Char::EMPTY ;
    }

    $name = $init[ AQL::ANALYZER ] ?? null ;
    if ( is_string( $name ) && $name !== Char::EMPTY )
    {
        $search = analyzer( $search , $name ) ;
    }

    return compile
    ([
        Operation::SEARCH . Char::SPACE . $search ,
        aqlOptions( [ AQL::OPTIONS => $init[ AQL::SEARCH_OPTIONS ] ?? null ] , SearchOptions::class ) ,
    ]) ;
}
