<?php

namespace oihana\arango\models\traits\aql;

use oihana\exceptions\BindException;

use xyz\oihana\schema\Pagination;

use function oihana\arango\db\operations\aqlLimit;

/**
 * Inject the LIMIT and OFFSET clause in a AQL query.
 */
trait LimitTrait
{
    /**
     * @throws BindException
     */
    public function prepareLimit( array $init = [] , ?array &$binds = null ) :?string
    {
        return aqlLimit( $init[ Pagination::LIMIT ] ?? 0 , $init[ Pagination::OFFSET ] ?? 0 , $binds );
    }
}
