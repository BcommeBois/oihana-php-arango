<?php

namespace oihana\arango\db\helpers\fields;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\helpers\aqlFields;
use function oihana\arango\models\helpers\authorizeRelationFields;
use function oihana\core\strings\key;
use function oihana\core\strings\keyValue;

/**
 * Generates an AQL key/value expression for a DOCUMENT-type field.
 *
 * This helper handles nested document fields and can include subfields recursively.
 * - If `$options[Field::FIELDS]` is provided as an array of subfields, it generates
 * a nested `{ ... }` expression using `aqlFields()`.
 * - If no subfields are defined, it falls back to a default field expression using `aqlFieldDefault()`.
 *
 * Example usage:
 * ```php
 * // Simple document field
 * aqlFieldDocument('author', 'doc', ['name' => 'author']);
 * // Produces: "author: doc.author"
 *
 * // Document field with nested subfields
 * aqlFieldDocument( 'author', 'doc',
 * [
 *     Field::NAME   => 'author',
 *     Field::FIELDS =>
 *     [
 *       'firstName' => Filter::DEFAULT ,
 *       'lastName'  => Filter::DEFAULT ,
 *     ]
 * ]);
 * // Produces: "author: { firstName: doc.author.firstName, lastName: doc.author.lastName }"
 * ```
 *
 * @param string $key The key of the field in the parent document.
 * @param string $doc The document variable or reference for the field.
 * @param array $options Field options, typically including:
 * - Field::NAME  => actual key name in the document
 * - Field::FIELDS => array of nested subfields
 * @param ContainerInterface|null $container The optional DI Container reference.
 * @param array $init Optional associative array definition.
 *
 * @return string AQL key/value expression representing the document field.
 *
 * @throws ContainerExceptionInterface
 * @throws NotFoundExceptionInterface
 * @throws UnsupportedOperationException
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function aqlFieldDocument
(
    string              $key ,
    string              $doc ,
    array               $options ,
    ?ContainerInterface $container = null ,
    array               $init      = []
)
: string
{
    $name   = $options[ Field::NAME   ] ?? null;
    $fields = $options[ Field::FIELDS ] ?? null;

    if ( is_array( $fields ) && count( $fields ) > 0 )
    {
        // Definition-level gating: the `LET` walk (the DOCUMENT branch of buildVariables)
        // and this projection walk both read the same normalized definition — the same
        // purge applied on each side keeps them symmetric (the helper is idempotent).
        $fields = authorizeRelationFields
        (
            $fields ,
            $options[ Field::EDGES ] ?? [] ,
            $options[ Field::JOINS ] ?? [] ,
            $init
        ) ;

        return keyValue
        (
            $key ,
            aqlDocument( aqlFields( $fields , key( $name ?? $key , $doc ) , $container , $init ) )
        ) ;
    }

    return aqlFieldDefault( $key , $doc , $name ) ;
}