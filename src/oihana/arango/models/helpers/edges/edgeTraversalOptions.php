<?php

namespace oihana\arango\models\helpers\edges;

use oihana\arango\db\enums\options\TraversalOption;
use oihana\arango\db\enums\options\TraversalOrder;
use oihana\arango\db\enums\options\TraversalUniqueVertices;

/**
 * The traversal options every edge relation is walked with — breadth-first, and each
 * vertex visited **once globally**.
 *
 * `uniqueVertices: global` is not cosmetic, it decides *how many* rows come back. Two
 * shapes make a vertex reachable more than once: a **diamond** (`a → b → d` plus
 * `a → c → d`) and a plainly **duplicated edge** (`a → c` created twice). ArangoDB's
 * default (`uniqueVertices: none`) yields such a vertex once per path.
 *
 * The list always passed these options; the count passed none — so the two disagreed
 * on the same data, measured live on the shapes above:
 *
 * | Declaration | List | Count, before |
 * |---|---|---|
 * | depth 1, one duplicated edge | `[ b , c ]` → 2 | **3** |
 * | `1..5` over the diamond      | `[ b , c , d ]` → 3 | **6** |
 *
 * Both builders now read the options here, so a count can no longer over-count rows
 * the list de-duplicated.
 *
 * @return array The `AQL::OPTIONS` payload of an edge traversal.
 *
 * @package oihana\arango\models\helpers\edges
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
function edgeTraversalOptions() :array
{
    return
    [
        TraversalOption::ORDER           => TraversalOrder::BFS ,
        TraversalOption::UNIQUE_VERTICES => TraversalUniqueVertices::GLOBAL ,
    ] ;
}
