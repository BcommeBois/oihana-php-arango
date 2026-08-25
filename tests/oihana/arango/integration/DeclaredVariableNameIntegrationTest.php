<?php

namespace tests\oihana\arango\integration;

use Throwable;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live validation of what a **declared** `Field::UNIQUE` may be.
 *
 * The unit suite can prove that a declared name is honoured and that an
 * ill-formed one is refused. It cannot prove the thing that actually matters:
 * that the name ArangoDB receives is one it accepts as a `LET` identifier. The
 * two grammars are not the same, and the library learned that the hard way —
 * the first guard written for this option was {@see assertAttributeName()},
 * which validates a *path* and therefore waves `address.city` through, a name
 * the server answers with a syntax error.
 *
 * These cases run the three shapes against a real server, so the boundary is
 * measured rather than assumed:
 *
 * - a well-formed identifier is accepted;
 * - a **path** is a syntax error — which is why the shape guard refuses it
 *   before the query is ever built;
 * - an **AQL keyword** and a name **already bound** (`doc`) are refused too,
 *   loudly, and deliberately left to the server: both have the shape of an
 *   identifier, so no shape guard can see them, and copying ArangoDB's keyword
 *   list into the library would only be a copy to keep in sync.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class DeclaredVariableNameIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_declared_variable_name_it' ;

    private const string COLLECTION = 'articles' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $articles = $db->collection( self::COLLECTION ) ;
        $articles->create() ;
        $articles->insert( [ '_key' => 'a1' , 'title' => 'Alpha' ] ) ;
        $articles->insert( [ '_key' => 'a2' , 'title' => 'Beta'  ] ) ;
    }

    /**
     * Runs a query shaped exactly like the one a projected relation produces —
     * a `LET` bound to the declared name, read back in the `RETURN`.
     *
     * @return string|null The server's refusal, or null when the query ran.
     */
    private function refusalFor( string $variable ) :?string
    {
        $query = 'FOR doc IN ' . self::COLLECTION
               . ' LET ' . $variable . ' = ( RETURN doc.title )'
               . ' RETURN { title: FIRST(' . $variable . ') }' ;

        try
        {
            iterator_to_array( self::$db->query( $query ) , false ) ;
            return null ;
        }
        catch ( Throwable $exception )
        {
            return $exception->getMessage() ;
        }
    }

    /**
     * The ordinary case: a well-formed identifier is a working `LET`.
     */
    public function testAWellFormedNameIsAcceptedByTheServer() :void
    {
        $this->assertNull( $this->refusalFor( 'authorRef' ) ) ;
        $this->assertNull( $this->refusalFor( '_ref'      ) ) ;
        $this->assertNull( $this->refusalFor( 'ref2'      ) ) ;
    }

    /**
     * 🔑 The case that exposed the wrong guard. A dotted path is a valid
     * *attribute* name, so the attribute guard accepted it — and the server
     * answers a syntax error. The shape guard now refuses it before the query
     * exists; this case pins **why** it must.
     */
    public function testAPathIsASyntaxErrorAsAVariable() :void
    {
        $refusal = $this->refusalFor( 'address.city' ) ;

        $this->assertNotNull( $refusal , 'A dotted name must not compile.' ) ;
        $this->assertStringContainsString( 'syntax error' , $refusal ) ;
    }

    /**
     * An AQL keyword has the shape of an identifier, so no shape guard can see
     * it. The server does, and says so.
     */
    public function testAnAqlKeywordIsRefusedByTheServer() :void
    {
        foreach ( [ 'LET' , 'RETURN' , 'FILTER' ] as $keyword )
        {
            $refusal = $this->refusalFor( $keyword ) ;

            $this->assertNotNull( $refusal , 'The keyword "' . $keyword . '" must not compile.' ) ;
            $this->assertStringContainsString( 'syntax error' , $refusal ) ;
        }
    }

    /**
     * The nastiest of the three, because `doc` is a plausible thing to write:
     * the name is well-formed, and it collides with the document variable the
     * query already binds. ArangoDB names the variable in its refusal, so the
     * declaration that caused it is identifiable at once.
     */
    public function testANameAlreadyBoundCollidesLoudly() :void
    {
        $refusal = $this->refusalFor( 'doc' ) ;

        $this->assertNotNull( $refusal , 'A name already bound must not compile.' ) ;
        $this->assertStringContainsString( 'doc' , $refusal ) ;
        $this->assertStringContainsString( 'assigned multiple times' , $refusal ) ;
    }

    /**
     * And the same collision between two `LET`s — the shape the duplicate guard
     * closes in the library, measured here to show what it prevents.
     */
    public function testTwoLetsSharingOneNameCollideLoudly() :void
    {
        $query = 'FOR doc IN ' . self::COLLECTION
               . ' LET ref = ( RETURN doc.title ) LET ref = ( RETURN doc._key )'
               . ' RETURN ref' ;

        try
        {
            iterator_to_array( self::$db->query( $query ) , false ) ;
            $this->fail( 'Two LETs sharing a name must not compile.' ) ;
        }
        catch ( Throwable $exception )
        {
            $this->assertStringContainsString( 'assigned multiple times' , $exception->getMessage() ) ;
        }
    }
}
