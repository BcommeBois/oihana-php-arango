<?php

namespace oihana\arango\models\traits\aql\fields;

use oihana\arango\db\enums\AQL;

/**
 * This class is the generic Model class.
 */
trait FieldMediaThumbnail
{
    /**
     * @param string $key
     * @param string $basePath
     * @param string $doc
     * @return string
     */
    protected function fieldMediaThumbnail( string $key , string $basePath , string $doc = AQL::DOC ): string
    {
        return $key . ': IS_OBJECT( ' . $doc . '.thumbnail ) ? MERGE( ' . $doc . '.thumbnail , { contentUrl : CONCAT( "' . $basePath . '" , ' . $doc . '.thumbnail.contentUrl ) } ) : null' ;
    }
}
