<?php

namespace oihana\arango\models\traits;

use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;

use org\schema\constants\Prop;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\core\strings\compile;

/**
 * Helper to manage a list of items in a ArangoDB document.
 */
trait ListItemTrait
{
    /**
     * @param string $owner
     * @param $value
     * @param string $keyList
     * @param array $init
     * @return object|null
     * @throws ArangoException
     * @throws ReflectionException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function deleteListItem( string $owner , $value , string $keyList , array $init = [] ) :?object
    {
        $key        = $init[ Arango::KEY ] ?? Prop::_KEY ;
        $binds      =
        [
            Arango::KEY_LIST => $keyList ,
            Arango::OWNER    => $owner ,
            Arango::VALUE    => $value ,
        ] ;

        $query =
        [
            'FOR doc IN ' . $this->collection ,
            'FILTER doc.' . $key . ' == @owner' ,
            'UPDATE doc WITH {' ,
            '@keyList: REMOVE_VALUE( doc.@keyList , @value )' ,
            ',' ,
            'modified: DATE_ISO8601( DATE_NOW() )' ,
            '}' ,
            'IN ' . $this->collection ,
            'RETURN NEW'
        ] ;

        return $this->getObject( compile( $query ) , $binds ) ;
    }

    /**
     * @param string $owner
     * @param array $items
     * @param string $keyList
     * @param array $init
     *
     * @return object|null
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function deleteListItemAll( string $owner , array $items , string $keyList , array $init = [] ): ?object
    {
        $key    = $init[ 'key' ] ?? '_key' ;
        $fields = [ '@keyList: REMOVE_VALUES( doc.@keyList , TO_ARRAY( @items ) )' ] ;
        return $this->updateListItems( $key , $fields , $owner , $items , $keyList ) ;
    }

    /**
     * @param $owner
     * @param $items
     * @param $keyList
     * @param array $init
     *
     * @return object|null
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function insertListItems( $owner , $items , $keyList , array $init = [] ): ?object
    {
        $key    = $init[ 'key' ] ?? '_key' ;
        $fields = [ '@keyList: APPEND( doc.@keyList , TO_ARRAY( @items ) , true )' ] ;
        return $this->updateListItems( $key , $fields , $owner , $items , $keyList ) ;
    }

    /**
     * @param string $key
     * @param array $fields
     * @param $owner
     * @param $items
     * @param $keyList
     *
     * @return object|null
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function updateListItems(string $key , array $fields , $owner , $items , $keyList ): ?object
    {
        $fields[] = 'modified: DATE_ISO8601( DATE_NOW() )' ;

        $binds =
        [
            'owner'   => $owner ,
            'items'   => $items ,
            'keyList' => $keyList
        ] ;

        $query = 'FOR doc IN ' . $this->collection . ' FILTER doc.' . $key . ' == @owner '
               . 'UPDATE doc WITH { ' . implode(', ' , $fields ) . ' } IN ' . $this->collection . ' '
               . 'RETURN NEW' ;

        return $this->getObject( $query , $binds ) ;
    }
}
