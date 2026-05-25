<?php

namespace tests\oihana\arango\models\traits\queries\mocks;

use oihana\arango\models\traits\queries\UpsertQueryTrait;

class UpsertQueryTraitMock
{
    public function __construct( array $init = [])
    {
        $this->collection = 'users_test_collection';
        $this->initializeQueryID( $init ) ;
    }

    use UpsertQueryTrait;
}