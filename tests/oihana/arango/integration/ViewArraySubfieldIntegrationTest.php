<?php

namespace tests\oihana\arango\integration;

use DI\Container;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Search;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of array-of-objects sub-field search: a `Search::FIELDS`
 * path carrying the `[*]` expansion marker (e.g. `contactPoints[*].email`) is
 * indexed by ArangoSearch (Community) and matched by `?search=` whenever a
 * token appears in **any** element of the array — a non-correlated search that
 * needs no Enterprise `nested` flag.
 *
 * It also proves the stability of the diff: after the View is provisioned,
 * {@see Documents::viewDiff()} reports `IN_SYNC`, a {@see Documents::viewSync()}
 * is a no-op, and a second `viewDiff()` is still `IN_SYNC` — the stripped link
 * shape never drifts against what the server stores.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ViewArraySubfieldIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_viewarray_it' ;

    private const string COLLECTION = 'orgs' ;

    /**
     * Seeds three documents carrying both a flat array of objects
     * (`contactPoints`) and a two-level nested array of objects
     * (`employees[*].contactPoints[*]`). Each searchable token is distinctive,
     * so a match pins down exactly one document.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $orgs = $db->collection( self::COLLECTION ) ;
        $orgs->create() ;

        $orgs->insert(
        [
            'label'         => 'A' ,
            'name'          => 'Alpha Corp' ,
            'contactPoints' =>
            [
                [ 'email' => 'alpha@acme.test'  , 'type' => 'billing'  ] ,
                [ 'email' => 'ceo@gamma.test'   , 'type' => 'personal' ] ,
            ] ,
            'employees' =>
            [
                [ 'contactPoints' => [ [ 'email' => 'deepalice@nested.test' ] ] ] ,
            ] ,
        ] ) ;

        $orgs->insert(
        [
            'label'         => 'B' ,
            'name'          => 'Bravo Ltd' ,
            'contactPoints' =>
            [
                [ 'email' => 'bravo@beta.test' , 'type' => 'work' ] ,
            ] ,
            'employees' =>
            [
                [ 'contactPoints' => [ [ 'email' => 'deepbob@nested.test' ] ] ] ,
            ] ,
        ] ) ;

        $orgs->insert(
        [
            'label'         => 'C' ,
            'name'          => 'Charlie SA' ,
            'contactPoints' =>
            [
                [ 'email' => 'charlie@delta.test' , 'type' => 'system' ] ,
            ] ,
            'employees' => [] ,
        ] ) ;
    }

    /**
     * A Documents model wired to the disposable database with a given
     * `AQL::VIEW` declaration. Lazy mode is ON, so the construction provisions
     * the declared View.
     *
     * @throws Throwable
     */
    private function model( array $view ) :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => self::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::VIEW        => $view ,
        ]) ;
    }

    /**
     * Polls the View until it exposes the expected document count (eventual consistency).
     *
     * @throws ArangoException When the count is still wrong after ~15 seconds.
     */
    private function waitForIndexing( int $expected , string $view ) :void
    {
        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $rows = iterator_to_array
            (
                self::$db->query( 'FOR d IN ' . $view . ' COLLECT WITH COUNT INTO total RETURN total' ) ,
                false
            ) ;

            if ( ( $rows[0] ?? 0 ) === $expected )
            {
                return ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        throw new ArangoException( 'The view never reached ' . $expected . ' indexed documents.' ) ;
    }

    /**
     * @param array<int,array|object> $rows
     * @return array<int,string>
     */
    private function labels( array $rows ) :array
    {
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;
        sort( $labels ) ;
        return $labels ;
    }

    /**
     * A token living inside one element of a flat array of objects
     * (`contactPoints[*].email`) matches the document — non-correlated.
     *
     * @throws Throwable
     */
    public function testSearchMatchesAFlatArraySubfield() :void
    {
        $view =
        [
            Search::NAME     => 'orgsArrayView' ,
            Search::ANALYZER => 'text_en' ,
            Search::FIELDS   => [ 'contactPoints[*].email' => 1 ] ,
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'orgsArrayView' ) , 'The array-subfield View must be provisioned.' ) ;

        $this->waitForIndexing( 3 , 'orgsArrayView' ) ;

        // `alpha` lives only in A's first contactPoint email.
        $this->assertSame( [ 'A' ] , $this->labels( $model->list( [ Arango::SEARCH => 'alpha' ] ) ) ) ;

        // `ceo` lives only in A's second contactPoint email — any element matches.
        $this->assertSame( [ 'A' ] , $this->labels( $model->list( [ Arango::SEARCH => 'ceo' ] ) ) ) ;

        // `bravo` lives only in B.
        $this->assertSame( [ 'B' ] , $this->labels( $model->list( [ Arango::SEARCH => 'bravo' ] ) ) ) ;

        // A token present in no element matches nothing.
        $this->assertSame( [] , $this->labels( $model->list( [ Arango::SEARCH => 'zzznotoken' ] ) ) ) ;
    }

    /**
     * A token living inside a two-level nested array of objects
     * (`employees[*].contactPoints[*].email`) matches the document.
     *
     * @throws Throwable
     */
    public function testSearchMatchesAMultiLevelArraySubfield() :void
    {
        $view =
        [
            Search::NAME     => 'orgsDeepView' ,
            Search::ANALYZER => 'text_en' ,
            Search::FIELDS   => [ 'employees[*].contactPoints[*].email' => 1 ] ,
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'orgsDeepView' ) , 'The multi-level array-subfield View must be provisioned.' ) ;

        // C carries no nested email (`employees` is empty), so it is not indexed
        // by this field-specific link : only A and B reach the View.
        $this->waitForIndexing( 2 , 'orgsDeepView' ) ;

        // `deepalice` is buried in A.employees[*].contactPoints[*].email.
        $this->assertSame( [ 'A' ] , $this->labels( $model->list( [ Arango::SEARCH => 'deepalice' ] ) ) ) ;

        // `deepbob` is buried in B.
        $this->assertSame( [ 'B' ] , $this->labels( $model->list( [ Arango::SEARCH => 'deepbob' ] ) ) ) ;
    }

    /**
     * The stripped link shape never drifts: once the View is provisioned,
     * `viewDiff()` is IN_SYNC, `viewSync()` is a no-op, and a second
     * `viewDiff()` is still IN_SYNC — proving the false drift the `[*]` markers
     * could have introduced does not reappear.
     *
     * @throws Throwable
     */
    public function testStrippedLinkDoesNotDrift() :void
    {
        $view =
        [
            Search::NAME     => 'orgsDiffView' ,
            Search::ANALYZER => 'text_en' ,
            Search::FIELDS   =>
            [
                'name'                                  => 1 ,
                'contactPoints[*].email'                => 1 ,
                'employees[*].contactPoints[*].email'   => 1 ,
            ] ,
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'orgsDiffView' ) , 'The View must be provisioned.' ) ;

        $first = $model->viewDiff() ;
        $this->assertSame
        (
            DiffStatus::IN_SYNC ,
            $first->status ,
            'Right after creation the declared (stripped) link must match the server : ' . implode( ' | ' , $first->changes )
        ) ;

        $sync = $model->viewSync() ;
        $this->assertSame( DiffStatus::IN_SYNC , $sync->status , 'viewSync() on an in-sync View is a no-op.' ) ;

        $second = $model->viewDiff() ;
        $this->assertSame
        (
            DiffStatus::IN_SYNC ,
            $second->status ,
            'The diff must stay IN_SYNC on a re-run — no false drift reappears : ' . implode( ' | ' , $second->changes )
        ) ;
    }
}
