<?php

namespace oihana\arango\commands\actions\documents;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;
use UnexpectedValueException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\enums\DocumentsCommandParam;
use oihana\commands\enums\ExitCode;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use function oihana\commands\helpers\success;

trait DocumentsCommandUpdate
{
    use DocumentsCommandTrait ;

    /**
     * Updates a document in the collection.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed|null $option
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
    protected function update( InputInterface $input, OutputInterface $output , mixed $option = null ):int
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

        [ $value , $document , $key ] = array_pad( $option , 3 , null ) ;

        if( !isset( $value ) )
        {
            throw new UnexpectedValueException( 'The identifier of the document to update must be defined.') ;
        }

        $document = json_decode( $document ) ;

        if( !isset( $document ) )
        {
            throw new UnexpectedValueException( 'The document to update must be defined.') ;
        }

        $io = $this->getIO( $input , $output ) ;

        $io->section( 'Update a document in the collection' ) ;

        $result = $this->documents->update
        ([
            DocumentsCommandParam::DOC         => $document ,
            DocumentsCommandParam::KEY         => $key ,
            DocumentsCommandParam::REMOVE_KEYS => $this->removeKeys ,
            DocumentsCommandParam::VALUE       => $value ,
        ]) ;

        if( isset( $result ) )
        {
            $io->text( success( sprintf( "The document <info>%s</info> is updated" , $value ) ) );
            if( $output->isVerbose() )
            {
                $this->outputDocuments( [ $result ] , $input , $output ) ;
            }
        }

        return ExitCode::SUCCESS ;
    }
}