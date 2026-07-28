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
use oihana\arango\enums\Field;
use oihana\arango\models\Documents;
use oihana\arango\search\FederatedSearch;
use oihana\arango\search\enums\FederatedSearchParam;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation that the **hydration** stage of a federated search projects
 * under the same gates as a direct read.
 *
 * `FederatedSearch::rebuild()` re-reads every matched key through its owning
 * model. It receives the request authorizer — it uses it to decide which
 * collections are searchable and to route a polymorphic key — but did not forward
 * it to the `list()` that rebuilds the documents. The search was gated, the
 * projection was not, so a `Field::REQUIRES` field came out through the search
 * that a `get()` on the same document hides.
 *
 * The stage is exercised directly rather than through `search()`: `rebuild()` is
 * public and needs no ArangoSearch View, so the test targets the leak instead of
 * the plumbing around it.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class FederatedRebuildGateIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_federated_gate_it' ;

    private const string DOCUMENTS = 'documents' ;

    /**
     * The permission the `secret` field requires.
     */
    private const string PERMISSION = 'docs.secret:read' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::DOCUMENTS )->create() ;

        $db->collection( self::DOCUMENTS )->insert( [ '_key' => 'k1' , 'name' => 'public' , 'secret' => 'CLASSIFIED' ] ) ;
    }

    /**
     * The control: read directly through the model, the gate has always held.
     */
    public function testTheGateHoldsOnADirectRead() :void
    {
        $document = $this->model()->get( [ Arango::VALUE => 'k1' , Arango::AUTHORIZER => $this->refusing() ] ) ;

        $this->assertObjectNotHasProperty( 'secret' , $document ) ;
    }

    /**
     * The leak: the same document, rebuilt by the federated engine for the same
     * caller, used to carry the field the read refuses.
     */
    public function testTheGateHoldsOnAFederatedRebuild() :void
    {
        $document = $this->rebuiltDocument( [ Arango::AUTHORIZER => $this->refusing() ] ) ;

        $this->assertObjectNotHasProperty( 'secret' , $document , 'the federated hydration projected a field the read hides' ) ;
    }

    /**
     * Closing the gate must not close everything.
     */
    public function testAnUngatedFieldStillComesBack() :void
    {
        $this->assertSame( 'public' , $this->rebuiltDocument( [ Arango::AUTHORIZER => $this->refusing() ] )->name ?? null ) ;
    }

    /**
     * No authorization stack, no gate: the projection falls open, as everywhere
     * else in the library.
     */
    public function testWithoutAnAuthorizerTheProjectionFallsOpen() :void
    {
        $this->assertSame( 'CLASSIFIED' , $this->rebuiltDocument( [] )->secret ?? null ) ;
    }

    /**
     * An authorizer that **grants** the permission gets the field — proving the
     * forwarded closure is really consulted, not merely present.
     */
    public function testAGrantingAuthorizerStillSeesTheField() :void
    {
        $granting = static fn( string $subject ) :bool => $subject === self::PERMISSION ;

        $this->assertSame( 'CLASSIFIED' , $this->rebuiltDocument( [ Arango::AUTHORIZER => $granting ] )->secret ?? null ) ;
    }

    // ---- harness ----------------------------------------------------------

    /**
     * A live model whose `secret` field requires a permission.
     */
    private function model() :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $model = new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::DOCUMENTS ,
            AQL::LAZY        => false ,
        ]) ;

        // `_key` is declared on purpose: the rebuild indexes hydrated documents by
        // it, and a projection that drops it yields rows the engine cannot place.
        $model->fields = [ '_key' => [] , 'name' => [] , 'secret' => [ Field::REQUIRES => self::PERMISSION ] ] ;

        return $model ;
    }

    /**
     * An authorizer refusing every permission — a caller holding none.
     */
    private function refusing() :callable
    {
        return static fn( string $subject ) :bool => false ;
    }

    /**
     * Runs the rebuild stage over a single match and returns the hydrated document.
     *
     * @param array<string,mixed> $init The request options handed to the engine.
     */
    private function rebuiltDocument( array $init ) :?object
    {
        $model     = $this->model() ;
        $container = new Container() ;

        $container->set( LoggerInterface::class , new NullLogger() ) ;
        $container->set( 'model.documents'      , $model ) ;

        $engine = new FederatedSearch( $container ,
        [
            FederatedSearchParam::MODELS => [ self::DOCUMENTS => 'model.documents' ] ,
        ]) ;

        $results = $engine->rebuild( [ [ Arango::COLLECTION => self::DOCUMENTS , Arango::KEY => 'k1' ] ] , $init ) ;

        return $results[ 0 ][ FederatedSearch::DOCUMENT ] ?? null ;
    }
}
