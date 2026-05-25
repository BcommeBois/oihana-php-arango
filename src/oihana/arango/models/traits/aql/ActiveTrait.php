<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use org\schema\constants\Prop;

use function oihana\arango\db\operators\equal;
use function oihana\core\strings\key ;

/**
 * Inject the 'prepareActive' method to generates the doc.active == 1|0 predicates in a AQL query.
 */
trait ActiveTrait
{
    /**
     * @var bool
     */
    public bool $activable = false ;

    /**
     * Initialize the activable flag to check if the documents are 'active' or not.
     * @param array $init
     * @return static
     */
    public function initializeActivable( array $init = [] ):static
    {
        $this->activable = $init[ Arango::ACTIVABLE ] ?? false ;
        return $this;
    }

    /**
     * Prepare the 'active' variable.
     * @param array $init
     * @param array|null $binds
     * @param string $docRef
     * @return string|null
     * @throws BindException
     */
    public function prepareActive
    (
         array $init   = [] ,
        ?array &$binds = null ,
        string $docRef = AQL::DOC
    )
    :?string
    {
        if( !$this->activable )
        {
            return Char::EMPTY ;
        }

        $active = $init[ Arango::ACTIVE ] ?? null ;
        return is_null( $active ) ? Char::EMPTY : equal
        (
            key( Arango::ACTIVE , $docRef ) ,
            $this->bind( $active ? 1 : 0 , $binds , Prop::ACTIVE )
        ) ;
    }
}
