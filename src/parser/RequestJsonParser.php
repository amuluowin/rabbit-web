<?php

namespace rabbit\web\parser;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use rabbit\helper\JsonHelper;

/**
 * Class RequestJsonParser
 * @package rabbit\web\parser
 */
class RequestJsonParser implements RequestParserInterface
{
    /**
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    public function parse(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request instanceof RequestInterface && strtoupper($request->getMethod()) !== 'GET') {
            $bodyStream = $request->getBody();
            $bodyContent = $bodyStream->getContents();
            try {
                $bodyParams = JsonHelper::decode($bodyContent, true);
            } catch (\Exception $e) {
                $bodyParams = $bodyContent;
            }
            return $request->withBodyParams($bodyParams);
        }

        return $request;
    }
}
