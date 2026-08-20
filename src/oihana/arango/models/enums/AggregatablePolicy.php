<?php

namespace oihana\arango\models\enums;

use oihana\arango\enums\Arango;
use oihana\reflect\traits\ConstantsTrait;

/**
 * What happens to a `?group=` aggregate whose field is **absent** from the model's
 * {@see Arango::AGGREGATABLE} whitelist.
 *
 * The two halves of a group spec do not answer to the same law by default: `by`
 * (the dimensions) is fail-closed through {@see Arango::GROUPABLE}, while `agg`
 * (the aggregates) has historically let every projected path through. An aggregate
 * over a field no document carries is not an error in AQL — `SUM(null)` is `0` —
 * so the answer comes back well-formed, in `200`, and wrong. This policy is how a
 * consumer closes that door, and picks the noise it wants when it shuts.
 *
 * ```php
 * new Documents( $container ,
 * [
 *     Arango::COLLECTION          => 'measures' ,
 *     Arango::AGGREGATABLE        => [ 'speed' => 'speed.value' , 'weight' ] ,
 *     Arango::AGGREGATABLE_POLICY => AggregatablePolicy::STRICT ,
 * ]) ;
 * ```
 *
 * The default is {@see AggregatablePolicy::DROP} when a whitelist is declared, and
 * {@see AggregatablePolicy::OPEN} when none is — so a model that never heard of
 * `AGGREGATABLE` keeps emitting exactly the query it emitted before.
 *
 * 🚨 Whatever the policy, it gates the **whitelist only**, never the permission gate:
 * a whitelisted field refused by `Field::REQUIRES` is always dropped in silence, even
 * under {@see AggregatablePolicy::STRICT}. An error naming a protected field would
 * tell the client that field exists — the very oracle the gate is there to close.
 *
 * @package oihana\arango\models\enums
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
class AggregatablePolicy
{
    use ConstantsTrait ;

    /**
     * The aggregate is **dropped** from the response, like an undeclared grouping
     * dimension. The rest of the group survives untouched — dimensions, count, and
     * the group sort (which never references a variable the `COLLECT` did not emit).
     *
     * The default when a whitelist is declared. Suited to a public API, where a
     * missing column is seen at once.
     */
    public const string DROP = 'drop' ;

    /**
     * The aggregate **passes**, on its raw field path. A declared alias still
     * resolves, so the whitelist works as a pure `publicKey => fieldPath` mapping
     * with no gate — the migration ramp for a surface that wants the aliases before
     * it can afford to close the door.
     *
     * The default when no whitelist is declared, and the historical behaviour.
     */
    public const string OPEN = 'open' ;

    /**
     * The query **fails** with a `ValidationException` naming the refused token.
     * Suited to an internal API, where a plain refusal beats a plausible zero.
     */
    public const string STRICT = 'strict' ;
}
