<?php

namespace oihana\arango\db\options;

class CollectOptions extends QueryOptions
{
    /**
     * You can disable the use-index-for-collect optimization for individual COLLECT operations by setting this option to true.
     * <code>
     * COLLECT ... OPTIONS { disableIndex: true }
     * </code>
     * The optimization improves the scanning for distinct values using COLLECT if a usable persistent index is present.
     * It is automatically disabled if the selectivity is high, i.e. there are many different values,
     * or if there is filtering or an INTO or AGGREGATE clause.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/collect/#disableindex
     */
    public ?bool $disableIndex ;

    /**
     * There are two variants of COLLECT that the optimizer can choose from: the sorted and the hash variant.
     * The method option can be used in a COLLECT statement to inform the optimizer about the preferred method, "sorted" or "hash".
     * @var string|null
     */
    public ?string $method ;
}