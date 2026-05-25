<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

/**
 * AQL offers functions for numeric calculations.
 * @see https://docs.arangodb.com/stable/aql/functions/numeric
 */
class NumericFunction
{
    use FunctionCallTrait ;
    
    /**
     * Return the absolute part of value.
     */
    public const string ABS = 'ABS' ;

    /**
     * Return the arccosine of value.
     */
    public const string ACOS = 'ACOS' ;

    /**
     * Return the arcsine of value.
     */
    public const string ASIN = 'ASIN' ;

    /**
     * Return the arc tangent of value.
     */
    public const string ATAN = 'ATAN' ;

    /**
     * Return the arc tangent of the quotient of y and x.
     */
    public const string ATAN2 = 'ATAN2' ;

    /**
     * Return the average (arithmetic mean) of the values in array.
     */
    public const string AVERAGE = 'AVERAGE' ;

    /**
     * Return the integer closest but not less than value.
     */
    public const string CEIL = 'CEIL' ;

    /**
     * Return the cosine of value.
     */
    public const string COS = 'COS' ;

    /**
     * Return the cosine similarity  between x and y.
     */
    public const string COSINE_SIMILARITY = 'COSINE_SIMILARITY' ;

    /**
     * Calculate the score for one or multiple values with a Gaussian function that decays depending on the distance of a numeric value from a user-given origin.
     */
    public const string DECAY_GAUSS = 'DECAY_GAUSS' ;

    /**
     * Calculate the score for one or multiple values with an exponential function that decays depending on the distance of a numeric value from a user-given origin.
     */
    public const string DECAY_EXP = 'DECAY_EXP' ;

    /**
     * Calculate the score for one or multiple values with a linear function that decays depending on the distance of a numeric value from a user-given origin.
     */
    public const string DECAY_LINEAR = 'DECAY_LINEAR' ;

    /**
     * Return the angle converted from radians to degrees.
     */
    public const string DEGREES = 'DEGREES' ;

    /**
     * Return Euler’s constant (2.71828…) raised to the power of value.
     */
    public const string EXP = 'EXP' ;

    /**
     * Return 2 raised to the power of value.
     */
    public const string EXP2 = 'EXP2' ;

    /**
     * Return the integer closest but not greater than value.
     */
    public const string FLOOR = 'FLOOR' ;

    /**
     * Return the natural logarithm of value. The base is Euler’s constant (2.71828…).
     */
    public const string LOG = 'LOG' ;

    /**
     * Return the base 2 logarithm of value.
     */
    public const string LOG2 = 'LOG2' ;

    /**
     * Return the base 10 logarithm of value.
     */
    public const string LOG10 = 'LOG10' ;

    /**
     * Return the Manhattan distance between x and y.
     */
    public const string L1_DISTANCE = 'L1_DISTANCE' ;

    /**
     * Return the Euclidean distance between x and y.
     */
    public const string L2_DISTANCE = 'L2_DISTANCE' ;

    /**
     * Return the greatest element of anyArray. The array is not limited to numbers.
     */
    public const string MAX = 'MAX' ;

    /**
     * Return the median value of the values in array.
     */
    public const string MEDIAN = 'MEDIAN' ;

    /**
     * Return the smallest element of anyArray.
     */
    public const string MIN = 'MIN' ;

    /**
     * Return the nth percentile of the values in numArray.
     */
    public const string PERCENTILE = 'PERCENTILE' ;

    /**
     * Return pi.
     */
    public const string PI = 'PI' ;

    /**
     * Return the base to the exponent exp.
     */
    public const string POW = 'POW' ;

    /**
     * Return the product of the values in array.
     */
    public const string PRODUCT = 'PRODUCT' ;

    /**
     * Return the angle converted from degrees to radians.
     */
    public const string RADIANS = 'RADIANS' ;

    /**
     * Return a pseudo-random number between 0 and 1.
     */
    public const string RAND = 'RAND' ;

    /**
     * Return an array of numbers in the specified range, optionally with increments other than 1.
     * The start and stop arguments are truncated to integers unless a step argument is provided.
     */
    public const string RANGE = 'RANGE' ;

    /**
     * Return the integer closest to value.
     */
    public const string ROUND = 'ROUND' ;

    /**
     * Return the sine of value.
     */
    public const string SIN = 'SIN' ;

    /**
     * Return the square root of value.
     */
    public const string SQRT = 'SQRT' ;

    /**
     * Return the population standard deviation of the values in array.
     */
    public const string STDDEV_POPULATION = 'STDDEV_POPULATION' ;

    /**
     * Return the sample standard deviation of the values in array.
     */
    public const string STDDEV_SAMPLE = 'STDDEV_SAMPLE' ;

    /**
     * Return the sum of the values in array.
     */
    public const string SUM = 'SUM' ;

    /**
     * Return the tangent of value.
     */
    public const string TAN = 'TAN' ;

    /**
     * Return the population variance of the values in array.
     */
    public const string VARIANCE_POPULATION = 'VARIANCE_POPULATION' ;

    /**
     * Return the sample variance of the values in array.
     */
    public const string VARIANCE_SAMPLE = 'VARIANCE_SAMPLE' ;
}
