<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

class DocumentFunction
{
    use FunctionCallTrait ;

    /**
     * The ATTRIBUTES(document, removeSystemAttrs, sort) → strArray function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#attributes
     */
    public const string ATTRIBUTES = 'ATTRIBUTES' ;

    /**
     * This is an alias for LENGTH().
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#count
     */
    public const string COUNT = 'COUNT' ;

    /**
     * The ENTRIES(document) → pairArray function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#entries
     */
    public const string ENTRIES = 'ENTRIES' ;

    /**
     * The HAS(document, attributeName) → isPresent function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#entries
     */
    public const string HAS = 'HAS' ;

    /**
     * The IS_SAME_COLLECTION(collectionName, documentIdentifier) → isSame function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#is_same_collection
     */
    public const string IS_SAME_COLLECTION = 'IS_SAME_COLLECTION' ;

    /**
     * The KEEP(document, attributeName1, attributeName2, ... attributeNameN) → doc function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#keep
     */
    public const string KEEP = 'KEEP' ;

    /**
     * The KEEP_RECURSIVE(document, attributeName1, attributeName2, ... attributeNameN) → doc function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#keep_recursive
     */
    public const string KEEP_RECURSIVE = 'KEEP_RECURSIVE' ;

    /**
     * This is an alias for ATTRIBUTES().
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#keys
     */
    public const string KEYS = 'KEYS' ;

    /**
     * The LENGTH(doc) → attrCount function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#length
     */
    public const string LENGTH = 'LENGTH' ;

    /**
     * The MATCHES(document, examples, returnIndex) → match function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#matches
     */
    public const string MATCHES = 'MATCHES' ;

    /**
     * The MERGE(document1, document2, ... documentN) → mergedDocument function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#merge
     */
    public const string MERGE = 'MERGE' ;

    /**
     * The MERGE_RECURSIVE(document1, document2, ... documentN) → mergedDocument function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#merge_recursive
     */
    public const string MERGE_RECURSIVE = 'MERGE_RECURSIVE' ;

    /**
     * The PARSE_COLLECTION(documentIdentifier) → collectionName function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#parse_collection
     */
    public const string PARSE_COLLECTION = 'PARSE_COLLECTION' ;

    /**
     * The PARSE_IDENTIFIER(documentIdentifier) → parts function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#parse_identifier
     */
    public const string PARSE_IDENTIFIER = 'PARSE_IDENTIFIER' ;

    /**
     * The PARSE_KEY(documentIdentifier) → key function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#parse_key
     */
    public const string PARSE_KEY = 'PARSE_KEY' ;

    /**
     * The TRANSLATE(value, lookupDocument, defaultValue) → mappedValue function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#translate
     */
    public const string TRANSLATE = 'TRANSLATE' ;

    /**
     * The UNSET(document, attributeName1, attributeName2, ... attributeNameN) → doc function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#unset
     */
    public const string UNSET = 'UNSET' ;

    /**
     * The UNSET_RECURSIVE(document, attributeName1, attributeName2, ... attributeNameN) → doc function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#unset_recursive
     */
    public const string UNSET_RECURSIVE = 'UNSET_RECURSIVE' ;

    /**
     * The VALUE(document, path) → value function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#value
     */
    public const string VALUE = 'VALUE' ;

    /**
     * The VALUES(document, removeSystemAttrs) → anyArray function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#values
     */
    public const string VALUES = 'VALUES' ;

    /**
     * The ZIP(keys, values) → doc function.
     * @see https://docs.arangodb.com/stable/aql/functions/document-object/#zip
     */
    public const string ZIP = 'ZIP' ;
}
