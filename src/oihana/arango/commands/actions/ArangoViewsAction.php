<?php

namespace oihana\arango\commands\actions;

use Throwable;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\enums\ViewField;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\db\enums\ViewDiffStatus;
use oihana\arango\db\results\ViewDiffReport;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use oihana\commands\enums\ExitCode;
use oihana\commands\exceptions\ExitException;
use oihana\commands\traits\IOTrait;

use oihana\enums\Char;

// Manage the ArangoSearch views of the database
// $ php bin/console.php command:arangodb views                  (list the views)
// $ php bin/console.php command:arangodb views --diff           (compare the declared views with the server)
// $ php bin/console.php command:arangodb views --sync           (create the missing views, repair the drifted ones)
// $ php bin/console.php command:arangodb views --sync=a,b       (restrict the sync to these views)
// $ php bin/console.php command:arangodb views --drop=a,b       (drop these views)
// $ php bin/console.php command:arangodb views --drop           (interactive selection)

/**
 * Manages the ArangoSearch views of the ArangoDB database.
 *
 * The default behaviour lists the views of the live database through
 * {@see ArangoClientTrait::buildDatabase()} (like the `collections`
 * action). Three additional modes are available:
 *
 * - `--diff`        : compares the `AQL::VIEW` declaration of every configured
 *                     model with the server state and reports the differences
 *                     ({@see ViewDiffStatus}), plus the
 *                     orphan views (on the server, declared by no model).
 *                     Read-only — the lazy provisioning of the inspected
 *                     models is disabled through the container `lazy` entry.
 * - `--sync[=a,b]`  : same walk, but missing views are created and drifted
 *                     ones repaired with `updateProperties()`.
 * - `--drop[=a,b]`  : drops the given views (comma-separated), or offers an
 *                     interactive selection when no name is provided.
 *
 * The models to inspect are supplied via the `models` init key
 * ({@see \oihana\arango\commands\enums\ArangoCommandParam::MODELS}) as a
 * list of container ids of {@see Documents} definitions — the same
 * decoupling as the dump/restore configuration.
 *
 * @package oihana\arango\commands\actions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait ArangoViewsAction
{
    use ArangoClientTrait ,
        IOTrait ;

    /**
     * Container ids of the `Documents` models whose View declarations
     * the `--diff` / `--sync` modes inspect.
     *
     * @var array<int, string>
     */
    public array $models = [] ;

    /**
     * Manages the views of the database.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     * @throws ExitException When the interactive selection is exited.
     */
    protected function views( InputInterface $input , OutputInterface $output ) :int
    {
        $io = $this->getIO( $input , $output ) ;

        $drop = $input->getOption( ArangoCommandOption::DROP ) ;
        if( $drop !== false )
        {
            return $this->viewsDrop( $input , $output , $io , is_string( $drop ) ? $drop : null ) ;
        }

        $sync = $input->getOption( ArangoCommandOption::SYNC ) ;
        if( $sync !== false || $input->getOption( ArangoCommandOption::DIFF ) )
        {
            return $this->viewsReport( $input , $io , apply : $sync !== false , only : is_string( $sync ) ? $sync : null ) ;
        }

        return $this->viewsList( $input , $io ) ;
    }

    /**
     * Builds the live {@see Database} client from the
     * command options/configuration, or null (with an error printed) when no
     * usable endpoint is available.
     *
     * @param InputInterface $input
     * @param SymfonyStyle   $io
     * @return Database|null
     */
    private function viewsDatabase( InputInterface $input , SymfonyStyle $io ) :?Database
    {
        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;
        $endpoint = $input->getOption( ArangoCommandOption::ENDPOINT ) ?? $this->getEndpoint() ;
        $password = $input->getOption( ArangoCommandOption::PASSWORD ) ?? $this->getPassword() ;
        $username = $input->getOption( ArangoCommandOption::USER     ) ?? $this->getUsername() ;

        $db = $this->buildDatabase( $endpoint , $username , $password , $database ) ;
        if( $db === null )
        {
            $io->error( 'No ArangoDB HTTP client available (check the endpoint and database configuration).' ) ;
        }

        return $db ;
    }

    /**
     * Drops the given views (comma-separated), or offers an interactive
     * selection across the views of the database.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param SymfonyStyle    $io
     * @param string|null     $names Comma-separated view names, or null to select interactively.
     * @return int
     * @throws ExitException When the interactive selection is exited.
     */
    private function viewsDrop( InputInterface $input , OutputInterface $output , SymfonyStyle $io , ?string $names ) :int
    {
        $db = $this->viewsDatabase( $input , $io ) ;
        if( $db === null )
        {
            return ExitCode::FAILURE ;
        }

        $io->section( 'Drop views' ) ;

        $selection = array_values( array_filter( array_map( trim( ... ) , explode( Char::COMMA , $names ?? Char::EMPTY ) ) ) ) ;

        if( $selection === [] )
        {
            if( !$input->isInteractive() )
            {
                $io->error( 'No view names provided — use --drop=a,b or run interactively.' ) ;
                return ExitCode::FAILURE ;
            }

            try
            {
                $choices = array_map( fn( $view ) => $view[ ViewField::NAME ] ?? Char::EMPTY , $db->listViews() ) ;
            }
            catch( ArangoException $exception )
            {
                $io->error( 'Unable to list the views — ArangoDB HTTP API unreachable: ' . $exception->getMessage() ) ;
                return ExitCode::FAILURE ;
            }

            $choices = array_values( array_filter( $choices ) ) ;
            sort( $choices ) ;

            if( $choices === [] )
            {
                $io->text( 'There are no views in the database.' ) ;
                return ExitCode::SUCCESS ;
            }

            $choices[] = $this->exit ;

            $question = new ChoiceQuestion( '🗑️ Please select the view(s) to drop (comma-separated) :' , $choices , 0 ) ;
            $question->setMultiselect( true ) ;
            $question->setErrorMessage( '⚠️ The view %s is invalid.' ) ;

            $selection = (array) $this->getQuestionHelper()->ask( $input , $output , $question ) ;
            if( in_array( $this->exit , $selection , true ) )
            {
                throw new ExitException() ;
            }

            $io->newLine() ;
        }

        $status = ExitCode::SUCCESS ;
        foreach( $selection as $name )
        {
            try
            {
                $view = $db->view( $name ) ;
                if( $view->exists() )
                {
                    $view->drop() ;
                    $io->text( sprintf( '<info>✓</info> %s — dropped' , $name ) ) ;
                }
                else
                {
                    $io->text( sprintf( '<comment>·</comment> %s — not found' , $name ) ) ;
                }
            }
            catch( ArangoException $exception )
            {
                $io->text( sprintf( '<error>✗</error> %s — %s' , $name , $exception->getMessage() ) ) ;
                $status = ExitCode::FAILURE ;
            }
        }

        $io->newLine() ;

        return $status ;
    }

    /**
     * Lists the views of the database (name, type, linked collections).
     *
     * @param InputInterface $input
     * @param SymfonyStyle   $io
     * @return int
     */
    private function viewsList( InputInterface $input , SymfonyStyle $io ) :int
    {
        $db = $this->viewsDatabase( $input , $io ) ;
        if( $db === null )
        {
            return ExitCode::FAILURE ;
        }

        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;

        $io->section( sprintf( "List the views of the '%s' database" , $database ) ) ;

        try
        {
            $views = $db->listViews() ;
        }
        catch( ArangoException $exception )
        {
            $io->error( 'Unable to list the views — ArangoDB HTTP API unreachable: ' . $exception->getMessage() ) ;
            return ExitCode::FAILURE ;
        }

        usort( $views , fn( $a , $b ) => strcmp( $a[ ViewField::NAME ] ?? Char::EMPTY , $b[ ViewField::NAME ] ?? Char::EMPTY ) ) ;

        if( $views === [] )
        {
            $io->text( 'There are no views in the database.' ) ;
        }
        else
        {
            foreach( $views as $view )
            {
                $name = $view[ ViewField::NAME ] ?? Char::EMPTY ;
                $type = $view[ ViewField::TYPE ] ?? Char::EMPTY ;

                try
                {
                    $links = array_keys( $db->view( $name )->properties()[ ViewField::LINKS ] ?? [] ) ;
                }
                catch( ArangoException )
                {
                    $links = [] ;
                }

                $io->text( sprintf( '→ %s (%s)%s' , $name , $type , $links !== [] ? ' — ' . implode( ', ' , $links ) : Char::EMPTY ) ) ;
            }
        }

        $io->newLine() ;

        return ExitCode::SUCCESS ;
    }

    /**
     * Walks the configured models and reports (or repairs, in apply mode)
     * the state of their declared views, with the orphan views of the
     * database as a footnote.
     *
     * @param InputInterface $input
     * @param SymfonyStyle   $io
     * @param bool           $apply False → `--diff` (read-only) ; true → `--sync`.
     * @param string|null    $only  Comma-separated view names to restrict the sync to.
     * @return int
     */
    private function viewsReport( InputInterface $input , SymfonyStyle $io , bool $apply , ?string $only ) :int
    {
        $io->section( $apply ? 'Synchronize the declared views' : 'Diff the declared views' ) ;

        if( $this->models === [] )
        {
            $io->warning( 'No models configured — pass their container ids via the `models` init key of the command.' ) ;
            return ExitCode::SUCCESS ;
        }

        // Inspection must not provision : disable the lazy mode of the
        // resolved models through the container kill-switch (LazyTrait).
        $this->container->set( Arango::LAZY , false ) ;

        $filter   = $only !== null ? array_values( array_filter( array_map( trim( ... ) , explode( Char::COMMA , $only ) ) ) ) : null ;
        $declared = [] ;
        $status   = ExitCode::SUCCESS ;

        foreach( $this->models as $id )
        {
            try
            {
                $model = $this->container->get( $id ) ;
            }
            catch( Throwable $exception )
            {
                $io->text( sprintf( '<error>✗</error> %s — %s' , $id , $exception->getMessage() ) ) ;
                $status = ExitCode::FAILURE ;
                continue ;
            }

            if( !( $model instanceof Documents ) )
            {
                $io->text( sprintf( '<comment>·</comment> %s — not a Documents model, skipped' , $id ) ) ;
                continue ;
            }

            $name = $model->getViewName() ;
            if( $name === null )
            {
                $io->text( sprintf( '<comment>·</comment> %s — no View declared' , $id ) ) ;
                continue ;
            }

            $declared[] = $name ;

            if( $filter !== null && !in_array( $name , $filter , true ) )
            {
                continue ;
            }

            $report = $apply ? $model->viewSync() : $model->viewDiff() ;

            $this->viewsRenderReport( $io , $id , $report , $apply ) ;

            if( $report->status === ViewDiffStatus::UNREACHABLE )
            {
                $status = ExitCode::FAILURE ;
            }
        }

        if( $filter === null )
        {
            $this->viewsRenderOrphans( $input , $io , $declared ) ;
        }

        $io->newLine() ;

        return $status ;
    }

    /**
     * Prints the orphan views — on the server but declared by none of the
     * configured models. Report only : orphans are never dropped here, use
     * `--drop` explicitly.
     *
     * @param InputInterface     $input
     * @param SymfonyStyle       $io
     * @param array<int, string> $declared The view names declared by the configured models.
     * @return void
     */
    private function viewsRenderOrphans( InputInterface $input , SymfonyStyle $io , array $declared ) :void
    {
        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;
        $endpoint = $input->getOption( ArangoCommandOption::ENDPOINT ) ?? $this->getEndpoint() ;
        $password = $input->getOption( ArangoCommandOption::PASSWORD ) ?? $this->getPassword() ;
        $username = $input->getOption( ArangoCommandOption::USER     ) ?? $this->getUsername() ;

        $db = $this->buildDatabase( $endpoint , $username , $password , $database ) ;
        if( $db === null )
        {
            return ;
        }

        try
        {
            $names = array_map( fn( $view ) => $view[ ViewField::NAME ] ?? Char::EMPTY , $db->listViews() ) ;
        }
        catch( ArangoException )
        {
            return ;
        }

        $orphans = array_values( array_diff( array_filter( $names ) , $declared ) ) ;
        if( $orphans === [] )
        {
            return ;
        }

        sort( $orphans ) ;

        $io->newLine() ;
        $io->text( sprintf( 'Orphan views (declared by no configured model) : %s' , implode( ', ' , $orphans ) ) ) ;
        $io->text( 'Use `views --drop=name` to remove them explicitly.' ) ;
    }

    /**
     * Prints one model's {@see ViewDiffReport} as a status line plus one
     * indented line per change.
     *
     * @param SymfonyStyle   $io
     * @param string         $id     The container id of the model.
     * @param ViewDiffReport $report The report to render.
     * @param bool           $apply  Whether the report comes from `viewSync()`.
     * @return void
     */
    private function viewsRenderReport( SymfonyStyle $io , string $id , ViewDiffReport $report , bool $apply ) :void
    {
        $label = sprintf( '%s (%s)' , $report->name , $id ) ;

        $line = match( true )
        {
            $report->status === ViewDiffStatus::IN_SYNC                       => sprintf( '<info>✓</info> %s — in sync' , $label ) ,
            $report->status === ViewDiffStatus::MISSING && $report->applied   => sprintf( '<info>✓</info> %s — created' , $label ) ,
            $report->status === ViewDiffStatus::MISSING                       => sprintf( '<error>✗</error> %s — missing on the server' , $label ) ,
            $report->status === ViewDiffStatus::DRIFTED && $report->applied   => sprintf( '<info>✓</info> %s — resynchronized' , $label ) ,
            $report->status === ViewDiffStatus::DRIFTED                       => sprintf( '<comment>~</comment> %s — drifted' , $label ) ,
            $report->status === ViewDiffStatus::INVALID                       => sprintf( '<error>!</error> %s — invalid' , $label ) ,
            default                                                           => sprintf( '<error>!</error> %s — unreachable' , $label ) ,
        } ;

        if( $apply && !$report->applied && in_array( $report->status , [ ViewDiffStatus::MISSING , ViewDiffStatus::DRIFTED ] , true ) )
        {
            $line .= ' (sync failed)' ;
        }

        $io->text( $line ) ;

        foreach( $report->changes as $change )
        {
            $io->text( '    · ' . $change ) ;
        }
    }
}
