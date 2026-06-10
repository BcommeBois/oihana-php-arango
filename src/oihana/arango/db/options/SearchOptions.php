<?php

namespace oihana\arango\db\options;

/**
 * The options of the AQL `SEARCH` operation (`SEARCH expression OPTIONS { … }`),
 * hydrated by {@see \oihana\arango\db\operations\aqlSearch()} from the
 * `AQL::SEARCH_OPTIONS` key. Null properties are omitted from the emitted
 * `OPTIONS` object, so the server applies its own defaults.
 *
 * Not to be confused with {@see ForOptions} — the `FOR`-level options
 * (`indexHint`, `useCache`, …) for collection iteration. The options below
 * only apply to a `SEARCH` against a View.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/search/#search-options
 */
class SearchOptions extends QueryOptions
{
    /**
     * Restrict the search to the given source collections of the View — an array
     * of collection names. Documents from the View's other linked collections
     * are ignored. The search expression itself is unrestricted (`true` matches
     * every document of the selected collections).
     * @var array|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/search/#collections
     */
    public ?array $collections ;

    /**
     * How the search criteria get optimized — one of the
     * {@see \oihana\arango\db\enums\ConditionOptimization} constants:
     * `'auto'` (server default: convert to disjunctive normal form and remove
     * redundant or overlapping conditions) or `'none'` (search the index
     * without optimizing the conditions).
     * @var string|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/search/#conditionoptimization
     */
    public ?string $conditionOptimization ;

    /**
     * How the total row count is calculated when the `fullCount` query option is
     * enabled or a `COLLECT WITH COUNT` clause is executed — one of the
     * {@see \oihana\arango\db\enums\CountApproximate} constants: `'exact'`
     * (server default: enumerate the rows) or `'cost'` (O(1) cost-based
     * approximation, precise for an empty or single-term search condition).
     * @var string|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/search/#countapproximate
     */
    public ?string $countApproximate ;

    /**
     * Number of worker threads that may process the View's index segments in
     * parallel. `1` disables parallelization; greater values are a hint, capped
     * by the server's `--arangosearch.execution-threads-limit`. Omit to use the
     * server's `--arangosearch.default-parallelism`.
     * @var int|null
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/search/#parallelism
     */
    public ?int $parallelism ;
}
