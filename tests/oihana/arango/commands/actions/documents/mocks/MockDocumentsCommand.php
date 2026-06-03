<?php

namespace tests\oihana\arango\commands\actions\documents\mocks;

use oihana\arango\commands\actions\documents\DocumentsCommandActions;
use oihana\arango\models\Documents;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reusable host for the document command-action traits.
 *
 * It composes {@see DocumentsCommandActions} (all the count/get/insert/… action
 * methods, which are protected) and exposes a thin public proxy per action so the
 * console flow can be driven from tests with a `$documents` model double.
 */
class MockDocumentsCommand
{
    use DocumentsCommandActions ;

    public function __construct( ?Documents $documents )
    {
        $this->documents = $documents ;
    }

    public function callCount( InputInterface $input , OutputInterface $output ) :int
    {
        return $this->count( $input , $output ) ;
    }

    public function callFetchDocuments( OutputInterface $output , ?Documents $ref = null , string $name = 'documents' , array $init = [] ) :array
    {
        return $this->fetchDocuments( $output , $ref , $name , $init ) ;
    }

    public function callInitializeDocuments( array $init = [] , ?ContainerInterface $container = null ) :static
    {
        return $this->initializeDocuments( $init , $container ) ;
    }

    public function callOutputDocuments( array $documents , InputInterface $input , OutputInterface $output , ?array $fields = null ) :void
    {
        $this->outputDocuments( $documents , $input , $output , $fields ) ;
    }

    public function callExist( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        return $this->exist( $input , $output , $option ) ;
    }

    public function callGet( InputInterface $input , OutputInterface $output , array $option = [] ) :int
    {
        return $this->get( $input , $output , $option ) ;
    }

    public function callLast( InputInterface $input , OutputInterface $output , array $option = [] ) :int
    {
        return $this->last( $input , $output , $option ) ;
    }

    public function callList( InputInterface $input , OutputInterface $output ) :int
    {
        return $this->list( $input , $output ) ;
    }

    public function callDelete( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        return $this->delete( $input , $output , $option ) ;
    }

    public function callInsert( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        return $this->insert( $input , $output , $option ) ;
    }

    public function callReplace( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        return $this->replace( $input , $output , $option ) ;
    }

    public function callTruncate( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        return $this->truncate( $input , $output , $option ) ;
    }

    public function callUpdate( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        return $this->update( $input , $output , $option ) ;
    }

    public function callUpsert( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        return $this->upsert( $input , $output , $option ) ;
    }
}
