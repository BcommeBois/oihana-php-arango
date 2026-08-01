<?php

namespace tests\oihana\arango\db\helpers\fields;

use Exception;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\fields\aqlFieldDocument;

final class AqlFieldDocumentTest extends TestCase
{
    /**
     * Definition-level gating (`AQL::REQUIRES` on the nested join definition):
     * the denied relation marker is purged from the nested projection — no
     * dangling key referencing a never-emitted `LET`.
     *
     * @throws Exception
     */
    public function testDocumentPurgesMarkersOfDeniedNestedDefinitions(): void
    {
        $options =
        [
            Field::FIELDS =>
            [
                'street' => [ Field::FILTER => Filter::DEFAULT ] ,
                'city'   => [ Field::FILTER => Filter::JOIN , Field::UNIQUE => 'c' ] ,
            ] ,
            Field::JOINS =>
            [
                'city' => [ AQL::MODEL => 'x' , AQL::REQUIRES => 'places:read' ] ,
            ] ,
        ];

        $denied = aqlFieldDocument( 'address' , 'doc' , $options , null , [ Arango::AUTHORIZER => fn() => false ] );

        $this->assertStringContainsString( 'street:doc.address.street' , $denied );
        $this->assertStringNotContainsString( 'city' , $denied );

        $granted = aqlFieldDocument( 'address' , 'doc' , $options , null , [ Arango::AUTHORIZER => fn() => true ] );

        $this->assertStringContainsString( 'city:' , $granted );
    }

    /**
     * @throws Exception
     */
    public function testFieldDocumentWithSubFields(): void
    {
        $options = [
            Field::FIELDS => [
                'street' => [Field::FILTER => Filter::DEFAULT],
                'city' => [Field::FILTER => Filter::DEFAULT],
            ]
        ];

        $result = aqlFieldDocument('address', 'doc', $options);

        // The sub-fields reference doc.address (key is used to build reference)
        $this->assertStringContainsString('address:', $result);
        $this->assertStringContainsString('{', $result);
        $this->assertStringContainsString('street:doc.address.street', $result);
        $this->assertStringContainsString('city:doc.address.city', $result);
        $this->assertStringContainsString('}', $result);
    }

    /**
     * @throws Exception
     */
    public function testFieldDocumentWithCustomFieldName(): void
    {
        $options = [
            Field::NAME => 'location',
            Field::FIELDS => [
                'lat' => [Field::FILTER => Filter::DEFAULT],
                'lng' => [Field::FILTER => Filter::DEFAULT],
            ]
        ];

        $result = aqlFieldDocument('address', 'doc', $options);

        // With NAME = 'location', sub-fields reference doc.location
        $this->assertStringContainsString('address:', $result);
        $this->assertStringContainsString('lat:doc.location.lat', $result);
        $this->assertStringContainsString('lng:doc.location.lng', $result);
    }

    /**
     * @throws Exception
     */
    public function testFieldDocumentFallsBackToFieldDefault(): void
    {
        $options = [];

        $result = aqlFieldDocument('name', 'doc', $options);

        $this->assertEquals('name:doc.name', $result);
    }

    /**
     * @throws Exception
     */
    public function testFieldDocumentWithEmptyFieldsFallsBack(): void
    {
        $options = [
            Field::FIELDS => []
        ];

        $result = aqlFieldDocument('description', 'doc', $options);

        $this->assertEquals('description:doc.description', $result);
    }

    /**
     * Backward compatibility, stated as an assertion : without the guard markers the
     * emitted AQL is the historical one, byte for byte — no ternary, no type test, not
     * one space more.
     *
     * @throws Exception
     */
    public function testUnguardedProjectionIsUnchangedByteForByte(): void
    {
        $result = aqlFieldDocument( 'thing' , 'doc' ,
        [
            Field::FIELDS =>
            [
                '_key' => [] ,
                'name' => [] ,
            ]
        ]);

        $this->assertSame( 'thing:{_key:doc.thing._key, name:doc.thing.name}' , $result ) ;
    }

    /**
     * Field::NULLABLE wraps the rebuilt object — the object itself is untouched, it is
     * only put behind a guard.
     *
     * @throws Exception
     */
    public function testNullableGuardsTheRebuiltObject(): void
    {
        $result = aqlFieldDocument( 'thing' , 'doc' ,
        [
            Field::NULLABLE => true ,
            Field::FIELDS   => [ '_key' => [] , 'name' => [] ] ,
        ]);

        $this->assertSame
        (
            'thing:IS_OBJECT(doc.thing) ? {_key:doc.thing._key, name:doc.thing.name} : null' ,
            $result
        ) ;
    }

