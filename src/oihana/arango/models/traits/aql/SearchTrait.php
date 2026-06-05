<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\enums\Arango;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use function oihana\arango\db\functions\strings\like;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * This class is the generic Model class.
 */
trait SearchTrait
{
    use BindTrait ;

    /**
     * The searchable fields.
     */
    public ?array $searchable = [] ;

    /**
     * The 'searchable' parameter key.
     */
    public const string SEARCHABLE = 'searchable' ;

    /**
     * Initialize the 'searchable' property.
     *
     * @param array $init
     *
     * @return static
     */
    public function initializeSearchable( array $init = [] ) :static
    {
        $this->searchable = $init[ self::SEARCHABLE ] ?? $this->searchable ;
        return $this ;
    }

    /**
     * Prepare the searchable AQL conditions.
     *
     * @param array|string|null $search
     * @param ?array $binds
     * @param ?array $searchable
     * @param  string $docRef
     *
     * @return ?string
     *
     * @throws BindException
     *
     * @example
     * ```
     * ?search=Marc,Marco
     * ```
     */
    public function prepareSearch
    (
        array|string|null $search     = [] ,
        ?array            &$binds     = null ,
        ?array            $searchable = null ,
        string            $docRef     = AQL::DOC
    )
    :?string
    {
        if( is_array( $search ) )
        {
            $search = $search[ Arango::SEARCH ] ?? null ;
        }

        if( is_string( $search ) && $search != Char::EMPTY )
        {
            $searchable = $searchable ?? $this->searchable ;
            $words      = explode ( Char::COMMA , $search ) ;
            if( count( $words ) > 0 && is_array( $searchable ) && count( $searchable ) > 0 )
            {
                $likes   = [] ;
                $index   = 0 ;
                foreach( $words as $word )
                {
                    $word = $this->bind
                    (
                        Char::MODULUS . $word . Char::MODULUS ,
                        $binds ,
                        AQL::SEARCH . Char::UNDERLINE . $index++
                    ) ;
                    foreach( $searchable as $field )
                    {
                        $likes[] = like( key( $field , $docRef ) , $word , caseInsensitive: true ) ;
                    }
                }
                return betweenParentheses
                (
                    expression : compile( $likes , Char::SPACE . Logic::OR . Char::SPACE ) ,
                    trim       : false
                ) ;
            }
        }
        return null ;
    }
}
