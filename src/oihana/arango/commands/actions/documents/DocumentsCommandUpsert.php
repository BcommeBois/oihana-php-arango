<?php

namespace oihana\arango\commands\actions\documents;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use JsonSerializable;
use ReflectionException;
use UnexpectedValueException;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\enums\DocumentsCommandParam;
use oihana\commands\enums\ExitCode;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function oihana\commands\helpers\success;

trait DocumentsCommandUpsert
{
    use DocumentsCommandTrait ;

    /**
     * Upsert a document in the collection.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string|JsonSerializable|null|array{
     *      collection?  :string|null ,
     *      filter?      :array|string|null ,
     *      search?      :array|string|null ,
     *      insert?      :array|string|null ,
     *      update?      :array|string|null ,
     *      overwrite?   :string|null ,
     *      options?     :array|string|JsonSerializable|null ,
     *  } $option
     *
     * @return int
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws ArangoException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    protected function upsert
    (
        InputInterface                     $input  ,
        OutputInterface                    $output ,
        array|string|JsonSerializable|null $option
    )
    :int
    {
        $this->assertDocuments() ;

        if( is_string( $option ) )
        {
            $option = json_decode( $option , true ) ;
        }

        if( !is_array( $option ) )
        {
            throw new UnexpectedValueException( 'The option argument of the method must be an array : reference or a json string expression' ) ;
        }

        $io      = $this->getIO( $input , $output ) ;
        $verbose = $output->isVerbose() ;

        $io->section( 'Upsert a document.' ) ;

        $result = $this->documents->upsert
        ([
            DocumentsCommandParam::REMOVE_KEYS => $this->removeKeys ,
            ...$option
        ]) ;

        if( isset( $result ) )
        {
            $io->text( success( 'The document is upserted' ) ) ;
            if( $verbose )
            {
                $this->getJson( $output )->writeJson( $result ) ;
            }
        }

        return ExitCode::SUCCESS ;
    }
}