<?php

namespace oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\enums\Char;
use oihana\enums\Order;
use oihana\traits\SortDefaultTrait;

use function oihana\core\strings\compile;
use function oihana\core\strings\key;

trait SortTrait
{
    use SortDefaultTrait ;

    /**
     * The collection (map) of all the sortable fields.
     */
    public ?array $sortable = null ;

    /**
     * Initialize the sortable array definition.
     * @param array $init
     * @return $this
     */
    public function initializeSortable( array $init = [] ):static
    {
        $this->sortable = $init[ AQL::SORTABLE ] ?? $this->sortable ;
        return $this ;
    }

    /**
     * Prepare the AQL Sort expression with a specific string parameter, ex: 'name,-identifier'
     * @param array $init
     * @param array|null $sortable
     * @param string $docRef
     * @return string|null
     */
    public function prepareSort
    (
        array  $init     = [] ,
        ?array $sortable = null ,
        string $docRef   = AQL::DOC
    )
    :?string
    {
        $sort       = $init[ Arango::SORT ] ?? $this->sortDefault ;
        $sortable ??= $this->sortable ;
        $orders     = is_array( $sort ) ? $sort : [] ;
        if( is_string( $sort ) )
        {
            $criteria = explode( Char::COMMA , $sort ) ;
            if( count( $criteria ) > 0 )
            {
                foreach( $criteria as $key )
                {
                    if( !empty( $key ) )
                    {
                        $first = $key[0] ;

                        if( $first == Char::HYPHEN )
                        {
                            $order = Order::DESC ;
                            $key   = ltrim( $key , Char::HYPHEN ) ;
                        }
                        else
                        {
                            $order = Order::ASC ;
                        }

                        if( is_array( $sortable ) )
                        {
                            if( array_key_exists( $key , $sortable )  )
                            {
                                $orders[] = key
                                (
                                    compile( $sortable[ $key ] ?? null , Char::DOT ) ,
                                    $docRef
                                )
                                . Char::SPACE
                                . $order ;
                            }
                        }
                        else
                        {
                            $orders[] = key( $key , $docRef ) . Char::SPACE . $order ;
                        }
                    }
                }
            }
        }
        return compile( $orders , Char::COMMA . Char::SPACE ) ;
    }
}
