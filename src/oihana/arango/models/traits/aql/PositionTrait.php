<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\traits\ArangoTrait;
use oihana\enums\Char;
use org\schema\constants\Prop;

/**
 * This class is the generic Model class.
 */
trait PositionTrait
{
    use ArangoTrait ;

    public function preparePosition( array $fields , string $path ) :string
    {
        $position = $fields[ Prop::POSITION ] ?? null ;
        if( $position )
        {
            return 'LET ' . $fields[ Prop::POSITION ][ AQL::UNIQUE ]
                . ' = ( '
                . 'FOR doc_coll IN ' . $this->collection . Char::SPACE
                . 'FILTER POSITION( doc_coll.' . $path . ' , TO_NUMBER( @value ) ) == true ' // TODO verify the binding variable name ...
                . 'RETURN POSITION( doc_coll.' . $path . ' , TO_NUMBER( @value ) , true ) '
                . ')[0]' ;
        }
        else
        {
            return Char::EMPTY ;
        }
    }
}
