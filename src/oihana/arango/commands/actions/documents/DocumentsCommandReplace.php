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

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\enums\DocumentsCommandParam;
use oihana\commands\enums\ExitCode;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function oihana\commands\helpers\success;

trait DocumentsCommandReplace
{
    use DocumentsCommandTrait ;

    /**
     * Replace a document in the collection.
     *
     * @param InputInterface $input The console input instance.
     * @param OutputInterface $output The console output instance.
     * @param string|array|null $option An array where the first element is the identifier of the document to retrieve.
     *
     * @return int The exit code for the command, typically `ExitCode::SUCCESS`.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ArangoException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws ReflectionException
     */
    protected function replace( InputInterface $input, OutputInterface $output , mixed $option = null ):int
    {
        $this->assertDocuments() ;

        if ( is_string( $option ) )
        {
            $option = explode(Char::PIPE, $option);
        }
        else if ( !is_array( $option ) )
        {
            throw new UnexpectedValueException('The option must be a string or an array.' ) ;
        }

        [ $value , $document , $key ] = array_pad( $option , 3 , null ) ;

        if( !isset( $value ) )
        {
            throw new UnexpectedValueException( 'The identifier of the document to replace must be defined.') ;
        }

        $document = json_decode( $document ) ;

        if( !isset( $document ) )
        {
            throw new UnexpectedValueException( 'The document to replace must be defined.') ;
        }

        $io = $this->getIO( $input , $output ) ;

        $io->section( 'Replace a document in a collection' ) ;

        $result = $this->documents->replace
        ([
            DocumentsCommandParam::EXCLUDES => $this->removeKeys ,
            DocumentsCommandParam::DOC      => $document ,
            DocumentsCommandParam::KEY      => $key ,
            DocumentsCommandParam::VALUE    => $value ,
        ]) ;

        if( isset( $result ) )
        {
            $io->text( success( sprintf( "The document <info>%s</info> is replaced" , $value ) ) );
            if( $output->isVerbose() )
            {
                $this->outputDocuments( [ $result ] , $input , $output ) ;
            }
        }

        return ExitCode::SUCCESS ;
    }
}