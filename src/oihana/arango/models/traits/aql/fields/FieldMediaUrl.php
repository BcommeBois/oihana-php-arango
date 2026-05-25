<?php

namespace oihana\arango\models\traits\aql\fields;

use oihana\arango\db\enums\AQL;

/**
 * This class is the generic Model class.
 */
trait FieldMediaUrl
{
    /**
     * Transform an AQL object field to be a boolean.
     * -> $key : doc.image ? CONCAT( "$url.'media'" , "/" , doc.image ) : null
     * @param string $key
     * @param string $doc
     * @param string $basePath
     * @return string
     */
    protected function fieldMediaUrl( string $key , string $doc = AQL::DOC , string $basePath = '' ): string
    {
        return $key . ': ' . $doc . '.contentUrl ? CONCAT( "' . $basePath . '" , ' . $doc . '.contentUrl ) : null' ;
    }
}
