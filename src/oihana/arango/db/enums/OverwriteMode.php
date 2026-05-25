<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

class OverwriteMode
{
    use ConstantsTrait ;

    /**
     * If a document with the specified _key value exists already, return a unique constraint violation error
     * so that the insert operation fails.
     * This is also the default behavior in case the overwrite mode is not set,
     * and the overwrite flag is false or not set either.
     */
    public const string CONFLICT = 'conflict' ;

    /**
     * If f a document with the specified _key value exists already, nothing will be done and
     * no write operation will be carried out. The insert operation will return success in this case.
     * This mode does not support returning the old document version.
     * Using RETURN OLD will trigger a parse error, as there will be no old version to return.
     * RETURN NEW will only return the document in case it was inserted.
     * In case the document already existed, RETURN NEW will return null.
     */
    public const string IGNORE = 'ignore' ;

    /**
     * If a document with the specified _key value exists already, it will be overwritten with the specified document value.
     * This mode will also be used when no overwrite mode is specified but the overwrite flag is set to true.
     */
    public const string REPLACE = 'replace' ;

    /**
     * If a document with the specified _key value exists already, it will be patched (partially updated) with the specified document value.
     */
    public const string UPDATE = 'update' ;

}