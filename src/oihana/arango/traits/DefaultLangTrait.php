<?php

namespace oihana\arango\traits;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\assertLanguageCode;

/**
 * Carries the **default language** — the locale a multilingual expression falls
 * back to when the requested one is absent, or when no language is requested at
 * all.
 *
 * ⚠ Not to be confused with `Arango::LANG`, the language **requested** by the
 * current call (`?lang=`). The two travel side by side in the same `$init` and
 * mean opposite things: `Arango::LANG` is an *instruction*, and it wins over
 * everything; `DEFAULT_LANG` is a *default*, and a default never overrides an
 * explicit declaration. That is why they cannot share a name.
 *
 * The trait is used at two levels, which is what makes it a trait rather than a
 * property: a **model** declares the fallback its own multilingual fields use,
 * and a **controller** reads the site-wide one from the configuration and pushes
 * it into the model call. When both answer, the model wins — a site default must
 * not silently override what a model states about itself, or the model would
 * change behaviour depending on which host loads it.
 *
 * @example
 * ```php
 * // In a model or a controller constructor:
 * $this->initializeDefaultLang( $init , $container ) ;
 *
 * // Declared explicitly:
 * new ProductModel( $container , [ Arango::DEFAULT_LANG => 'en' ] ) ;
 *
 * // Or site-wide, through the container entry of the same name:
 * 'defaultLang' => 'fr' ,
 * ```
 *
 * @package oihana\arango\traits
 * @author  Marc Alcaraz
 * @since   1.0.0
 */
trait DefaultLangTrait
{
    /**
     * The 'defaultLang' parameter.
     */
    public const string DEFAULT_LANG = 'defaultLang' ;

    /**
     * The default (fallback) language code, or null when none is declared.
     */
    public ?string $defaultLang = null ;

    /**
     * Initialize the default language, from the `$init` definition or, failing
     * that, from the container entry of the same name.
     *
     * The tag is lowercased so a single spelling reaches the query builders, and
     * validated on the spot: it is interpolated verbatim into AQL (an attribute
     * name can never be bound), and a declaration nobody can fix from a request
     * must fail loudly rather than quietly sort on nothing.
     *
     * @param array $init Optional definition carrying a `defaultLang` entry.
     * @param ContainerInterface|null $container Optional PSR-11 container for a site-wide fallback.
     *
     * @return static
     *
     * @throws ContainerExceptionInterface If the container fails while retrieving the entry.
     * @throws NotFoundExceptionInterface  If the container has no such entry.
     * @throws ValidationException         If the declared value is not a valid language code.
     */
    public function initializeDefaultLang( array $init = [] , ?ContainerInterface $container = null ) :static
    {
        $lang = $init[ self::DEFAULT_LANG ] ?? $this->defaultLang ;

        if( $lang === null && $container?->has( self::DEFAULT_LANG ) )
        {
            $lang = $container->get( self::DEFAULT_LANG ) ;
        }

        if( $lang === null )
        {
            $this->defaultLang = null ;
            return $this ;
        }

        if( is_string( $lang ) )
        {
            $lang = strtolower( $lang ) ;
        }

        assertLanguageCode( $lang ) ;

        $this->defaultLang = $lang ;

        return $this ;
    }
}
