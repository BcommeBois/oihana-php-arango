<?php

namespace oihana\arango\models\traits\aql\fields;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\functions\strings\concat;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

trait FieldUrlApi
{
    /**
     * @param string $key
     * @param string $basePath
     * @param string $doc
     * @param string|null $keyName
     * @return string
     * @throws UnsupportedOperationException
     */
    protected function fieldUrlApi( string $key , string $basePath = '' , string $doc = AQL::DOC , ?string $keyName = null ): string
    {
        return keyValue( $key , concat
        ([
            betweenDoubleQuotes( $basePath ) ,
            key( $keyName ?? $key , $doc ) // TODO test it
        ])) ;
    }
}
