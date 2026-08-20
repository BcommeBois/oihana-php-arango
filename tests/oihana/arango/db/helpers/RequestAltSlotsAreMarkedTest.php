<?php

namespace tests\oihana\arango\db\helpers;

use PHPUnit\Framework\TestCase;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use function oihana\arango\db\helpers\requestAlt;

/**
 * A guard over the source itself, not over one behaviour.
 *
 * An `alt` chain arriving with a request must be marked by {@see requestAlt()} at
 * the point where it is **read**, because that is the only place its origin is
 * still known. Every behavioural test proves one reading point works today; none
 * of them can notice the day someone adds a seventh and forgets to mark it — the
 * chain would simply be interpolated again, in silence, exactly as before.
 *
 * So this test walks `src/` instead, finds every read of a request `alt` slot, and
 * requires each one to be either marked on the spot or listed below with a reason.
 * A new occurrence fails the test until a human has said which it is.
 */
final class RequestAltSlotsAreMarkedTest extends TestCase
{
    /**
     * Files reading a request `alt` slot **without** marking it, with the reason and
     * the exact number of occurrences expected. The count is part of the contract:
     * adding one more to an exempt file fails too, so an exemption never becomes a
     * blanket pass for the whole file.
     *
     * @var array<string,array{count:int,reason:string}>
     */
    private const array EXEMPT =
    [
        'models/traits/aql/GroupTrait.php' =>
        [
            'count'  => 1 ,
            'reason' => 'the slot holds a map of chains keyed by dimension, not one chain; each is marked at use' ,
        ] ,
        'models/traits/aql/filters/HasHierarchicalFilter.php' =>
        [
            'count'  => 1 ,
            'reason' => 'relays the raw slot into a nested filter init, whose own read marks it' ,
        ] ,
        'db/helpers/fields/buildWhenLeaf.php' =>
        [
            'count'  => 1 ,
            'reason' => 'a Field::WHEN / Field::WHERE leaf is a model declaration, never a request' ,
        ] ,
        'controllers/traits/inject/InjectFilterTrait.php' =>
        [
            'count'  => 2 ,
            'reason' => 'writes the slot and forwards the host own filter; the eventual read marks it' ,
        ] ,
    ];

    /**
     * Matches a read of a request `alt` slot: `$something[ FilterParam::ALT ]` or
     * `$something[ Group::ALT ]`, whatever the spacing.
     */
    private const string SLOT = '/\$\w+\[\s*(?:FilterParam::ALT|Group::ALT)\s*]/' ;

    public function testEveryRequestAltSlotIsMarkedOrExempt() :void
    {
        $unmarked = [] ;

        foreach ( $this->slotOccurrences() as $file => $lines )
        {
            $bare = array_filter( $lines , fn( array $l ) => !str_contains( $l[ 'code' ] , 'requestAlt(' ) ) ;

            if ( $bare === [] )
            {
                continue ;
            }

            $expected = self::EXEMPT[ $file ][ 'count' ] ?? 0 ;

            if ( count( $bare ) !== $expected )
            {
                foreach ( $bare as $l )
                {
                    $unmarked[] = sprintf( '%s:%d  %s' , $file , $l[ 'line' ] , $l[ 'code' ] ) ;
                }
            }
        }

        $this->assertSame( [] , $unmarked , implode( "\n" , array_merge
        ([
            'A request `alt` slot is read without requestAlt(). Either mark it there —' ,
            'so its parameters are bound instead of written into the query — or add the' ,
            'file to self::EXEMPT with the reason and the new occurrence count.' ,
            '' ,
        ] , $unmarked ) ) ) ;
    }

    /**
     * An exemption that no longer matches anything is a stale claim: it would keep
     * granting a pass to a file that has since been rewritten.
     */
    public function testNoExemptionIsStale() :void
    {
        $occurrences = $this->slotOccurrences() ;

        foreach ( self::EXEMPT as $file => $entry )
        {
            $this->assertArrayHasKey( $file , $occurrences , "Stale exemption: $file reads no request alt slot any more." ) ;

            $bare = array_filter( $occurrences[ $file ] , fn( array $l ) => !str_contains( $l[ 'code' ] , 'requestAlt(' ) ) ;

            $this->assertCount
            (
                $entry[ 'count' ] ,
                $bare ,
                sprintf( 'Exemption for %s expects %d unmarked read(s) — %s' , $file , $entry[ 'count' ] , $entry[ 'reason' ] )
            ) ;
        }
    }

    /**
     * Every unmarked read of `src/`, keyed by path relative to `src/oihana/arango/`.
     * Comment lines are skipped: a docblock naming the slot is prose, not a read.
     *
     * @return array<string,array<int,array{line:int,code:string}>>
     */
    private function slotOccurrences() :array
    {
        $root  = dirname( __DIR__ , 5 ) . '/src/oihana/arango' ;
        $found = [] ;

        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) ;

        foreach ( $files as $file )
        {
            if ( $file->getExtension() !== 'php' )
            {
                continue ;
            }

            $relative = str_replace( $root . '/' , '' , $file->getPathname() ) ;

            foreach ( file( $file->getPathname() ) as $index => $line )
            {
                $code = trim( $line ) ;

                if ( $code === '' || str_starts_with( $code , '*' ) || str_starts_with( $code , '//' ) )
                {
                    continue ;
                }

                if ( preg_match( self::SLOT , $code ) === 1 )
                {
                    $found[ $relative ][] = [ 'line' => $index + 1 , 'code' => $code ] ;
                }
            }
        }

        ksort( $found ) ;
        return $found ;
    }
}
