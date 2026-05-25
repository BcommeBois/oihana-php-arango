<?php

namespace oihana\arango\commands\actions\documents;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\commands\enums\ExitCode;
use oihana\commands\traits\UITrait;
use oihana\enums\Pagination;
use oihana\models\enums\ModelParam;
use oihana\models\traits\ListModelTrait;

use org\schema\Thing;

use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function oihana\commands\helpers\notice;
use function oihana\commands\helpers\warning;

/**
 * The documents harvest command helper.
 */
trait DocumentsCommandHarvest
{
    use DocumentsCommandTrait ,
        DocumentsCommandUpsert ,
        ListModelTrait ,
        LockableTrait ,
        UITrait ;

    /**
     * Harvest the documents from a ListModel and upsert it in the documents collection.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed|null $option
     *
     * @return int 0 if everything went fine, or an exit code
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ArangoException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     *
     * @see setCode()
     */
    protected function harvest( InputInterface $input, OutputInterface $output , mixed $option = null ): int
    {
        $this->assertDocuments() ;
        $this->assertListModel() ;

        $io     = $this->getIO( $input , $output ) ;
        $status = ExitCode::SUCCESS ;

        $verbose = $output->isVerbose() ;

        if( !$this->lock() )
        {
            if( $verbose )
            {
                $io->text( warning( '⚠️ The command is already running in another process.' ) ) ;
            }
            return $status ;
        }

        if( $verbose )
        {
            $io->section( '🔧 Preparing the documents' );
        }

        $documents = $this->list->list( [ Pagination::LIMIT => 0 ] ) ;

        $this->outputDocuments( $documents , $input , $output ) ;

        if( empty( $documents ) )
        {
            if( $verbose )
            {
                $io->text( notice( '⚠️ No document to harvest.' ) ) ;
            }
            return ExitCode::SUCCESS ;
        }

        if( $verbose )
        {
            $io->section( '🚜 Harvesting the documents' ) ;
        }

        $progressBar = $this->createProgressBar( $output , count( $documents ) ) ;

        if( $verbose )
        {
            $progressBar->start();
        }

        /**
         * @var Thing $document
         */
        foreach( $documents as $document )
        {
            $id       = $document->id ;
            $search   = [ ModelParam::ID => $id ] ;
            $document = $document->jsonSerialize() ;

            $status += $this->upsert
            (
                input  : $input ,
                output : $output ,
                option :
                [
                    Arango::SEARCH => $search ,
                    Arango::INSERT => $document ,
                    Arango::UPDATE => $document ,
                ]
            ) ;

            if( $verbose )
            {
                $progressBar->setMessage( sprintf( '<info>Harvest the document "%s"</info>' , $id ) ) ;
                $progressBar->advance() ;
            }
        }

        if( $verbose )
        {
            $progressBar->setMessage( '<comment>Harvesting completed</comment>' ) ;
            $progressBar->finish();
            $io->newLine() ;
        }

        $this->release() ;

        return $status == ExitCode::SUCCESS ? $status : ExitCode::FAILURE ;
    }
}