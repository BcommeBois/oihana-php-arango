<?php

namespace oihana\arango\controllers\traits\documents;

use Exception;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\traits\PayloadsTrait;
use oihana\arango\enums\Arango;
use oihana\arango\controllers\enums\ModelOperation;

use oihana\controllers\traits\ModelCallTrait;
use oihana\controllers\traits\prepare\PrepareLang;
use oihana\controllers\traits\prepare\PrepareSkin;
use oihana\controllers\traits\StatusTrait;
use oihana\controllers\traits\ValidatorTrait;

use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;

use oihana\models\traits\ModelTrait;

use org\schema\constants\Schema;


/**
 * Provides the functionality to update or replace documents in an ArangoDB collection
 * using PATCH or PUT HTTP methods. This trait handles:
 *   - Document existence verification.
 *   - Preparation and filtering of document data.
 *   - Validation against defined rules.
 *   - Conditional update (PATCH) or replacement (PUT) of documents.
 *   - Hooks for pre- and post-update actions (`beforeUpdate` and `afterUpdate`).
 *
 * Usage:
 * - PATCH ../collection/{id} -> Partial update
 * - PUT   ../collection/{id} -> Full replacement
 */
trait DocumentsControllerUpdateTrait
{
    use ModelCallTrait ,
        ModelTrait ,
        PayloadsTrait ,
        PrepareLang ,
        PrepareSkin ,
        ReloadWrittenDocumentTrait ,
        StatusTrait ,
        ValidatorTrait ;

    /**
     * Provides the functionality to update or replace documents in an ArangoDB collection
     * using PATCH or PUT HTTP methods. This trait handles:
     * - Document existence verification.
     * - Preparation and filtering of document data.
     * - Validation against defined rules.
     * - Conditional update (PATCH) or replacement (PUT) of documents.
     * - Hooks for pre- and post-update actions (`beforeUpdate` and `afterUpdate`).
     *
     * Usage:
     * - PATCH ../collection/{id} -> Partial update
     * - PUT   ../collection/{id} -> Full replacement
     *
     * @param ?Request $request Optional PSR-7 ServerRequest instance.
     * @param ?Response $response Optional PSR-7 Response instance.
     * @param array $args Route parameters (e.g., ['id' => '_key']).
     * @param array $init Initialization options and additional context.
     *
     * @return mixed The updated document data on success, or a standardized error response on failure.
     */
    public function update
    (
        ?Request  $request  = null ,
        ?Response $response = null ,
         array    $args     = []   ,
         array    $init     = []
    )
    :mixed
    {
        try
        {
            $value = $args[ Schema::ID ] ?? null ;

            // The route args are posed once, up-front, so the existence probe sees them too - the same way delete() does.
            $init = [ ...$init , Arango::ARGS => $args ] ;

            // The probe gets its OWN hooked init, like PropertyController::patch() —
            // not the write's, which does not exist yet: the payload is assembled
            // below and a hook reading it must still see it. Ran unhooked, the probe
            // let an out-of-scope document through and the scoped write then matched
            // nothing, ending on the same 404 through the null guard further down —
            // so the answer never differed, but a write query ran for a document the
            // caller may not touch, and the two sibling verbs were wired opposite
            // ways for no reason.
            $existInit = [ ...$init , Arango::VALUE => $value , Arango::OPERATION => ModelOperation::EXIST ] ;

            $this->beforeModelCall( $request , $existInit ) ;

            if( !$this->model->exist( $existInit ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : sprintf( 'The document "%s" does not exist' , ( $value ?? 'undefined' ) )
                ) ;
            }

            $relations = [] ;
            $payload   = null ;
            $failure   = null ;
            $method    = $request?->getMethod() ;

            if ( !$this->prepareWritePayload( $request , $response , $method , $init , $relations , $payload , $failure ) )
            {
                return $failure ;
            }

            // The write gets its own named init, like the probe above — `$init` stays
            // the caller's, pristine. It used to be reassigned and then handed to the
            // hook BY REFERENCE, so it came out carrying the consumer predicate; the
            // reload below, seeded from it and hooked in turn, then posed that
            // predicate twice.
            // The operation is named after the model call, which is the distinction the
            // HTTP verb cannot make from a single call site — and it is posed after the
            // spread, so a caller init carrying one of its own cannot survive into it.
            $writeInit =
            [
                ...$init ,
                Arango::DOC       => $payload ,
                Arango::OPERATION => $method == HttpMethod::PATCH ? ModelOperation::UPDATE : ModelOperation::REPLACE ,
                Arango::RELATIONS => $relations ,
                Arango::VALUE     => $value ,
            ] ;

            $this->beforeModelCall( $request , $writeInit ) ;

            $document = $method == HttpMethod::PATCH
                      ? $this->model->update  ( $writeInit )   // PATCH -> update
                      : $this->model->replace ( $writeInit ) ; // PUT   -> replace

            $this->afterModelCall( $request , $writeInit , $document ) ;

            // `UPDATE`/`REPLACE … RETURN NEW` yields a row only when its FILTER
            // matched, so a null document means the target was gone by the time the
            // write ran — deleted between the existence probe above and this line.
            // Same fact the probe reports, hence the same status and the same
            // wording. Without this guard the reload below dereferences null and
            // raises an `Error`, which `catch( Exception )` does not intercept; in
            // raw mode nothing would crash at all and the response would claim
            // success for a write that touched nothing.
            if( $document === null )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : sprintf( 'The document "%s" does not exist' , ( $value ?? 'undefined' ) )
                ) ;
            }

            $raw = (bool) ( $init[ Arango::RAW ] ?? false ) ;

            return $this->success
            (
                $request ,
                $response ,
                $raw ? $document : $this->reload( $request , $args , $init , $document , strtolower( $method ) )
            );
        }
        catch( Exception $e )
        {
            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : HttpStatusCode::fromException( $e ) ,
                details  : $e->getMessage()
            ) ;
        }
    }
}