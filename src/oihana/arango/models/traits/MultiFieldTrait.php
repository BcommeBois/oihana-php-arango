<?php

namespace oihana\arango\models\traits;

use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\enums\Char;

use org\schema\constants\Prop;

/**
 * Helper to manage a multi field item in a ArangoDB document.
 */
trait MultiFieldTrait
{
    /**
     * @param string $id
     * @param string  $idField
     * @param array $init
     * @return object|null
     * @throws ArangoException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function deleteInMultiField( string $id , string  $idField , array $init = [] ): ?object
    {
        $field = $init[ Arango::FIELD ] ?? null ;
        $key   = $init[ Arango::KEY   ] ?? Prop::_KEY ;
        $num   = $init[ Arango::NUM   ] ?? null ;

        $extraQuery = Char::EMPTY ;
        $fields     = [] ;
        $binds      =
        [
            Prop::KEY => $id ,
            $field    => $idField
        ];

        $this->deleteInMultiFieldHelper( $field , $num , $fields , $extraQuery  ) ;

        $query = 'FOR doc IN ' . $this->collection . ' FILTER doc.' . $key . ' == @key ' . $extraQuery . ' '
               . 'UPDATE doc WITH { ' . implode( ', ' , $fields ) . ' } IN ' . $this->collection . ' '
               . 'RETURN NEW';

        return $this->getObject( $query , $binds ) ;
    }

    /**
     * @param string $id
     * @param array $init
     * @return object|null
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function deleteReverseInMultiField( string $id , array $init = [] ): ?object
    {
        $field = $init[ Arango::FIELD ] ?? null ;
        $num   = $init[ Arango::NUM   ] ?? null ;

        $extraQuery = Char::EMPTY ;
        $fields     = [] ;
        $binds      = [ $field => $id ];

        $this->deleteInMultiFieldHelper( $field , $num , $fields , $extraQuery  ) ;

        $query = 'FOR doc IN ' . $this->collection . ' FILTER POSITION( doc.hasPart , @' . $field . ') ' . $extraQuery . ' '
               . 'UPDATE doc WITH { ' . implode( ', ' , $fields ) . ' } IN ' . $this->collection . ' RETURN NEW' ;

        return $this->getObject( $query , $binds ) ;
    }

    protected function deleteInMultiFieldHelper( $field , $num , &$fields , &$extraQuery ):void
    {
        if( array_key_exists( $field , $this->fillable ) )
        {
            $extraQuery = 'LET newMultiField = REMOVE_VALUE( doc.' . $field . ' , @' . $field.' ) ' ;
            $fields[]   = $field . ': newMultiField' ;
            if( array_key_exists( $num , $this->fillable ) )
            {
                $fields[] = $num . ': LENGTH( newMultiField )' ;
            }
        }

        $fields[] = 'modified: DATE_ISO8601( DATE_NOW() )' ;
    }

    /**
     * @param $value
     * @param $idField
     * @param array $init
     * @return bool
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function existsInMultiField($value , $idField , array $init = [] ) :bool
    {

        $field = $init[ Arango::FIELD ] ?? null ;
        $key   = $init[ Arango::KEY   ] ?? Prop::_KEY ;

        $query = 'RETURN COUNT( FOR doc IN ' . $this->collection . ' '
               . 'FILTER doc.' . $key . ' == @value && POSITION( doc.' . $field . ' , @field ) '
               . 'RETURN 1 )';

        return (bool) $this->getFirstResult( $query , [ 'value' => $value, 'field' => $idField ] ) ;
    }

    /**
     * @param $id
     * @param $idField
     * @param array $init
     * @return object|null
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function insertInMultiField($id , $idField , array $init = [] ) :?object
    {
        $field = $init[ Arango::FIELD ] ?? null ;
        $key   = $init[ Arango::KEY   ] ?? Prop::_KEY ;
        $num   = $init[ Arango::NUM   ] ?? null ;
        $side  = $init[ Arango::SIDE  ] ?? 'left' ;

        $binds      = [ Arango::KEY => $id , $field => $idField ];
        $fields     = [] ;
        $extraQuery = '' ;

        if( array_key_exists( $field , $this->fillable ) )
        {
            if( $side == 'right' )
            {
                $s = 'PUSH' ;
            }
            else
            {
                $s = 'UNSHIFT' ;
            }

            $extraQuery = 'LET newMultiField = ' . $s . '( doc.' . $field . ' , @' . $field.' , true ) ' ;

            $fields[] = $field . ': newMultiField ' ;
            if( array_key_exists( $num , $this->fillable ) )
            {
                $fields[] = $num . ': LENGTH( newMultiField )' ;
            }
        }

        $fields[] = 'modified: DATE_ISO8601( DATE_NOW() )' ;

        $query = 'FOR doc IN ' . $this->collection . ' FILTER doc.' . $key . ' == @key ' . $extraQuery . ' '
            . 'UPDATE doc WITH { ' . implode( ', ' , $fields ) . ' } IN ' . $this->collection . ' RETURN NEW';

        return $this->getObject( $query , $binds ) ;
    }

    /**
     * @param $id
     * @param array $init
     * @return object|null
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function updateDateParentMultiField( $id , array $init = [] ): ?object
    {
        $key   = $init[ 'key' ] ?? '_key' ;
        $query = 'FOR doc IN ' . $this->collection . ' FILTER POSITION( doc.' . $key . ' , @value ) '
            . 'UPDATE doc WITH { modified: DATE_ISO8601( DATE_NOW() ) } IN ' . $this->collection . ' '
            . 'RETURN NEW';

        return $this->getObject( $query , [ 'value' => $id ] ) ;
    }

    /**
     * @param $id
     * @param $idField
     * @param array $init
     * @return object|null
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function updateInMultiField( $id , $idField , array $init = [] ): ?object
    {
        $field    = $init[ Arango::FIELD    ] ?? null ;
        $key      = $init[ Arango::KEY      ] ?? Prop::_KEY ;
        $num      = $init[ Arango::NUM      ] ?? null ;
        $position = $init[ Arango::POSITION ] ?? 0 ;

        $binds =
        [
            Prop::KEY      => $id ,
            $field         => $idField,
            Prop::POSITION => $position
        ];

        $fields     = [] ;
        $extraQuery = '' ;

        if( array_key_exists( $field , $this->fillable ) )
        {
            $extraQuery = ' '
                . 'LET newField = REMOVE_VALUE( doc.' . $field . ' , @' . $field.' ) '
                . 'LET startField = SLICE( newField , 0 , @position ) '
                . 'LET endField = SLICE( newField , @position ) '
                . 'LET newMultiField = UNION( PUSH( startField , @' . $field . ' , true ) , endField ) ' ;

            $fields[] = $field . ': newMultiField' ;
            if( array_key_exists( $num , $this->fillable ) )
            {
                $fields[] = $num . ': LENGTH( newMultiField )' ;
            }
        }

        $fields[] = 'modified: DATE_ISO8601( DATE_NOW() )' ;

        $query = 'FOR doc IN ' . $this->collection . ' FILTER doc.' . $key . ' == @key ' . $extraQuery . ' '
               . 'UPDATE doc WITH { ' . implode( ', ' , $fields ) . ' } IN ' . $this->collection . ' RETURN NEW';

        return $this->getObject( $query , $binds ) ;
    }
}
