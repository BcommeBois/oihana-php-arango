<?php

namespace oihana\arango\models\traits;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Hydration;

/**
 * Gives a model the choice of **how** a document it reads is turned into its
 * schema object — see {@see Hydration} for the two modes and what separates them.
 *
 * The mode is declared once, per model, through the `AQL::HYDRATION` option, and
 * defaults to the constructor : a model that says nothing behaves exactly as
 * before.
 *
 * ```php
 * new Documents( $container ,
 * [
 *     AQL::COLLECTION => 'albums' ,
 *     AQL::SCHEMA     => Album::class ,
 *     AQL::HYDRATION  => Hydration::REFLECTION , // nested structures come back typed
 * ]) ;
 * ```
 *
 * @package oihana\arango\models\traits
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
trait HydrationTrait
{
    /**
     * How a document read by this model is turned into its schema object — one of
     * the {@see Hydration} modes, defaulting to {@see Hydration::CONSTRUCTOR},
     * the historical behaviour.
     *
     * 🚨 It lives **here, on the model**, and is handed to the database façade at
     * every read. It must never become a state of that façade : a single instance
     * of it is shared by every model, so a mode set on it would silently apply to
     * whichever model reads next — and models do read inside one another (a
     * document being served resolves its relations through other models).
     *
     * @var string
     */
    public string $hydration = Hydration::CONSTRUCTOR ;

    /**
     * Initializes the hydration mode from the `AQL::HYDRATION` option.
     *
     * Anything other than the exact {@see Hydration::REFLECTION} value — an
     * absent option, a null, a typo — resolves to the constructor. A misspelt
     * mode therefore keeps the historical behaviour instead of silently opting
     * into the stricter one, and never breaks the container build.
     *
     * @param array $init The model options (key: {@see AQL::HYDRATION}).
     *
     * @return static The current instance, for fluent chaining.
     */
    public function initializeHydration( array $init = [] ) :static
    {
        $value = $init[ AQL::HYDRATION ] ?? null ;

        $this->hydration = $value === Hydration::REFLECTION ? Hydration::REFLECTION : Hydration::CONSTRUCTOR ;

        return $this ;
    }
}
