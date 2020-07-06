<?php
declare(strict_types=1);

namespace Rabbit\Web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use rabbit\core\Context;
use rabbit\core\ObjectFactory;

/**
 * Class RequestHandler
 * @package rabbit\server
 */
class RequestHandler implements RequestHandlerInterface
{
    /**
     * @var array
     */
    private $middlewares;
    /**
     * @var int
     */
    private $offset = 0;

    /**
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws \Exception
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (empty($this->middlewares[$this->offset])) {
            return Context::get('response');
        } else {
            $handler = $this->middlewares[$this->offset];
        }
        \is_string($handler) && $handler = ObjectFactory::get($handler);

        if (!$handler instanceof MiddlewareInterface) {
            throw new \InvalidArgumentException('Invalid Handler. It must be an instance of MiddlewareInterface');
        }
        $this->offset++;
        return $handler->process($request, $this);
    }
}
