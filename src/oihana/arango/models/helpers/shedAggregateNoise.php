<?php

namespace oihana\arango\models\helpers;

use function oihana\core\maths\shedFloatNoise;

/**
 * Sheds the float noise of the summed aggregates of one grouped row.
 *
 * A `COLLECT` row is not a document : it comes back raw, and the `SUM` and
 * `AVERAGE` the store computed carry the binary noise of an addition of floats —
 * `1380913.4299999992` where the sheets hold cents. The named keys are cleaned by
 * {@see shedFloatNoise()}, whose scale follows the size of the figure ; a value that
 * is not a float — an integer sum, a count, a null, a dimension — is left as it is,
 * and so is every key that is not named.
 *
 * @param object|array<string,mixed> $row   The grouped row, as the store handed it back.
 * @param array<int,string>          $names The keys of the aggregates that add up.
 *
 * @return object|array<string,mixed> The row, in the shape it came in.
 *
 * @package oihana\arango\models\helpers
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.7.0
 */
function shedAggregateNoise( object|array $row , array $names ) :object|array
{
    foreach ( $names as $name )
    {
        if ( is_array( $row ) )
        {
            if ( is_float( $row[ $name ] ?? null ) )
            {
                $row[ $name ] = shedFloatNoise( $row[ $name ] ) ;
            }
        }
        elseif ( is_float( $row->{ $name } ?? null ) )
        {
            $row->{ $name } = shedFloatNoise( $row->{ $name } ) ;
        }
    }

    return $row ;
}
