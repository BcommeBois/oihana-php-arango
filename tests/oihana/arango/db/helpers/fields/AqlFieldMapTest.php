<?php

namespace tests\oihana\arango\db\helpers\fields;

use Exception;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\reflect\exceptions\ConstantException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use function oihana\arango\db\helpers\fields\aqlFieldMap;

/**
 * Test class for FieldMap trait.
 *
 * Tests the generation of AQL array mapping expressions with sub-documents.
 */
final class AqlFieldMapTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ConstantException
     */
    public function testFieldMapWithEmptyFields(): void
    {
        $options = [
            Field::FIELDS => []
        ];

        $result = aqlFieldMap('addresses', 'doc', $options);

        $this->assertEquals('addresses:[]', $result);
    }

    /**
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testFieldMapWithNoFields(): void
    {
        $options = [];

        $result = aqlFieldMap('items', 'doc', $options);

        $this->assertEquals('items:[]', $result);
    }

    /**
     * Test fieldMap with simple fields generates FOR loop.
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testFieldMapWithSimpleFields(): void
    {
        $options =
        [
            Field::FIELDS =>
            [
                'street' => [ Field::FILTER => Filter::DEFAULT ],
                'city'   => [ Field::FILTER => Filter::DEFAULT ],
            ]
        ];

        $result = aqlFieldMap('addresses', 'doc', $options);

        $this->assertStringContainsString('addresses:', $result);

        // 2. Vérifie la structure de la boucle FOR avec la protection IS_ARRAY et la variable dynamique
        // Regex : FOR item_[chiffres] IN (IS_ARRAY(doc.addresses) ? doc.addresses : [])
        $this->assertMatchesRegularExpression(
            '/FOR item_[0-9]+ IN \(IS_ARRAY\(doc\.addresses\) \? doc\.addresses : \[\]\)/',
            $result,
            'La boucle FOR sécurisée est incorrecte.'
        );

        // 3. Vérifie le RETURN
        $this->assertStringContainsString('RETURN {', $result);

        // 4. Vérifie les champs mappés avec la variable dynamique
        // Regex : street:item_[chiffres].street
        $this->assertMatchesRegularExpression('/street:item_[0-9]+\.street/', $result);
        $this->assertMatchesRegularExpression('/city:item_[0-9]+\.city/', $result);
    }

    /**
     * Test fieldMap with custom field name.
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testFieldMapWithCustomFieldName(): void
    {
        $options =
        [
            Field::NAME   => 'customAddresses',
            Field::FIELDS =>
            [
                'name' => [ Field::FILTER => Filter::DEFAULT ] ,
            ]
        ];

        $result = aqlFieldMap('addresses', 'doc', $options);

        // Vérifie que le FOR pointe bien vers customAddresses avec la protection IS_ARRAY
        $this->assertMatchesRegularExpression(
            '/FOR item_[0-9]+ IN \(IS_ARRAY\(doc\.customAddresses\) \? doc\.customAddresses : \[]\)/',
            $result
        );

        // Vérifie le mapping du champ
        $this->assertMatchesRegularExpression('/name:item_[0-9]+\.name/', $result);
    }
}
