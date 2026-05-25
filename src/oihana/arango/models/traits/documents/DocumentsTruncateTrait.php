<?php

namespace oihana\arango\models\traits\documents;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\traits\ArangoTrait;

trait DocumentsTruncateTrait
{
    use ArangoTrait ;

    /**
     * Truncates the collection.
     * Warning: the method removes all documents.
     *
     * @param array{ collection:null|string } $init
     *
     * @return bool
     */
    public function truncate( array $init = [] ) :bool
    {
        return $this->collectionTruncate( $init[ AQL::COLLECTION ] ?? $this->collection ) ;
    }
}