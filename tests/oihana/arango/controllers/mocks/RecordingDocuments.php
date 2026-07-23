<?php

namespace tests\oihana\arango\controllers\mocks;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * A {@see MockDocuments} that records the `$init` array every model entry point
 * receives, then delegates to the real trait implementation.
 *
 * The write handlers build their model `$init` in place (route args, payload,
 * relations, value) and the only observable seam from the controller side is
 * what the model is finally called with — this double captures exactly that.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class RecordingDocuments extends MockDocuments
{
    /**
     * The recorded calls, each `[ method , init ]`, in invocation order.
     *
     * @var array<int,array{0:string,1:array}>
     */
    public array $calls = [] ;

    /**
     * @inheritDoc
     */
    public function delete( array $init = [] ) :null|array|object
    {
        $this->calls[] = [ 'delete' , $init ] ;
        return parent::delete( $init ) ;
    }

    /**
     * @inheritDoc
     */
    public function exist( array $init = [] ) :bool
    {
        $this->calls[] = [ 'exist' , $init ] ;
        return parent::exist( $init ) ;
    }

    /**
     * @inheritDoc
     */
    public function get( array $init = [] ) :?object
    {
        $this->calls[] = [ 'get' , $init ] ;
        return parent::get( $init ) ;
    }

    /**
     * @inheritDoc
     */
    public function insert( array $init = [] ) :?object
    {
        $this->calls[] = [ 'insert' , $init ] ;
        return parent::insert( $init ) ;
    }

    /**
     * @inheritDoc
     */
    public function replace( array $init = [] ) :?object
    {
        $this->calls[] = [ 'replace' , $init ] ;
        return parent::replace( $init ) ;
    }

    /**
     * @inheritDoc
     */
    public function update( array $init = [] ) :?object
    {
        $this->calls[] = [ 'update' , $init ] ;
        return parent::update( $init ) ;
    }

    /**
     * Returns the `$init` of the first recorded call to the given method.
     *
     * @param string $method The recorded model method name.
     *
     * @return array|null The captured init, or null when the method was never called.
     */
    public function initOf( string $method ) :?array
    {
        foreach( $this->calls as [ $name , $init ] )
        {
            if( $name === $method )
            {
                return $init ;
            }
        }
        return null ;
    }

    /**
     * Returns the recorded method names, in invocation order.
     *
     * @return array<int,string>
     */
    public function methods() :array
    {
        return array_map( fn( array $call ) => $call[ 0 ] , $this->calls ) ;
    }
}
