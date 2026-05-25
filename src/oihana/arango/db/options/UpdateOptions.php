<?php

namespace oihana\arango\db\options;

class UpdateOptions extends ReplaceOptions
{
    /**
     * When updating an attribute to the null value, ArangoDB does not remove the attribute from the document but stores this null value.
     * To remove attributes in an update operation, set them to null and set the keepNull option to false.
     * Only top-level attributes and sub-attributes can be removed this way (e.g. { attr: { sub: null } })
     * but not attributes of objects that are nested inside of arrays (e.g. { attr: [ { nested: null } ] }).
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/update/#keepnull
     */
    public bool $keepNull ;

    /**
     * The option mergeObjects controls whether object contents are merged
     * if an object attribute is present in both the UPDATE query and in the to-be-updated document.
     * @var bool
     * @see https://docs.arangodb.com/stable/aql/high-level-operations/update/#mergeobjects
     */
    public bool $mergeObjects ;
}