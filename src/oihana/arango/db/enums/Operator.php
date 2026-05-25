<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class Operator
{
    use ConstantsTrait ;

    /**
     * The AGGREGATE clause in the COLLECT operations to aggregate the data per group,
     * such as determining the minimum, maximum, and average values, the sums, and more.
     *
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/collect/#aggregation
     */
    public const string AGGREGATE = 'AGGREGATE' ;

    /**
     * The array expansion operator.
     */
    public const string ARRAY_EXPANSION = '[*]' ;

    /**
     * Variable assignment (LET / COLLECT operations, AGGREGATE / PRUNE clauses)
     */
    public const string ASSIGN = '=' ;

    /**
     * The special AT LEAST (<expression>) operator to require an arbitrary number of elements to satisfy the condition to evaluate to true.
     * You can use a static number or calculate it dynamically using an expression.
     */
    public const string AT_LEAST = 'AT LEAST' ;

    /**
     * The DISTINCT keyword will ensure uniqueness of the values returned by the RETURN statement.
     */
    public const string DISTINCT = 'DISTINCT' ;

    /**
     * INTO operator (INSERT / UPDATE / REPLACE / REMOVE / COLLECT operations).
     */
    public const string INTO = 'INTO' ;

    /**
     * In the COLLECT clause, an optional KEEP clause that can be used to control which variables
     * will be copied into the variable created by INTO.
     *
     * If no KEEP clause is specified, all variables from the scope will be copied
     * as sub-attributes into the groupsVariable.
     *
     * This is safe but can have a negative impact on performance
     * if there are many variables in scope or the variables contain massive amounts of data.
     *
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/collect/#discarding-obsolete-variables
     */
    public const string KEEP = 'KEEP' ;

    /**
     * The range operator.
     */
    public const string RANGE = '..' ;

    /**
     * The scope operator (user-defined AQL functions)
     */
    public const string SCOPE = '::' ;

    /**
     * The `WITH` operator (WITH / UPDATE / REPLACE / COLLECT operations).
     */
    public const string WITH = 'WITH' ;

    /**
     * The `COLLECT` clause also provides a special `WITH COUNT` clause that can be used
     * to determine the number of group members efficiently.
     *
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/collect/#group-length-calculation
     */
    public const string WITH_COUNT = 'WITH COUNT' ;
}
