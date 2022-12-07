<?php
namespace Pyncer\Auth;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequestInterface;
use Pyncer\Auth\AbstractAuthenticator;
use Pyncer\Data\Model\ModelInterface;
use Pyncer\Http\Message\Response;
use Pyncer\Http\Message\Status;
use Pyncer\Http\Server\RequestHandlerInterface;

use function count;
use function explode;
use function implode;
use function is_array;
use function Pyncer\nullify as pyncer_nullify;

abstract class AbstractBearerAuthenticator extends AbstractAuthenticator
{
    protected array $scopes = [];

    public function __construct(
        PsrServerRequestInterface $request,
        string $realm,
    ) {
        parent::__construct($request, 'Bearer', $realm);
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function hasScopes(string ...$scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!in_array($this->getScopes(), $scope)) {
                return false;
            }
        }

        return true;
    }

    public function getResponse(
        RequestHandlerInterface $handler
    ): ?PsrResponseInterface
    {
        $header = $this->request->getHeader('Authorization');

        if (!$header) {
            return null;
        }

        $token = explode(' ', $header[0], 2);

        if (count($token) !== 2 || $token[0] !== $this->getScheme()) {
            return $this->getChallengeResponse(
                Status::CLIENT_ERROR_400_BAD_REQUEST
            );
        }

        if (!$this->authenticate($token[1])) {
            return $this->getChallengeResponse(
                Status::CLIENT_ERROR_401_UNAUTHORIZED
            );
        }

        $this->hasAuthenticated = true;

        return null;
    }

    protected abstract function authenticate(string $token): bool;

    public function getChallengeResponse(
        Status $status,
        array $params = [],
    ): PsrResponseInterface
    {
        $description = $params['description'] ?? null;
        $scopes = null;

        if ($status === Status::CLIENT_ERROR_400_BAD_REQUEST) {
            $description ??= 'The authorization header is invalid';
            $error = 'invalid_request';
        } elseif ($status === Status::CLIENT_ERROR_403_FORBIDDEN) {
            $description ??= 'The authorization token has insufficient scope';

            $scopes = pyncer_nullify($scopes);
            if ($scopes === null) {
                $scopes = 'access';
            }

            if (is_array($scopes)) {
                $scopes = implode(' ', $scopes);
            }

            $error = 'insufficient_scope';
        } else { // 401
            $description ??= 'The auththorization token is expired, revoked, or invalid';
            $error = 'invalid_token';
        }

        return new Response(
            status: $status,
            headers: [
                'WWW-Authenticate' =>
                    'Bearer ' .
                    'realm="' . $this->getRealm() . '", ' .
                    ($scopes ? 'scope="' . $scopes . '", ' : '') .
                    'error="' . $error . '", ' .
                    'error_description="' . $description . '"'
            ]
        );
    }
}