<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\options\ForOptions;

use oihana\enums\Char;

/**
 * The index a model hands the optimizer for the loop its list-family queries share.
 *
 * The optimizer chooses an index from estimates, and an estimate can be wrong in
 * the one direction that costs : a list narrowed on a very selective value and
 * ordered on another field may be read through the index of the order, every
 * document of the collection walked to keep a handful, where the index of the
 * narrowing value would have read that handful alone. The `EXPLAIN` says which
 * index a query takes — see `wiki/en/db/explain-and-profiling.md`.
 *
 * ```php
 * AQL::INDEX_HINT       => 'byOwnerDue' ,  // a name, or a list of names in order of preference
 * AQL::FORCE_INDEX_HINT => true ,          // optional : an error rather than another index
 * ```
 *
 * becomes, on the loop of the list :
 *
 * ```
 * FOR doc IN @@collection OPTIONS {"forceIndexHint":true,"indexHint":"byOwnerDue"}
 * ```
 *
 * and, on the loop of its count, facet counts and bounds :
 *
 * ```
 * FOR doc IN @@collection OPTIONS {"indexHint":"byOwnerDue"}
 * ```
 *
 * 🔑 **The list, the count, the facet counts and the bounds receive the same
 * index**, because they share their loop ({@see \oihana\arango\models\traits\queries\FilteredScopeTrait}) :
 * a list and its count read the same set, and the count is served by the same index.
 *
 * 🚨 **But the list alone FORCES it.** An index may serve the list for its SORT
 * alone — `[ due ]` for « the tasks of one owner, by due date » —, and the count,
 * the facet counts and the bounds sort nothing : forced on them, such an index is
 * one they cannot use, and the server refuses the query (« could not use index hint
 * to serve query », measured live). They receive the hint as a preference, which
 * the optimizer takes whenever the index serves their filter.
 *
 * 🚨 **Not on a search.** When an active `?search=` iterates the View, the loop runs
 * over the View, which knows no `indexHint` : the option is left out there.
 *
 * ⚠️ **`forceIndexHint` makes the query fail when the index is missing, or cannot
 * serve it.** Declare the index in `AQL::INDEXES` before forcing it. Without
 * `forceIndexHint`, an index the optimizer cannot use is a preference it ignores.
 *
 * Nothing declared — the default — leaves every query exactly as it was.
 *
 * @package oihana\arango\models\traits\aql
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
trait IndexHintTrait
{
    /**
     * Whether the {@see $indexHint} is mandatory. `null` leaves the server's default
     * (`false`).
     */
    public ?bool $forceIndexHint = null ;

    /**
     * The index the loop is handed : a name, or a list of names in order of
     * preference. `null` — the default — hands none.
     *
     * @var string|array<int,string>|null
     */
    public null|string|array $indexHint = null ;

    /**
     * Initialize the index handed to the optimizer, and whether it is mandatory.
     *
     * @param array $init Reads `AQL::INDEX_HINT` and `AQL::FORCE_INDEX_HINT`.
     *
     * @return $this
     */
    public function initializeIndexHint( array $init = [] ) :static
    {
        $this->indexHint      = $init[ AQL::INDEX_HINT       ] ?? $this->indexHint ;
        $this->forceIndexHint = $init[ AQL::FORCE_INDEX_HINT ] ?? $this->forceIndexHint ;
        return $this ;
    }

    /**
     * The options of the loop over the collection, or null when no index is handed.
     *
     * ⚠️ `forceIndexHint` alone, without an index to force, hands nothing.
     *
     * @param bool $forcible Whether this loop may carry `forceIndexHint` — the list's
     *                       does, the loops that sort nothing do not.
     *
     * @return array<string,mixed>|null
     */
    protected function indexHintOptions( bool $forcible = true ) :?array
    {
        if ( $this->indexHint === null || $this->indexHint === Char::EMPTY || $this->indexHint === [] )
        {
            return null ;
        }

        $options = [ ForOptions::INDEX_HINT => $this->indexHint ] ;

        if ( $forcible && $this->forceIndexHint !== null )
        {
            $options[ ForOptions::FORCE_INDEX_HINT ] = $this->forceIndexHint ;
        }

        return $options ;
    }
}
