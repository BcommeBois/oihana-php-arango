<?php

namespace oihana\arango\controllers\traits\documents;

use Casbin\Exceptions\CasbinException;
use oihana\exceptions\http\Error403;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\arango\controllers\enums\ModelOperation;

use oihana\controllers\traits\ModelCallTrait;
use oihana\controllers\traits\prepare\PrepareLang;
use oihana\controllers\traits\prepare\PrepareSkin;

use oihana\models\traits\ModelTrait;

/**
 * Re-reads the document a write has just produced, so the response carries what
 * the database holds rather than what the caller sent — computed values, `modified`,
 * i18n and defaults included.
 *
 * **It is a read, and it is treated as one.** Both write handlers used to build
 * this call by hand, from four keys, outside the {@see ModelCallTrait} hooks: the
 * request authorizer never reached it, so
 * {@see \oihana\arango\models\helpers\isAuthorized()} fell open and a field hidden
 * by `Field::REQUIRES` came straight back in the write response — the very value the
 * matching `GET` refuses. Stating the call once, here, is what keeps the two
 * responses of the same controller in agreement.
 *
 * `Arango::RAW` never reaches this method: it returns the write's own result and
 * skips the reload entirely, so **no projection applies to it**. That opt-out is
 * declared in the route, never by a client, and it is incompatible with a
 * projection gate.
 *
 * @package oihana\arango\controllers\traits\documents
 * @author  Marc Alcaraz
 */
trait ReloadWrittenDocumentTrait
{
    use ModelCallTrait ,
        ModelTrait ,
        PrepareLang ,
        PrepareSkin ;

    /**
     * Re-reads a written document through the lifecycle hooks.
     *
     * @param Request|null $request The current PSR-7 request.
     * @param array $args The route placeholders, forwarded as `Arango::ARGS`.
     * @param array $init The caller init the lang / skin are read from.
     * @param object $document The document the write returned.
     * @param string $method The HTTP method, for the per-verb skin default.
     *
     * @return mixed The reloaded document, after {@see ModelCallTrait::afterModelCall()}.
     *
     * @throws CasbinException
     * @throws Error403
     */
    protected function reload( ?Request $request , array $args , array $init , object $document , string $method ) :mixed
    {
        $modelInit =
        [
            // A `get` in every respect — a hook scoping reads must reach this one too,
            // so it is not given an operation of its own. The flag is what tells the
            // two apart for a hook that needs to.
            Arango::OPERATION   => ModelOperation::GET ,
            Arango::AFTER_WRITE => true ,
            Arango::ARGS        => $args ,
            Arango::VALUE       => $document->_key ,
            Arango::CONDITIONS  => $init[ Arango::CONDITIONS ] ?? [] ,
            Arango::LANG        => $this->prepareLang( $request , $init ) ,
            Arango::SKIN        => $this->prepareSkin( $request , $init , method: $method ) ,
        ] ;

        $this->beforeModelCall( $request , $modelInit ) ;

        $reloaded = $this->model->get( $modelInit ) ;

        $this->afterModelCall( $request , $modelInit , $reloaded ) ;

        return $reloaded ;
    }
}
