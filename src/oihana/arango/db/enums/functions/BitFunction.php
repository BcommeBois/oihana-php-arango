<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

/**
 * AQL offers functions for bit manipulation on unsigned integers (0 … 2³² - 1, up to 32 bits).
 * @see https://docs.arangodb.com/stable/aql/functions/bit/
 */
class BitFunction
{
    use FunctionCallTrait ;

    /**
     * Return the bitwise AND of an array of numbers, or of two number operands.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_and
     */
    public const string BIT_AND = 'BIT_AND' ;

    /**
     * Construct a number from an array of bit positions to set (zero-based).
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_construct
     */
    public const string BIT_CONSTRUCT = 'BIT_CONSTRUCT' ;

    /**
     * Deconstruct a number into the array of its set bit positions (zero-based).
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_deconstruct
     */
    public const string BIT_DECONSTRUCT = 'BIT_DECONSTRUCT' ;

    /**
     * Parse a bitstring (e.g. "0101") into its numeric value.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_from_string
     */
    public const string BIT_FROM_STRING = 'BIT_FROM_STRING' ;

    /**
     * Return the bitwise negation of a number, keeping up to the given number of bits.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_negate
     */
    public const string BIT_NEGATE = 'BIT_NEGATE' ;

    /**
     * Return the bitwise OR of an array of numbers, or of two number operands.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_or
     */
    public const string BIT_OR = 'BIT_OR' ;

    /**
     * Return the number of bits set to 1 in a number (population count).
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_popcount
     */
    public const string BIT_POPCOUNT = 'BIT_POPCOUNT' ;

    /**
     * Bitwise-shift the bits of a number to the left, keeping up to the given number of bits.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_shift_left
     */
    public const string BIT_SHIFT_LEFT = 'BIT_SHIFT_LEFT' ;

    /**
     * Bitwise-shift the bits of a number to the right, keeping up to the given number of bits.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_shift_right
     */
    public const string BIT_SHIFT_RIGHT = 'BIT_SHIFT_RIGHT' ;

    /**
     * Test whether the bit at the given (zero-based) position is set in a number.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_test
     */
    public const string BIT_TEST = 'BIT_TEST' ;

    /**
     * Return the bitstring representation of a number, with the given number of bits.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_to_string
     */
    public const string BIT_TO_STRING = 'BIT_TO_STRING' ;

    /**
     * Return the bitwise XOR of an array of numbers, or of two number operands.
     * @see https://docs.arangodb.com/stable/aql/functions/bit/#bit_xor
     */
    public const string BIT_XOR = 'BIT_XOR' ;
}
