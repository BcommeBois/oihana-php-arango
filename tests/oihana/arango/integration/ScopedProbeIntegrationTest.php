<?php

namespace tests\oihana\arango\integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use DI\Container;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the **scoped existence probe** — the one guarantee the unit
 * suite cannot give, because it hinges on what the server does with the query
 * rather than on what the controller puts in the model `$init`.
 *
 * Every controller that gates a write behind `exist()` — `PropertyController::patch()`,
 * the six array operations, and the vertex probes of `EdgesController` — hands the
 * model an `Arango::CONDITIONS` predicate plus the `Arango::BINDS` it references.
 * The doubles used by the unit tests record that init and stop there: they never
 * assemble the AQL, so a bind dropped between the init and the query is invisible
 * to them. Only a real arangod refuses the query.
 *
 * ```
 * FILTER doc._key IN [@q_1] && doc.__scope == @__scope   ← the condition arrives
 * binds : { "q_1": "k1", "@collection": "…" }             ← its bind does not
 * ```
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ScopedProbeIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_scoped_probe_it' ;

    private const string DOCUMENTS = 'documents' ;

    /**
     * Two documents that differ only by the attribute the scope reads.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::DOCUMENTS )->create() ;

        $db->collection( self::DOCUMENTS )->insert( [ '_key' => 'visible' , '__scope' => 'visible' ] ) ;
        $db->collection( self::DOCUMENTS )->insert( [ '_key' => 'hidden'  , '__scope' => 'hidden'  ] ) ;
    }

    /**
     * The probe a scoped controller runs: the document exists **and** satisfies
     * the consumer predicate, so it must answer `true` — not raise.
     *
     * @throws ArangoException
     */
    public function testAScopedProbeAnswersForADocumentInsideTheScope() :void
    {
        $this->assertTrue( $this->model()->exist( $this->scopedInit( 'visible' ) ) ) ;
    }

    /**
     * The other half : the scope must actually bite. A `true` here would mean the
     * predicate reached the query and matched anyway — a scope that does nothing.
     *
     * @throws ArangoException
     */
    public function testAScopedProbeRefusesADocumentOutsideTheScope() :void
    {
        $this->assertFalse( $this->model()->exist( $this->scopedInit( 'hidden' ) ) ) ;
    }

    /**
     * Non-regression : a probe without a scope keeps answering on the key alone.
     *
     * @throws ArangoException
     */
    public function testAnUnscopedProbeIsUnchanged() :void
    {
        $model = $this->model() ;

        $this->assertTrue ( $model->exist( [ Arango::VALUE => 'hidden'  ] ) ) ;
        $this->assertFalse( $model->exist( [ Arango::VALUE => 'unknown' ] ) ) ;
    }

    /**
     * A live `Documents` model wired to the disposable database.
     */
    private function model() :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::DOCUMENTS ,
            AQL::LAZY        => false ,
        ]) ;
    }

    /**
     * The init a `beforeModelCall()` hook builds: the key being probed, the
     * consumer predicate, and the bind that predicate references.
     *
     * @return array<string,mixed>
     */
    private function scopedInit( string $key ) :array
    {
        return
        [
            Arango::VALUE      => $key ,
            Arango::CONDITIONS => [ 'doc.__scope == @__scope' ] ,
            Arango::BINDS      => [ '__scope' => 'visible' ] ,
        ] ;
    }
}
