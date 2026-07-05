<?php

namespace oihana\arango\helpers\conditions ;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;

use org\schema\constants\Schema;

/**
 * Filter documents by additionalType schema.
 *
 * Accepts a string, an AQL expression, or an array of values.
 *
 * @param string|array $schemaType The schema type(s) to filter by
 * @param string       $docRef     The AQL doc reference (default: doc)
 *
 * @return array An array containing a single AQL condition expression
 *
 * @throws UnsupportedOperationException
 *
 * @example
 * ```php
 * isAdditionalType('Person');
 * // → doc.additionalType == 'Person'
 *
 * isAdditionalType(['Person','Organization']);
 * // → doc.additionalType IN ['Person','Organization']
 *
 * isAdditionalType('@types');
 * // → doc.additionalType == @types
 * ```
 *
 * @author  Marc Alcaraz (eKameleon)
 * @package oihana\arango\models
 * @version 1.5.0
 */
function isAdditionalType( array|string $schemaType , string $docRef = AQL::DOC ): array
{
    return isProperty( Schema::ADDITIONAL_TYPE , $schemaType , $docRef ) ;
}