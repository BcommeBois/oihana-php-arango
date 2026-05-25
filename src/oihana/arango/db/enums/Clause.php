<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class Clause
{
    use ConstantsTrait ;

    /**
     * CURRENT clause
     */
    public const string CURRENT = 'CURRENT' ;

    /**
     * NEW clause
     */
    public const string NEW = 'NEW' ;

    /**
     * OLD clause
     */
    public const string OLD = 'OLD' ;

    /**
     * OPTIONS clause.
     */
    public const string OPTIONS = 'OPTIONS' ;

    /**
     * WITH_STATUS clause.
     *
     * Special, non-standard clause used with UPSERT/REPSET operations to return
     * both the new document and the type of operation performed.
     *
     * Produces an AQL expression like:
     * `RETURN { doc: NEW, type: OLD ? 'update' : 'insert' }`
     *
     * - `doc`   : the new document (NEW)
     * - `type`  : "update" if an existing document was matched (OLD is defined),
     *             otherwise "insert".
     */
    public const string WITH_STATUS = 'WITH_STATUS' ;
}