    /**
     * The guard tests the ALIASED source (Field::NAME), not the output key — otherwise
     * it would test an attribute that does not exist and mask everything.
     *
     * @throws Exception
     */
    public function testNullableGuardsTheAliasedSource(): void
    {
        $result = aqlFieldDocument( 'address' , 'doc' ,
        [
            Field::NAME     => 'location' ,
            Field::NULLABLE => true ,
            Field::FIELDS   => [ 'lat' => [] ] ,
        ]);

        $this->assertSame( 'address:IS_OBJECT(doc.location) ? {lat:doc.location.lat} : null' , $result ) ;
    }

    /**
     * Field::WHEN on a DOCUMENT is compiled against the PARENT reference : the
     * condition reads `doc.visibility`, never `doc.contact.visibility`. That is what
     * keeps the read gate of conditionReadsDeniedField() correct.
     *
     * @throws Exception
     */
    public function testWhenIsCompiledAgainstTheParentReference(): void
    {
        $result = aqlFieldDocument( 'contact' , 'doc' ,
        [
            Field::WHEN   => [ 'visibility' , 'public' ] ,
            Field::FIELDS => [ 'email' => [] ] ,
        ]);

        $this->assertSame( "contact:doc.visibility == 'public' ? {email:doc.contact.email} : null" , $result ) ;
    }

    /**
     * Both markers on the same field compose with `&&`.
     *
     * @throws Exception
     */
    public function testNullableAndWhenCompose(): void
    {
        $result = aqlFieldDocument( 'contact' , 'doc' ,
        [
            Field::NULLABLE => true ,
            Field::WHEN     => [ 'visibility' , 'public' ] ,
            Field::FIELDS   => [ 'email' => [] ] ,
        ]);

        $this->assertSame
        (
            "contact:(IS_OBJECT(doc.contact) && doc.visibility == 'public') ? {email:doc.contact.email} : null" ,
            $result
        ) ;
    }

    /**
     * Nesting : each level carries its own guard and they do not interfere — the outer
     * ternary never evaluates the inner object when it is false.
     *
     * @throws Exception
     */
    public function testNestedGuardedDocumentsCompose(): void
    {
        $result = aqlFieldDocument( 'thing' , 'doc' ,
        [
            Field::NULLABLE => true ,
            Field::FIELDS   =>
            [
                'name'  => [] ,
                'owner' =>
                [
                    Field::FILTER   => Filter::DOCUMENT ,
                    Field::NULLABLE => true ,
                    Field::FIELDS   => [ 'name' => [] ] ,
                ] ,
            ] ,
        ]);

        $this->assertSame
        (
            'thing:IS_OBJECT(doc.thing) ? {name:doc.thing.name, owner:IS_OBJECT(doc.thing.owner) ? {name:doc.thing.owner.name} : null} : null' ,
            $result
        ) ;
    }

    /**
     * A guarded DOCUMENT carrying a relation : the marker is projected inside the
     * guarded object, referencing the `LET` variable emitted upstream by
     * buildVariables() — which the guard does NOT suppress (a `LET` cannot be
     * conditioned in AQL). The query stays correct; it is not made cheaper.
     *
     * @throws Exception
     */
    public function testGuardedDocumentKeepsItsRelationMarkerInside(): void
    {
        $result = aqlFieldDocument( 'address' , 'doc' ,
        [
            Field::NULLABLE => true ,
            Field::FIELDS   =>
            [
                'street' => [] ,
                'city'   => [ Field::FILTER => Filter::JOIN , Field::UNIQUE => 'city_j1' ] ,
            ] ,
            Field::JOINS => [ 'city' => [ AQL::MODEL => 'x' ] ] ,
        ]);

        $this->assertStringStartsWith( 'address:IS_OBJECT(doc.address) ? {' , $result ) ;
        $this->assertStringContainsString( 'city_j1' , $result ) ;
        $this->assertStringEndsWith( ' : null' , $result ) ;
    }

    /**
     * No sub-field whitelist : the sub-document is embedded as-is. An explicit
     * Field::NULLABLE still applies rather than being a silent no-op — it then reads
     * « this key only holds a real object ».
     *
     * @throws Exception
     */
    public function testGuardAppliesToTheNoFieldsFallback(): void
    {
        $this->assertSame
        (
            'thing:IS_OBJECT(doc.thing) ? doc.thing : null' ,
            aqlFieldDocument( 'thing' , 'doc' , [ Field::NULLABLE => true ] )
        ) ;

        $this->assertSame
        (
            "thing:doc.active == true ? doc.thing : null" ,
            aqlFieldDocument( 'thing' , 'doc' , [ Field::WHEN => [ 'active' , true ] ] )
        ) ;
    }
}
