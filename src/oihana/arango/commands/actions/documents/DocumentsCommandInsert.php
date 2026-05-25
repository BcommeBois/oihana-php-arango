<?php

namespace oihana\arango\commands\actions\documents;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;
use UnexpectedValueException;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\enums\DocumentsCommandParam;
use oihana\commands\enums\ExitCode;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait DocumentsCommandInsert
{
    use DocumentsCommandTrait ;

    /**
     * Insert a new document in the collection.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string|array|null $option The option to configure the action.
     *
     * @return int
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ArangoException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    protected function insert( InputInterface $input, OutputInterface $output , mixed $option = null ):int
    {
        $this->assertDocuments() ;

        if ( is_string( $option ) )
        {
            $option = explode(Char::PIPE , $option ) ;
        }
        else if ( !is_array( $option ) )
        {
            throw new UnexpectedValueException('The option must be a string or an array.' ) ;
        }

        [ $document ] = array_pad( $option , 1 , null ) ;

        $document = json_decode( $document , true ) ;

        if( !isset( $document ) )
        {
            throw new UnexpectedValueException( 'The document to insert must be defined.') ;
        }

        $io = $this->getIO( $input , $output ) ;

        $io->section( 'Insert a new document in the collection' ) ;

        $result = $this->documents->insert
        ([
            DocumentsCommandParam::DOC      => $document ,
            DocumentsCommandParam::EXCLUDES => $this->removeKeys
        ]) ;

        if( isset( $result ) )
        {
            $io->success( "The document is inserted" ) ;
            if( $output->isVerbose() )
            {
                $this->outputDocuments( [ $result ] , $input , $output ) ;
            }
        }

        return ExitCode::SUCCESS ;
    }
}