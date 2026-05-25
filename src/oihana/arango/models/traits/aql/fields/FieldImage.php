<?php

namespace oihana\arango\models\traits\aql\fields;

use oihana\arango\db\enums\AQL;
use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;
use org\schema\constants\Prop;

use function oihana\arango\db\functions\strings\concat;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

trait FieldImage
{
    /**
     * Transform an AQL field to be an image url representation.
     *
     * @param string $key
     * @param string $basePath
     * @param string $doc
     * @param string|null $keyName
     *
     * @return string
     *
     * @throws UnsupportedOperationException
     */
    protected function fieldImage( string $key , string $basePath = '' , string $doc = AQL::DOC , ?string $keyName = null  ): string
    {
        $image = key( $keyName ?? Prop::IMAGE , $doc ) ;
        return keyValue( $key , ternary
        (
            $image ,
            concat
            ([
                betweenDoubleQuotes( $basePath . Prop::MEDIA ) ,
                betweenDoubleQuotes(Char::SLASH ) ,
                $image
            ] ) ,
            AQL::NULL
        ) );
    }
}
