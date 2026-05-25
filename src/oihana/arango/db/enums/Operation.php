<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class Operation
{
    use ConstantsTrait ;

    /**
     * The COLLECT operation can group data by one or multiple grouping criteria, retrieve all distinct values, count how often values occur, and calculate statistical properties efficiently.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/collect
     * @example
     * ```
     * FOR u IN users
     * COLLECT city = u.city INTO groups
     * RETURN { "city" : city, "usersInCity" : groups }
     * ```
     */
    public const string COLLECT = 'COLLECT' ;

    /**
     * The FILTER operation lets you restrict the results to elements that match arbitrary logical conditions
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/filter
     * @example
     * ```
     * FOR u IN users
     * FILTER u.active == true && u.age < 39
     * RETURN u
     * ```
     */
    public const string FILTER = 'FILTER' ;

    /**
     * The versatile FOR operation can iterate over a collection or View, the elements of an array, or traverse a graph
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/for
     * @example
     * ```
     * FOR variableName IN expression
     * ```
     */
    public const string FOR = 'FOR' ;

    /**
     * The name identifying the named graph.
     *
     * Its vertex and edge collections are looked up.
     * Note that the graph name is like a regular string, hence it must be enclosed by quote marks, like GRAPH "graphName".
     *
     * @see https://docs.arangodb.com/3.10/aql/graphs/traversals/#working-with-named-graphs
     * @example
     * ```
     * FOR vertex
     *    IN 1..1
     *    OUTBOUND startVertex
     *    GRAPH "myGraph"
     * ```
     */
    public const string GRAPH = 'GRAPH' ;

    /**
     * You can use the INSERT operation to create new documents in a collection.
     * It can optionally end with an OPTIONS { … } clause.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/insert
     * @example
     * ```
     * INSERT document INTO collection
     * ```
     */
    public const string INSERT = 'INSERT' ;

    /**
     * You can use the LET operation to assign an arbitrary value to a variable.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/let
     * @example
     * ```
     * LET variableName = expression
     * ```
     */
    public const string LET = 'LET' ;

    /**
     * The LIMIT operation allows you to reduce the number of results to at most the specified number and optionally skip results using an offset for pagination.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/limit
     * @example
     * ```
     * LIMIT count
     * LIMIT offset, count
     * ``
     */
    public const string LIMIT = 'LIMIT' ;

    /**
     * An expression, like in a FILTER statement, which is evaluated in every step of the traversal, as early as possible.
     *
     * @see https://docs.arangodb.com/3.10/aql/graphs/traversals/#working-with-named-graphs
     * @see https://docs.arangodb.com/3.10/aql/graphs/traversals/#pruning
     *
     * @example
     * ```aql
     * FOR vertex
     *    IN 1..1
     *    OUTBOUND startVertex
     *    GRAPH "myGraph"
     *    PRUNE [pruneVariable = ]pruneCondition]
     * ```
     */
    public const string PRUNE = 'PRUNE' ;

    /**
     * The REPLACE operation removes all attributes of a document and sets the given attributes, excluding immutable system attributes.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/replace
     * @example
     * ```
     * REPLACE document IN collection
     * REPLACE keyExpression WITH document IN collection
     * ``
     */
    public const string REPLACE = 'REPLACE' ;

    /**
     * You can use the REMOVE operation to delete documents from a collection.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/remove
     * @example
     * ```
     * REMOVE keyExpression IN collection
     * ``
     */
    public const string REMOVE = 'REMOVE' ;

    /**
     * You can use the RETURN operation to produce the result of a query
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/return
     * @example
     * ```
     * FOR variableName IN expression
     * RETURN variableName
     * ```
     */
    public const string RETURN = 'RETURN' ;

    /**
     * The SEARCH operation lets you filter Views, accelerated by the underlying indexes
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/search
     * @example
     * ```
     * FOR doc IN viewName
     * SEARCH expression
     * OPTIONS { … }
     * ...
     * ```
     */
    public const string SEARCH = 'SEARCH' ;

    /**
     * The SORT operation allows you to specify one or multiple sort criteria and directions to control the order of query results or the elements of arrays.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/sort
     * @example
     * ```
     * FOR u IN users
     * SORT u.lastName, u.firstName, u.id DESC
     * RETURN u
     * ``
     */
    public const string SORT = 'SORT' ;

    /**
     * The UPDATE operation partially modifies a document with the given attributes, by adding new and updating existing attributes.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/update/
     * @example
     * ```
     * UPDATE document IN collection
     * UPDATE keyExpression WITH document IN collection
     * ```
     */
    public const string UPDATE = 'UPDATE' ;

    /**
     * An UPSERT operation either modifies an existing document, or creates a new document if it does not exist.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/upsert
     * @example
     * ```
     * UPSERT searchExpression
     * INSERT insertExpression
     * UPDATE updateExpression
     * IN collection
     * ```
     * or
     * ```
     * UPSERT searchExpression
     * INSERT insertExpression
     * REPLACE updateExpression
     * IN collection
     * ```
     * or
     * ```
     * UPSERT { name: 'superuser' }
     * INSERT { name: 'superuser', logins: 1, created: DATE_NOW() , modified: DATE_NOW() }
     * UPDATE { logins: OLD.logins + 1 , modified: DATE_NOW() } IN users
     * ```
     */
    public const string UPSERT = 'UPSERT' ;

    /**
     * Aggregate adjacent documents or value ranges with a sliding window to calculate running totals, rolling averages, and other statistical properties.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/window
     * @example
     * Row Based (adjacent document)
     * ```
     * WINDOW { preceding: numPrecedingRows, following: numFollowingRows }
     * AGGREGATE variableName = aggregateExpression
     * ```
     * Range Based (value or duration range)
     * ```
     * WINDOW rangeValue WITH { preceding: offsetPreceding, following: offsetFollowing }
     * AGGREGATE variableName = aggregateExpression
     * ```
     */
    public const string WINDOW = 'WINDOW' ;

    /**
     * An AQL query can start with a WITH operation, listing collections that a query implicitly reads from.
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/with/
     * @example
     * ```
     * WITH collection1 [, collection2 [, ... collectionN ] ]
     * ```
     */
    public const string WITH = 'WITH' ;
}