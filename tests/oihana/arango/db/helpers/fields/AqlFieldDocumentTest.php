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
}
