<?php

namespace tests\oihana\arango\db\helpers\fields;

use Exception;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\exceptions\UnsupportedOperationException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\fields\aqlFieldWrap;

final class AqlFieldWrapTest extends TestCase
{
    /**
     * Definition-level gating (`AQL::REQUIRES` on the nested sub-edge
     * definition): the denied relation marker is purged from the wrapped
     * projection — no dangling key referencing a never-emitted `LET`.
     *
     * @throws Exception
     */
    public function testWrapPurgesMarkersOfDeniedNestedDefinitions(): void
    {
        $options =
        [
            Field::FIELDS =>
            [
                'id'       => [ Field::FILTER => Filter::DEFAULT ] ,
                'worksFor' => [ Field::FILTER => Filter::EDGE , Field::UNIQUE => 'wf' ] ,
            ] ,
            Field::EDGES =>
            [
                'worksFor' => [ AQL::MODEL => 'x' , AQL::REQUIRES => 'org.read' ] ,
            ] ,
        ];

        $denied = aqlFieldWrap( 'subject' , 'v' , $options , null , [ Arango::AUTHORIZER => fn() => false ] );

        $this->assertStringContainsString( 'id:v.id' , $denied );
        $this->assertStringNotContainsString( 'worksFor' , $denied );

        $granted = aqlFieldWrap( 'subject' , 'v' , $options , null , [ Arango::AUTHORIZER => fn() => true ] );

        $this->assertStringContainsString( 'worksFor:' , $granted );
    }

    /**
     * The sub-fields are projected against the reference itself (`v.id`),
     * not a sub-attribute (`v.subject.id`), and wrapped under the key.
     *
     * @throws Exception
     */
    public function testWrapWithSubFields(): void
    {
        $options =
        [
            Field::FIELDS =>
            [
                'id'        => [ Field::FILTER => Filter::DEFAULT ] ,
                'givenName' => [ Field::FILTER => Filter::DEFAULT ] ,
            ]
        ];

        $result = aqlFieldWrap( 'subject' , 'v' , $options );

        $this->assertStringContainsString( 'subject:' , $result );
        $this->assertStringContainsString( '{' , $result );
        $this->assertStringContainsString( 'id:v.id' , $result );
        $this->assertStringContainsString( 'givenName:v.givenName' , $result );
        $this->assertStringContainsString( '}' , $result );
        $this->assertStringNotContainsString( 'v.subject' , $result );
    }

    /**
     * The reference is wrapped from an edge variable just as well as a vertex.
     *
     * @throws Exception
     */
    public function testWrapAgainstAnEdgeReference(): void
    {
        $options =
        [
            Field::FIELDS =>
            [
                'role' => [ Field::FILTER => Filter::DEFAULT ] ,
            ]
        ];

        $result = aqlFieldWrap( 'link' , 'e' , $options );

        $this->assertStringContainsString( 'link:' , $result );
        $this->assertStringContainsString( 'role:e.role' , $result );
    }

    /**
     * `Field::RAW => true` embeds the whole reference as-is, with no projection.
     *
     * @throws Exception
     */
    public function testWrapRawEmbedsTheWholeReference(): void
    {
        $result = aqlFieldWrap( 'subject' , 'v' , [ Field::RAW => true ] );

        $this->assertSame( 'subject:v' , $result );
    }

    /**
     * `Field::RAW` takes effect only when no field whitelist is provided ;
     * a whitelist always wins.
     *
     * @throws Exception
     */
    public function testFieldsWinOverRaw(): void
    {
        $options =
        [
            Field::RAW    => true ,
            Field::FIELDS =>
            [
                'id' => [ Field::FILTER => Filter::DEFAULT ] ,
            ]
        ];

        $result = aqlFieldWrap( 'subject' , 'v' , $options );

        $this->assertStringContainsString( '{' , $result );
        $this->assertStringContainsString( 'id:v.id' , $result );
    }

    /**
     * Without a field whitelist and without the explicit `Field::RAW` opt-in,
     * the projection is rejected — embedding the whole reference must be
     * deliberate.
     *
     * @throws Exception
     */
    public function testMissingFieldsWithoutRawThrows(): void
    {
        $this->expectException( UnsupportedOperationException::class );
        aqlFieldWrap( 'subject' , 'v' , [] );
    }

    /**
     * An empty field whitelist is treated as "no whitelist" : without the
     * `Field::RAW` opt-in it is rejected too.
     *
     * @throws Exception
     */
    public function testEmptyFieldsWithoutRawThrows(): void
    {
        $this->expectException( UnsupportedOperationException::class );
        aqlFieldWrap( 'subject' , 'v' , [ Field::FIELDS => [] ] );
    }

    /**
     * `Field::RAW => true` embeds the whole reference verbatim — there is no
     * projected object to nest a sub-edge into, so combining it with
     * `Field::EDGES` is a contradiction and rejected explicitly.
     *
     * @throws Exception
     */
    public function testRawCombinedWithEdgesThrows(): void
    {
        $this->expectException( UnsupportedOperationException::class );

        aqlFieldWrap( 'subject' , 'v' ,
        [
            Field::RAW   => true ,
            Field::EDGES =>
            [
                'worksFor' => [ Field::FILTER => Filter::EDGE ] ,
            ] ,
        ] );
    }
}
