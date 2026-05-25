<?php

namespace oihana\arango\models\traits;

use oihana\arango\models\traits\aql\ActiveTrait;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\FacetTrait;
use oihana\arango\models\traits\aql\FilterTrait;
use oihana\arango\models\traits\aql\LimitTrait;
use oihana\arango\models\traits\aql\SearchTrait;
use oihana\arango\models\traits\aql\SortTrait;

/**
 * This trait defines all facet helpers in the Model class.
 */
trait AQLQueryTrait
{
    use ActiveTrait ,
        BindTrait ,
        FilterTrait ,
        FacetTrait ,
        LimitTrait ,
        SearchTrait ,
        SortTrait ;
}