<?php

namespace oihana\arango\commands\actions;

use ReflectionException;
use Throwable;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\enums\ViewField;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\commands\traits\ArangoMigrationsTrait;
use oihana\arango\commands\traits\ArangoModelsTrait;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\results\DiffReport;
use oihana\arango\enums\Arango;
use oihana\arango\migrations\enums\MigrationKind;
use oihana\arango\migrations\enums\MigrationStatus;
use oihana\arango\migrations\MigrationAction;
use oihana\arango\migrations\MigrationStore;
use oihana\arango\models\Documents;

use oihana\commands\enums\ExitCode;
use oihana\commands\exceptions\ExitException;
use oihana\commands\traits\IOTrait;

use oihana\enums\Char;

// Diagnose / repair the whole declared structure of the database
// $ php bin/console.php command:arangodb doctor                    (report only : collections, indexes, views, orphans)
// $ php bin/console.php command:arangodb doctor --apply            (create what is missing, resync the views)
// $ php bin/console.php command:arangodb doctor --apply --force    (+ drop & recreate the drifted indexes)
// $ php bin/console.php command:arangodb doctor --prune            (interactive removal of the orphans)

/**
 * The structure health check of the ArangoDB database — the global
 * counterpart of the `views` action.
 *
 * For every configured model ({@see ArangoModelsTrait::$models}), the
 * action compares the declared structure with the server state through
 * {@see \oihana\arango\models\traits\DoctorTrait::diagnose()} — the
 * collection (existence, type), the declared `AQL::INDEXES` (the lazy
 * provisioning only creates them with the collection: an index added to an
 * existing model is otherwise never created) and the declared `AQL::VIEW`.
 *
 * Three modes, dry-run first:
 *
 * - default        : report only. The exit code fails as soon as something
 *                    is missing, drifted, invalid or unreachable — `doctor`
 *                    green means the structure matches the declarations
 *                    (CI-friendly). Orphans are reported but never fail.
 * - `--apply`      : repairs through {@see DoctorTrait::repair()} — creates
 *                    the missing collections (with their indexes) and
 *                    indexes, resynchronizes the views. Drifted indexes are
 *                    only announced unless `--force` is added (an index is
 *                    immutable: repairing means drop + recreate, with a
 *                    window where queries lose it).
 * - `--prune`      : offers an interactive selection of the orphans
 *                    (collections and views on the server that no
 *                    configured model declares) to remove — never automatic,
 *                    nothing in non-interactive mode.
 *
 * Like `views --diff`, the walk is side-effect free: the lazy provisioning
 * of the resolved models is disabled through the container `lazy` entry.
 *
 * @package oihana\arango\commands\actions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait ArangoDoctorAction
{
    use ArangoClientTrait ,
        ArangoMigrationsTrait ,
        ArangoModelsTrait ,
        IOTrait ;

    /**
     * Diagnoses (or repairs) the declared structure of the database.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     *
     * @throws ExitException When the interactive prune selection is exited.
     * @throws ReflectionException
     */
    protected function doctor( InputInterface $input , OutputInterface $output ) :int
    {
        $io = $this->getIO( $input , $output ) ;

        $apply = (bool) $input->getOption( ArangoCommandOption::APPLY ) ;
        $force = (bool) $input->getOption( ArangoCommandOption::FORCE ) ;
        $prune = (bool) $input->getOption( ArangoCommandOption::PRUNE ) ;

        $io->section( $apply ? 'Repair the declared structure' : 'Diagnose the declared structure' ) ;

        if( $this->models === [] )
        {
            $io->warning( 'No models configured — pass their container ids via the `models` init key of the command.' ) ;
            return ExitCode::SUCCESS ;
        }

        // Inspection must not provision : disable the lazy mode of the
        // resolved models through the container kill-switch (LazyTrait).
        $this->container->set( Arango::LAZY , false ) ;

        $declaredCollections = [] ;
        $declaredViews       = [] ;
        $counters            = [] ;
        $applied             = [] ;
        $healthy             = true ;

        foreach( $this->models as $id )
        {
            try
            {
                $model = $this->container->get( $id ) ;
            }
            catch( Throwable $exception )
            {
                $io->text( sprintf( '<error>✗</error> %s — %s' , $id , $exception->getMessage() ) ) ;
                $healthy = false ;
                continue ;
            }

            if( !( $model instanceof Documents ) )
            {
                $io->text( sprintf( '<comment>·</comment> %s — not a Documents model, skipped' , $id ) ) ;
                continue ;
            }

            $io->text( sprintf( '<info>%s</info>' , $id ) ) ;

            $reports = $apply ? $model->repair( $force ) : $model->diagnose() ;

            foreach( $reports as $report )
            {
                $this->doctorRenderReport( $io , $report , $apply ) ;

                $counters[ $report->status ] = ( $counters[ $report->status ] ?? 0 ) + 1 ;

                if( !$report->inSync() && !( $apply && $report->applied ) )
                {
                    $healthy = false ;
                }

                if( $apply && $report->applied )
                {
                    $applied[] = $report ;
                }
            }

            if( !empty( $model->collection ) )
            {
                $declaredCollections[] = $model->collection ;
            }
            if( $model->getViewName() !== null )
            {
                $declaredViews[] = $model->getViewName() ;
            }
        }

        $this->doctorJournal( $input , $applied ) ;

        $orphans = $this->doctorOrphans( $input , $io , $declaredCollections , $declaredViews ) ;

        if( $prune && $orphans !== [] )
        {
            $this->doctorPrune( $input , $output , $io , $orphans ) ;
        }

        $io->newLine() ;
        $io->text( sprintf
        (
            '%d model(s) — %d in sync, %d missing, %d drifted, %d invalid, %d unreachable ; %d orphan(s).' ,
            count( $this->models ) ,
            $counters[ DiffStatus::IN_SYNC     ] ?? 0 ,
            $counters[ DiffStatus::MISSING     ] ?? 0 ,
            $counters[ DiffStatus::DRIFTED     ] ?? 0 ,
            $counters[ DiffStatus::INVALID     ] ?? 0 ,
            $counters[ DiffStatus::UNREACHABLE ] ?? 0 ,
            count( $orphans )
        ) ) ;

        $io->newLine() ;

        return $healthy ? ExitCode::SUCCESS : ExitCode::FAILURE ;
    }

    /**
     * Journals each applied object as a `CreateAction` in the tracking
     * collection — the audit trail of `doctor --apply`, told apart from the
     * versioned migrations by its `additionalType` ({@see MigrationKind::DOCTOR}).
     *
     * Best-effort: nothing is journaled when nothing was applied or the
     * database is unreachable, and a journal failure never fails the run.
     * The migration runner ignores these rows.
     *
     * @param InputInterface     $input
     * @param array<int, DiffReport> $applied The reports actually created / repaired.
     *
     * @return void
     */
    private function doctorJournal( InputInterface $input , array $applied ) :void
    {
        if( $applied === [] )
        {
            return ;
        }

        $db = $this->resolveDatabase( $input ) ;
        if( $db === null )
        {
            return ;
        }

        $store     = new MigrationStore( $db , $this->migrationsCollection ) ;
        $agent     = $this->agent() ;
        $gitCommit = $this->gitCommit() ;

        try
        {
            foreach( $applied as $report )
            {
                $action = new MigrationAction() ;
                $action->name           = sprintf( 'doctor: %s %s' , $report->kind , $report->name ) ;
                $action->description    = sprintf( '%s — %s' , $report->status , implode( ' ; ' , $report->changes ) ) ;
                $action->additionalType = MigrationKind::DOCTOR ;
                $action->actionStatus   = MigrationStatus::COMPLETED ;
                $action->agent          = $agent ;
                $action->gitCommit      = $gitCommit ;

                $store->append( $action ) ;
            }
        }
        catch( ArangoException )
        {
            // the audit journal must never fail the doctor run
        }
    }

    /**
     * Computes and prints the orphans — collections (non-system) and views
     * on the server that no configured model declares. Report only.
     *
     * The migrations tracking collection ({@see ArangoMigrationsTrait::$migrationsCollection})
     * is never an orphan: no model declares it, yet both `migrate` and
     * `doctor --apply` write their journal there. It is excluded by its
     * configured name (so a renamed tracking collection is honoured), which
     * also keeps it out of the `--prune` selection.
     *
     * @param InputInterface     $input
     * @param SymfonyStyle       $io
     * @param array<int, string> $declaredCollections The collections declared by the configured models.
     * @param array<int, string> $declaredViews       The view names declared by the configured models.
     *
     * @return array<int, string> The orphan labels (`collection : name` / `view : name`).
     */
    private function doctorOrphans( InputInterface $input , SymfonyStyle $io , array $declaredCollections , array $declaredViews ) :array
    {
        $db = $this->resolveDatabase( $input ) ;
        if( $db === null )
        {
            return [] ;
        }

        $orphans = [] ;

        try
        {
            foreach( $db->collections() as $collection )
            {
                $name = $collection->getName() ;
                if( $name === $this->migrationsCollection && $name !== Char::EMPTY )
                {
                    continue ;
                }
                if( !in_array( $name , $declaredCollections , true ) )
                {
                    $orphans[] = 'collection : ' . $name ;
                }
            }

            foreach( $db->listViews() as $view )
            {
                $name = $view[ ViewField::NAME ] ?? Char::EMPTY ;
                if( $name !== Char::EMPTY && !in_array( $name , $declaredViews , true ) )
                {
                    $orphans[] = 'view : ' . $name ;
                }
            }
        }
        catch( ArangoException )
        {
            return [] ;
        }

        if( $orphans !== [] )
        {
            sort( $orphans ) ;
            $io->newLine() ;
            $io->text( 'Orphans (declared by no configured model) :' ) ;
            foreach( $orphans as $orphan )
            {
                $io->text( '    · ' . $orphan ) ;
            }
            $io->text( 'Use `doctor --prune` (interactive) to remove them explicitly.' ) ;
        }

        return $orphans ;
    }

    /**
     * Offers the interactive multi-selection of the orphans to remove and
     * drops the chosen ones. Nothing happens in non-interactive mode.
     *
     * @param InputInterface     $input
     * @param OutputInterface    $output
     * @param SymfonyStyle       $io
     * @param array<int, string> $orphans The orphan labels ({@see doctorOrphans()}).
     *
     * @return void
     * @throws ExitException When the selection is exited.
     */
    private function doctorPrune( InputInterface $input , OutputInterface $output , SymfonyStyle $io , array $orphans ) :void
    {
        if( !$input->isInteractive() )
        {
            $io->warning( 'The prune selection is interactive only — rerun without --no-interaction.' ) ;
            return ;
        }

        $db = $this->resolveDatabase( $input ) ;
        if( $db === null )
        {
            return ;
        }

        $choices   = [ ...$orphans , $this->exit ] ;
        $question  = new ChoiceQuestion( '🗑️ Please select the orphan(s) to remove (comma-separated) :' , $choices , count( $choices ) - 1 ) ;
        $question->setMultiselect( true ) ;
        $question->setErrorMessage( '⚠️ The orphan %s is invalid.' ) ;

        $selection = (array) $this->getQuestionHelper()->ask( $input , $output , $question ) ;
        if( in_array( $this->exit , $selection , true ) )
        {
            throw new ExitException() ;
        }

        $io->newLine() ;

        foreach( $selection as $orphan )
        {
            [ $kind , $name ] = array_map( trim( ... ) , explode( ':' , $orphan , 2 ) ) ;

            try
            {
                if( $kind === 'view' )
                {
                    $db->view( $name )->drop() ;
                }
                else
                {
                    $db->collection( $name )->drop() ;
                }

                $io->text( sprintf( '<info>✓</info> %s — dropped' , $orphan ) ) ;
            }
            catch( ArangoException $exception )
            {
                $io->text( sprintf( '<error>✗</error> %s — %s' , $orphan , $exception->getMessage() ) ) ;
            }
        }
    }

    /**
     * Prints one {@see DiffReport} as a status line (labelled with its
     * {@see \oihana\arango\db\enums\DiffKind}) plus one indented line per
     * change.
     *
     * @param SymfonyStyle   $io
     * @param DiffReport     $report The report to render.
     * @param bool           $apply  Whether the report comes from `repair()`.
     * @return void
     */
    private function doctorRenderReport( SymfonyStyle $io , DiffReport $report , bool $apply ) :void
    {
        $label = sprintf( '%s [%s]' , $report->name , $report->kind ) ;

        $line = match( true )
        {
            $report->status === DiffStatus::IN_SYNC                     => sprintf( '  <info>✓</info> %s — in sync' , $label ) ,
            $report->status === DiffStatus::MISSING && $report->applied => sprintf( '  <info>✓</info> %s — created' , $label ) ,
            $report->status === DiffStatus::MISSING                     => sprintf( '  <error>✗</error> %s — missing on the server' , $label ) ,
            $report->status === DiffStatus::DRIFTED && $report->applied => sprintf( '  <info>✓</info> %s — repaired' , $label ) ,
            $report->status === DiffStatus::DRIFTED                     => sprintf( '  <comment>~</comment> %s — drifted' , $label ) ,
            $report->status === DiffStatus::INVALID                     => sprintf( '  <error>!</error> %s — invalid' , $label ) ,
            default                                                     => sprintf( '  <error>!</error> %s — unreachable' , $label ) ,
        } ;

        if( $apply && !$report->applied && in_array( $report->status , [ DiffStatus::MISSING , DiffStatus::DRIFTED ] , true ) )
        {
            $line .= ' (not repaired)' ;
        }

        $io->text( $line ) ;

        foreach( $report->changes as $change )
        {
            $io->text( '      · ' . $change ) ;
        }
    }
}
