<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/10/9
 * Time: 13:34
 */

namespace rabbit\web;


use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

class Request implements ServerRequestInterface
{
    use MessageTrait;
    /**
     * @var \Swoole\Http\Request
     */
    protected $swooleRequest;

    /**
     * @var array
     */
    private $attributes = [];

    /**
     * @var array
     */
    private $cookieParams = [];

    /**
     * @var null|array|object
     */
    private $parsedBody;

    /**
     * @var array
     */
    private $queryParams = [];

    /**
     * @var array
     */
    private $serverParams = [];

    /**
     * @var array
     */
    private $uploadedFiles = [];

    /**
     * @var string
     */
    private $method;

    /**
     * @var UriInterface|Uri
     */
    private $uri;
    
    
    private $requestTarget;

    public function __construct(\Swoole\Http\Request $swooleRequest)
    {
        $server = $swooleRequest->server;
        $this->method = strtoupper($server['request_method'] ?? 'GET');
        $this->setHeaders($swooleRequest->header ?? []);
        $this->uri = self::getUriFromGlobals($swooleRequest);
        $body = new SwooleStream($swooleRequest->rawContent());
        $this->protocol = isset($server['server_protocol']) ? str_replace('HTTP/', '', $server['server_protocol']) : '1.1';

        $this->stream = $body;

        $this->withCookieParams($swooleRequest->cookie ?? [])
            ->withQueryParams($swooleRequest->get ?? [])
            ->withParsedBody($swooleRequest->post ?? [])
            ->withUploadedFiles(self::normalizeFiles($swooleRequest->files ?? []))
            ->withServerParams($server ?? [])
            ->setSwooleRequest($swooleRequest);
    }

