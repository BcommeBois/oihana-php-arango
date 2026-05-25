<?php

namespace oihana\arango\commands\enums\traits;

use oihana\commands\enums\traits\CommandParamTrait;
use oihana\reflect\traits\ConstantsTrait;

/**
 * The trait to defines the constants of the DocumentsCommandParam class.
 *
 * @package oihana\robots\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
trait DocumentsCommandParamTrait
{
    use ConstantsTrait,
        CommandParamTrait ;

    /**
     * The 'doc' parameter.
     */
    public const string DOC = 'doc' ;

    /**
     * The 'documents' parameter.
     */
    public const string DOCUMENTS = 'documents' ;

    /**
     * The 'excludes' parameter.
     */
    public const string EXCLUDES = 'excludes' ;

    /**
     * The 'fields' parameter.
     */
    public const string FIELDS = 'fields' ;

    /**
     * The 'key' parameter.
     */
    public const string KEY = 'key' ;

    /**
     * The 'keys' parameter.
     */
    public const string KEYS = 'keys' ;

    /**
     * The 'list' parameter.
     */
    public const string LIST = 'list' ;

    /**
     * The 'redirects' parameter.
     */
    public const string REDIRECTS = 'redirects' ;

    /**
     * The 'removeKeys' parameter.
     */
    public const string REMOVE_KEYS = 'removeKeys' ;

    /**
     * The 'skin' parameter.
     */
    public const string SKIN = 'skin' ;

    /**
     * The 'skinDefault' parameter.
     */
    public const string SKIN_DEFAULT = 'skinDefault' ;

    /**
     * The 'skinMethods' parameter.
     */
    public const string SKIN_METHODS = 'skinMethods' ;

    /**
     * The 'skins' parameter.
     */
    public const string SKINS = 'skins' ;

    /**
     * The 'upsertOptions' parameter.
     */
    public const string UPSERT_OPTIONS = 'upsertOptions' ;

    /**
     * The 'value' parameter.
     */
    public const string VALUE = 'value' ;
}