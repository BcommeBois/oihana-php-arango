<?php

namespace oihana\arango\models\traits\aql\fields;

use oihana\arango\db\enums\AQL;

trait FieldThesaurusImage
{
    /**
     * @param string $key
     * @param string $doc
     * @param string $basePath
     * @return string
     */
    protected function fieldThesaurusImage( string $key , string $basePath = '' , string $doc = AQL::DOC ): string
    {
        return $key . ': TO_BOOL( ' . $doc . '.' . $key . ' ) == true ? CONCAT( "' . $basePath . '" , ' . $doc . '.path , "/" , ' . $doc . '._key , "/image" ) : null' ;
    }
}