    private static function normalizeFiles(array $files)
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
            } elseif (is_array($value) && isset($value['tmp_name'])) {
                $normalized[$key] = self::createUploadedFileFromSpec($value);
            } elseif (is_array($value)) {
                $normalized[$key] = self::normalizeFiles($value);
                continue;
            } else {
                throw new \InvalidArgumentException('Invalid value in files specification');
            }
        }

        return $normalized;
    }

    private static function createUploadedFileFromSpec(array $value)
    {
        if (is_array($value['tmp_name'])) {
            return self::normalizeNestedFileSpec($value);
        }

        return new UploadedFile($value['tmp_name'], (int)$value['size'], (int)$value['error'], $value['name'], $value['type']);
    }

    private static function normalizeNestedFileSpec(array $files = [])
    {
        $normalizedFiles = [];

        foreach (array_keys($files['tmp_name']) as $key) {
            $spec = [
                'tmp_name' => $files['tmp_name'][$key],
                'size' => $files['size'][$key],
                'error' => $files['error'][$key],
                'name' => $files['name'][$key],
                'type' => $files['type'][$key],
            ];
            $normalizedFiles[$key] = self::createUploadedFileFromSpec($spec);
        }

        return $normalizedFiles;
    }

    /**
     * Get a Uri populated with values from $swooleRequest->server.
     * @param \Swoole\Http\Request $swooleRequest
     * @return \Psr\Http\Message\UriInterface
     * @throws \InvalidArgumentException
     */
    private static function getUriFromGlobals(\Swoole\Http\Request $swooleRequest)
    {
        $server = $swooleRequest->server;
        $header = $swooleRequest->header;
        $uri = new Uri();
        $uri = $uri->withScheme(!empty($server['https']) && $server['https'] !== 'off' ? 'https' : 'http');

        $hasPort = false;
        if (isset($server['http_host'])) {
            $hostHeaderParts = explode(':', $server['http_host']);
            $uri = $uri->withHost($hostHeaderParts[0]);
            if (isset($hostHeaderParts[1])) {
                $hasPort = true;
                $uri = $uri->withPort($hostHeaderParts[1]);
            }
        } elseif (isset($server['server_name'])) {
            $uri = $uri->withHost($server['server_name']);
        } elseif (isset($server['server_addr'])) {
            $uri = $uri->withHost($server['server_addr']);
        } elseif (isset($header['host'])) {
            if (\strpos($header['host'], ':')) {
                $hasPort = true;
                list($host, $port) = explode(':', $header['host'], 2);

                if ($port !== '80') {
                    $uri = $uri->withPort($port);
                }
            } else {
                $host = $header['host'];
            }

            $uri = $uri->withHost($host);
        }

        if (!$hasPort && isset($server['server_port'])) {
            $uri = $uri->withPort($server['server_port']);
        }

        $hasQuery = false;
        if (isset($server['request_uri'])) {
            $requestUriParts = explode('?', $server['request_uri']);
            $uri = $uri->withPath($requestUriParts[0]);
            if (isset($requestUriParts[1])) {
                $hasQuery = true;
                $uri = $uri->withQuery($requestUriParts[1]);
            }
        }

        if (!$hasQuery && isset($server['query_string'])) {
            $uri = $uri->withQuery($server['query_string']);
        }

        return $uri;
    }

    public function getServerParams()
    {
        return $this->serverParams;
    }

    public function getCookieParams()
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies)
    {
        $clone = $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    public function getQueryParams()
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query)
    {
        $clone = $this;
        $clone->queryParams = $query;
        return $clone;
    }

    public function getUploadedFiles()
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles)
    {
        $clone = $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data)
    {
        $clone = $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    public function getAttributes()
    {
        return $this->attributes;
    }

    public function getAttribute($name, $default = null)
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute($name, $value)
    {
        $clone = $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function withoutAttribute($name)
    {
        if (false === array_key_exists($name, $this->attributes)) {
            return $this;
        }

        $clone = $this;
        unset($clone->attributes[$name]);

        return $clone;
    }

    public function getRequestTarget()
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ($target == '') {
            $target = '/';
        }
        if ($this->uri->getQuery() != '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    public function withRequestTarget($requestTarget)
    {
        if (preg_match('#\s#', $requestTarget)) {
            throw new \InvalidArgumentException('Invalid request target provided; cannot contain whitespace');
        }

        $clone = $this;
        $clone->requestTarget = $requestTarget;
        return $clone;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function withMethod($method)
    {
        $method = strtoupper($method);
        $methods = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'HEAD'];
        if (!in_array($method, $methods)) {
            throw new \InvalidArgumentException('Invalid Method');
        }
        $clone = $this;
        $clone->method = $method;
        return $clone;
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false)
    {
        if ($uri === $this->uri) {
            return $this;
        }

        $clone = $this;
        $clone->uri = $uri;

        if (!$preserveHost) {
            $clone->updateHostFromUri();
        }

        return $clone;
    }

    /**
     * Return an instance with the specified server params.
     *
     * @param array $serverParams
     * @return static
     */
    public function withServerParams(array $serverParams)
    {
        $clone = $this;
        $clone->serverParams = $serverParams;
        return $clone;
    }

    private function setHeaders(array $headers)
    {
        $this->headers = [];
        foreach ($headers as $header => $value) {
            $normalized = strtolower($header);
            $this->headers[$normalized] = $value;
        }
        return $this;
    }

    private function updateHostFromUri()
    {
        $host = $this->uri->getHost();

        if ($host === '') {
            return;
        }

        if (($port = $this->uri->getPort()) !== null) {
            $host .= ':' . $port;
        }

        if ($this->hasHeader('host')) {
            $header = $this->getHeaderLine('host');
        } else {
            $header = 'Host';
        }
        // Ensure Host is the first header.
        $this->headers = [$header => [$host]] + $this->headers;
    }

    /**
     * @return \Swoole\Http\Request
     */
    public function getSwooleRequest(): \Swoole\Http\Request
    {
        return $this->swooleRequest;
    }

    /**
     * @param \Swoole\Http\Request $swooleRequest
     * @return $this
     */
    public function setSwooleRequest(\Swoole\Http\Request $swooleRequest)
    {
        $this->swooleRequest = $swooleRequest;
        return $this;
    }
}