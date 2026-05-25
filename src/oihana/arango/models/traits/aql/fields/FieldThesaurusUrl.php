<?php

namespace oihana\arango\models\traits\aql\fields;

use oihana\arango\db\enums\AQL;

trait FieldThesaurusUrl
{
    /**
     * @param string $key
     * @param string $basePath
     * @param string $doc
     * @return string
     */
    protected function fieldThesaurusUrl( string $key , string $basePath = '' , string $doc = AQL::DOC ): string
    {
        return $key . ': CONCAT( "' . $basePath . '" , ' . $doc . '.path )' ;
    }
}
