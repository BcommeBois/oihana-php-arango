<?php

namespace oihana\arango\clients\document\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Field names returned by ArangoDB through the document API
 * (`/_api/document/{collection}` and `/_api/document/{collection}/{key}`)
 * on top of the reserved attributes already covered by
 * {@see \org\schema\constants\Schema}.
 *
 * Mostly carries the two optional payload fields exposed by the server
 * when the request opts into them:
 * - `new` (when `returnNew: true` was requested on insert / update / replace),
 * - `old` (when `returnOld: true` was requested on update / replace / remove).
 *
 * @see https://docs.arangodb.com/stable/develop/http-api/documents/
 *
 * @package oihana\arango\clients\document\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class DocumentField
{
    use ConstantsTrait ;

    /**
     * Field carrying the full new document payload in the response
     * (only present when the request was sent with `returnNew: true`).
     */
    public const string NEW = 'new' ;

    /**
     * Field carrying the full previous document payload in the response
     * (only present when the request was sent with `returnOld: true`).
     */
    public const string OLD = 'old' ;
}
