<?php

namespace tests\oihana\arango\casbin;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

/**
 * Mock Documents model for unit testing without ArangoDB.
 *
 * Stores documents in memory and provides basic CRUD operations.
 */
class MockDocuments extends Documents
{
    private array $store = [] ;

    /**
     * Bypass parent constructor — no container or ArangoDB needed.
     */
    public function __construct()
    {
        // No parent constructor call — we don't need DI or ArangoDB
    }

    public function insert( array $init = [] ) :?object
    {
        $doc = $init[ Arango::DOC ] ?? [] ;
        $doc[ '_key' ] = (string) ( count( $this->store ) + 1 ) ;
        $this->store[] = $doc ;
        return (object) $doc ;
    }

    public function list( array $init = [] ) :array
    {
        $conditions = $init[ Arango::CONDITIONS ] ?? [] ;

        if( empty( $conditions ) )
        {
            return array_map( fn( $d ) => (object) $d , $this->store ) ;
        }

        return array_values( array_filter
        (
            array_map( fn( $d ) => (object) $d , $this->store ) ,
            function( $doc ) use ( $conditions )
            {
                foreach( $conditions as $key => $value )
                {
                    if( ( $doc->$key ?? null ) !== $value )
                    {
                        return false ;
                    }
                }
                return true ;
            }
        )) ;
    }

    public function delete( array $init = [] ) :array
    {
        $key   = $init[ Arango::KEY   ] ?? '_key' ;
        $value = $init[ Arango::VALUE ] ?? null ;

        $deleted = [] ;

        if( is_array( $value ) )
        {
            foreach( $value as $v )
            {
                $this->store = array_values( array_filter
                (
                    $this->store ,
                    function( $doc ) use ( $key , $v , &$deleted )
                    {
                        if( ( $doc[ $key ] ?? null ) === $v )
                        {
                            $deleted[] = $doc ;
                            return false ;
                        }
                        return true ;
                    }
                )) ;
            }
        }

        return array_map( fn( $d ) => (object) $d , $deleted ) ;
    }

    public function update( array $init = [] ) :?object
    {
        $doc   = $init[ Arango::DOC ] ?? [] ;
        $key   = $init[ Arango::KEY   ] ?? '_key' ;
        $value = $init[ Arango::VALUE ] ?? null ;

        foreach( $this->store as &$stored )
        {
            if( ( $stored[ $key ] ?? null ) === $value )
            {
                $stored = [ ...$stored , ...$doc ] ;
                return (object) $stored ;
            }
        }

        return null ;
    }

    public function truncate() :bool
    {
        $this->store = [] ;
        return true ;
    }

    public function getStore() :array
    {
        return $this->store ;
    }
}
