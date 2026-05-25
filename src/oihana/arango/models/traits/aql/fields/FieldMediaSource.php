<?php

namespace oihana\arango\models\traits\aql\fields;

use oihana\exceptions\UnsupportedOperationException;
use ReflectionException;
use oihana\arango\db\enums\AQL;
use oihana\enums\Char;

use org\schema\constants\Prop;

use function oihana\arango\db\functions\documents\merge;
use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\functions\strings\concat;
use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

trait FieldMediaSource
{
    /**
     * @param string $key
     * @param string $basePath
     * @param string $doc
     * @return string
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    protected function fieldMediaSource( string $key , string $basePath , string $doc = AQL::DOC ): string
    {
        // $basePath = $this->config[ Config::APP ][ Config::MEDIA_URL ] ?? Char::EMPTY ;

        // $key . ': IS_ARRAY(doc.source)
        //           ? ( FOR source IN doc.source RETURN MERGE( source , { contentUrl : CONCAT( "mediaUrl" , s.contentUrl ) } ) )
        //           : null

        $docSource = key( Prop::SOURCE , $doc ) ;
        $varSource = AQL::DOC . Char::UNDERLINE . Prop::SOURCE ;

        return keyValue( $key , ternary
        (
            isArray( $docSource ) ,
            betweenParentheses
            ([
                aqlFor([ AQL::DOC_REF => $varSource , AQL::IN => $docSource ]),
                aqlReturn( merge
                ([
                    $varSource ,
                    aqlDocument
                    ([
                        [
                            Prop::CONTENT_URL ,
                            concat([ betweenDoubleQuotes( $mediaUrl ) , key( Prop::CONTENT_URL , $varSource ) ])
                        ]
                    ])
                ]))
            ]) ,
            AQL::NULL
        )) ;
        // return $key . ': IS_ARRAY( ' . $doc . '.source ) ? ( FOR doc_source IN IS_ARRAY( ' . $doc . '.source ) ? ' . $doc . '.source : [] RETURN MERGE( doc_source , { contentUrl : CONCAT( "' . $mediaUrl . '" , doc_source.contentUrl ) } ) ) : null ' ;
    }
}
