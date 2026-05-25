<?php

namespace oihana\arango\commands;

use Throwable;
use UnexpectedValueException;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\actions\ArangoActions;
use oihana\arango\commands\enums\ArangoAction;
use oihana\arango\db\enums\ArangoConfig;
use oihana\commands\enums\CommandArg;
use oihana\commands\enums\ExitCode;
use oihana\commands\exceptions\ExitException;
use oihana\commands\Kernel;
use oihana\commands\options\CommandOption;

use oihana\enums\Char;

use oihana\files\exceptions\DirectoryException;

use function oihana\commands\helpers\clearConsole;
use function oihana\files\deleteTemporaryDirectory;

/**
 * The command to manage an ArangoDB database.
 */
class ArangoCommand extends Kernel
{
    /**
     * Creates a new ArangoDBCommand.
     * @param string|null $name
     * @param Container|null $container
     * @param array $init
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct( ?string $name , ?Container $container = null , array $init = [] )
    {
        parent::__construct( $name , $container , $init );
        $this->dateFormat = $init[ ArangoCommandParam::DATE_FORMAT ] ?? $this->dateFormat ;
        $this->timezone   = $init[ ArangoCommandParam::TIMEZONE    ] ?? $this->timezone ;
        $this->directory  = $init[ ArangoCommandParam::DIRECTORY   ] ?? $this->directory  ;
        $this->initializeArangoDB( $init );
        $this->initializeEncrypt( $init );
        $this->initializePassphrase( $init );
    }

    use ArangoActions ;

    /**
     * The default name of the command.
     */
    public const string NAME = 'command:arangodb' ;

    /**
     * Configures the current command.
     * @return void
     */
    protected function configure(): void
    {
        // ------ arguments

        CommandArg::configureAction
        (
            command     : $this ,
            description : 'Action to perform an arangoDB process: dump, restore, etc.'
        ) ;

        // ------ options

        CommandOption::configureClear( $this ) ;

        $this->addOption   ( CommandOption::PASS_PHRASE  , 'p'   , InputOption::VALUE_OPTIONAL  , 'The encryption passphrase to dump/restore the database.' ) ;

        $this->addOption   ( ArangoCommandOption::DATE      , 'd'   , InputOption::VALUE_OPTIONAL  , 'The Date of the dump to backup or restore.' ) ;
        $this->addOption   ( ArangoCommandOption::DIRECTORY , 'dir' , InputOption::VALUE_OPTIONAL  , 'The directory to dump and restore the database.' ) ;
        $this->addOption   ( ArangoCommandOption::ENCRYPT   , 'e'   , InputOption::VALUE_NONE      , 'Enabled the encryption to dump/restore the database.' ) ;
        $this->addOption   ( ArangoCommandOption::FILE      , 'f'   , InputOption::VALUE_OPTIONAL  , 'The file to dump/restore.' ) ;
        $this->addOption   ( ArangoCommandOption::LAST      , 'la'  , InputOption::VALUE_NONE      , 'Search the last dump file and restore it.' ) ;
        $this->addOption   ( ArangoCommandOption::LIST      , 'l'   , InputOption::VALUE_NONE      , 'Display a list of files.' ) ;

        $this->addOption ( ArangoConfig::DATABASE , null  , InputOption::VALUE_OPTIONAL  , 'The arangoDB database name.' ) ;
        $this->addOption ( ArangoConfig::ENDPOINT , null  , InputOption::VALUE_OPTIONAL  , 'The endpoint of the arangoDB server.' ) ;
        $this->addOption ( ArangoConfig::PASSWORD , null  , InputOption::VALUE_OPTIONAL  , 'The password of the arangoDB database.' ) ;
        $this->addOption ( ArangoConfig::USER     , null  , InputOption::VALUE_OPTIONAL  , 'The user of the arangoDB database.' ) ;
    }

    /**
     * Executes the current command.
     * @return int 0 if everything went fine, or an exit code
     * @throws LogicException When this abstract method is not implemented
     * @throws DirectoryException
     * @see setCode()
     */
    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        $this->initializeConsoleLogger( $output ) ;

        clearConsole( $input->getOption( CommandOption::CLEAR ) ?? $this->commandOptions?->clearable ?? false ) ;

        $this->action = $input->getArgument(CommandArg::ACTION ) ?? Char::EMPTY ;

        $this->assertActions( $this->action ) ;

        $this->getApplication()->setSignalsToDispatchEvent( (int) [ SIGINT , SIGTERM , SIGUSR1 , SIGUSR2 , SIGALRM ] );

        [ $io , $timestamp ] = $this->startCommand( $input , $output ) ;

        try
        {
            if( ArangoAction::includes( $this->action ) && method_exists( $this , $this->action ) )
            {
                $status = $this->{ $this->action }( $input , $output ) ;
            }
            else
            {
                throw new UnexpectedValueException( 'The action argument is not valid or not defined.' ) ;
            }
        }
        catch( ExitException )
        {
            $status = ExitCode::SUCCESS ;
        }
        catch ( Throwable $exception )
        {
            $io->error( sprintf( 'The command failed :  %s' , $exception->getMessage() ) ) ;
            $status = ExitCode::FAILURE ;
        }

        $io->newLine() ;

        $this->cleanup();

        return $this->endCommand( $input , $output , $status , $timestamp ) ;
    }

    /**
     * Cleanup the command when is finished.
     * @return void
     * @throws DirectoryException
     */
    protected function cleanup():void
    {
        deleteTemporaryDirectory( [ $this->id , $this->getName() , $this->action ] ) ;
    }

    // ------ Signals

    /**
     * The destructor method will be called as soon as there are no other references to a particular object,
     * or in any order during the shutdown sequence.
     * @throws DirectoryException
     */
    public function __destruct()
    {
        $this->cleanup() ;
    }

    /**
     * Returns the list of signals to subscribe.
     */
    public function getSubscribedSignals() : array
    {
        return [ SIGINT , SIGTERM , SIGUSR1 , SIGUSR2 , SIGALRM ] ;
    }

    /**
     * The method will be called when the application is signaled.
     * @return int|false The exit code to return or false to continue the normal execution
     * @throws DirectoryException
     */
    public function handleSignal( int $signal, int|false $previousExitCode = 0 ) : int|false
    {
        $this->cleanup() ;
        return false;
    }
}