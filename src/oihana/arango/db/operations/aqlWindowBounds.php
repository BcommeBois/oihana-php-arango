<?php

namespace oihana\arango\db\operations;

use oihana\arango\db\enums\AQL;
use oihana\enums\Char;

use function oihana\core\strings\betweenQuotes;

/**
 * Serializes the `{ preceding: …, following: … }` bounds object of a `WINDOW` clause.
 *
 * Numeric bounds are emitted bare; string bounds are single-quoted (ISO 8601
 * durations such as `PT1H`, or the `'unbounded'` keyword). A `null` bound is
 * omitted from the object.
 *
 * ```php
 * echo aqlWindowBounds( 1 , 1 ) ;             // { preceding: 1, following: 1 }
 * echo aqlWindowBounds( 'unbounded' , 0 ) ;   // { preceding: 'unbounded', following: 0 }
 * echo aqlWindowBounds( 0 , null ) ;          // { preceding: 0 }
 * echo aqlWindowBounds( null , null ) ;       // {  }
 * ```
 *
 * @param int|float|string|null $preceding Lower window bound.
 * @param int|float|string|null $following Upper window bound.
 *
 * @return string The bounds object literal, e.g. `{ preceding: 1, following: 1 }`.
 *
 * @see aqlWindow()
 *
 * @since   1.0.0
 * @author  Marc Alcaraz
 * @package oihana\arango\db\operations
 */
function aqlWindowBounds( int|float|string|null $preceding , int|float|string|null $following ) :string
{
    $format = static fn( int|float|string $value ) :string
        => is_string( $value ) ? betweenQuotes( $value ) : (string) $value ;

    $entries = [] ;

    if ( $preceding !== null )
    {
        $entries[] = AQL::PRECEDING . Char::COLON . Char::SPACE . $format( $preceding ) ;
    }

    if ( $following !== null )
    {
        $entries[] = AQL::FOLLOWING . Char::COLON . Char::SPACE . $format( $following ) ;
    }

    return Char::LEFT_BRACE . Char::SPACE
         . implode( Char::COMMA . Char::SPACE , $entries )
         . Char::SPACE . Char::RIGHT_BRACE ;
}
