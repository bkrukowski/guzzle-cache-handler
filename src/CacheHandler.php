<?php

namespace Concat\Http\Handler;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\ApcCache;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use RuntimeException;

/**
 * Guzzle handler used to cache responses using Doctrine\Common\Cache.
 */
class CacheHandler
{

    /**
     * @var \Doctrine\Common\Cache\CacheProvider Cache provider.
     */
    protected $cache;

    /**
     * @var callable Default handler used to send response.
     */
    protected $handler;

    /**
     * @var \Psr\Log\LoggerInterface PSR-3 compliant logger.
     */
    protected $logger;

    /**
     * @var string|callable Constant or callable that accepts a Response.
     */
    protected $logLevel;

    /**
     * @var array Configuration options.
     */
    protected $options;

    /**
     * Constructs a new cache handler.
     *
     * @param CacheProvider $cache Cache provider.
     * @param callable $handler Default handler used to send response.
     * @param array $options Configuration options.
     */
    public function __construct(
        CacheProvider $cache = null,
        callable $handler = null,
        array $options = []
    ) {
        $this->cache   = $cache   ?: $this->getDefaultCacheProvider();
        $this->handler = $handler ?: $this->getDefaultHandler();

        $this->setOptions($options);
    }

    /**
     * Sets the fallback handler to use when the cache is invalid.
     *
     * @param callable $handler
     *
     * @codeCoverageIgnore
     */
    public function setHandler(callable $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Sets the cache provider.
     *
     * @param CacheProvider $cache
     *
     * @codeCoverageIgnore
     */
    public function setCacheProvider(CacheProvider $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Resets the options, merged with default values.
     *
     * @param array $options
     */
    public function setOptions(array $options)
    {
        $this->options = array_merge($this->getDefaultOptions(), $options);
    }

    /**
     * Sets the logger.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Returns the default cache provider, used if a cache provider is not set.
     *
     * @return ApcCache
     *
     * @codeCoverageIgnore
     */
    protected function getDefaultCacheProvider()
    {
        return new ApcCache();
    }

    /**
     * Returns the default handler, used if a handler is not set.
     *
     * @return callable
     * @codeCoverageIgnore
     */
    protected function getDefaultHandler()
    {
        return \GuzzleHttp\choose_handler();
    }

    /**
     * Returns the default confiration options.
     *
     * @return array The default configuration options.
     */
    protected function getDefaultOptions()
    {
        return [

            // HTTP methods that should be cached
            'methods' => ['GET', 'HEAD', 'OPTIONS'],

            // Time in seconds to cache the response for
            'expire'  => 30,

            // Accepts a request and returns true if it should be cached
            'filter'  => null,
        ];
    }

    /**
     * Called when a request is made on the client.
     *
     * @return PromiseInterface
     */
    public function __invoke(Request $request, array $options)
    {
        if ($this->shouldCacheRequest($request)) {
            return $this->cache($request, $options);
        }

        return $this->invokeDefault($request, $options);
    }

    /**
     * Attempts to fetch, otherwise promises to cache a response when the
     * default handler fulfills its promise.
     *
     * @param Request $request The request to cache.
     * @param array $options Configuration options.
     *
     * @return PromiseInterface
     */
    protected function cache(Request $request, array $options)
    {
        $key = $this->getKey($request, $options);

        if ($this->cache->contains($key)) {

            // Return the cached response if fetch was successful.
            if ($response = $this->fetch($request, $key)) {
                return new FulfilledPromise($response);
            }
        }

        // Make the request using the default handler.
        $promise = $this->invokeDefault($request, $options);

        // Don't store if the expire time isn't positive.
        if ($this->options['expire'] <= 0) {
            return $promise;
        }

        // Promise to store the response once the default promise is fulfilled.
        return $promise->then(function ($response) use ($request, $key) {
            $this->store($request, $response, $key);
        });
    }

    /**
     * Attempts to fetch a response bundle from the cache for the given key.
     *
     * @param Request $request
     * @param string $key
     *
     * @return Response|null A response null if invalid.
     */
    protected function fetch(Request $request, $key)
    {
        $bundle = $this->fetchBundle($key);

        if ($bundle) {
            $this->logFetchedBundle($request, $bundle);
            return $bundle['response'];
        }
    }

    /**
     * Fetches a response bundle from the cache for a given key.
     *
     * @param string $key The key to fetch.
     *
     * @return array|null Bundle from cache or null if expired.
     */
    protected function fetchBundle($key)
    {
        $bundle = $this->cache->fetch($key);

        if ($bundle === false) {
            throw new RuntimeException("Failed to fetch response from cache");
        }

        if (time() < $bundle['expires']) {
            return $bundle;
        }

        // Delete expired entries so that they don't trigger 'contains'.
        $this->cache->delete($key);
    }

    /**
     * Builds and stores a cache bundle if the response should be stored.
     *
     * @param Request $request
     * @param Response $response
     * @param string $key
     *
     * @throws RuntimeException if it fails to store the response in the cache.
     */
    protected function store(Request $request, Response $response, $key)
    {
        // Check if response code should be stored.
        if ($this->shouldCacheResponse($response)) {

            // Build the response bundle to be stored
            $bundle = $this->buildCacheBundle($response);

            // Store the bundle in the cache
            $save = $this->cache->save($key, $bundle, $this->options['expire']);

            if ($save === false) {
                throw new RuntimeException("Failed to store response to cache");
            }

            // Log that it has been stored
            $this->logStoredBundle($request, $bundle);
        }
    }

    /**
     * Filters the request using a configured filter to determine if it should
     * be cached.
     *
     * @param Request The request to filter.
     *
     * @return boolean true if should be cached, false otherwise.
     */
    protected function filter(Request $request)
    {
        $filter = $this->options['filter'];
        return ! is_callable($filter) || $filter($request);
    }

    /**
     * Checks the method of the request to determine if it should be cached.
     *
     * @param Request The request to filter.
     *
     * @return boolean true if should be cached, false otherwise.
     */
    protected function checkMethod(Request $request)
    {
        $methods = (array) $this->options['methods'];
        return in_array($request->getMethod(), $methods);
    }

    /**
     * Returns true if the given request should be cached.
     *
     * @param Request $request The request to check.
     *
     * @return boolean true if the request should be cached, false otherwise.
     */
    private function shouldCacheRequest(Request $request)
    {
        return $this->checkMethod($request) && $this->filter($request);
    }

    /**
     * Determines if a response should be cached.
     *
     * @param Response $response
     */
    protected function shouldCacheResponse(Response $response)
    {
        return $response && $response->getStatusCode() < 400;
    }

    /**
     * Generates the cache key for the given request and request options. The
     * namespace should be set on the cache provider.
     *
     * @param Request $request The request to generate a key for.
     * @param array $options Configuration options.
     *
     * @return string The cache key
     */
    protected function getKey(Request $request, array $options)
    {
        return join(":", [
            $request->getMethod(),
            $request->getUri(),
            md5(json_encode($options)),
        ]);
    }

    /**
     * Builds a cache bundle using a given response.
     *
     * @param Response $response
     *
     * @return array The response bundle to cache.
     */
    protected function buildCacheBundle(Response $response)
    {
        return [
            'response' => $response,
            'expires'  => time() + $this->options['expire'],
        ];
    }

    /**
     * Invokes the default handler to produce a promise.
     *
     * @param Request $request
     * @param array $options
     *
     * @return PromiseInterface
     */
    protected function invokeDefault(Request $request, array $options)
    {
        return call_user_func($this->handler, $request, $options);
    }

    /**
     * Returns the default log level to use when logging response bundles.
     *
     * @return string LogLevel
     */
    protected function getDefaultLogLevel()
    {
        return LogLevel::DEBUG;
    }

    /**
     * Sets the log level to use, which can be either a string or a callable
     * that accepts a response (which could be null). A log level could also
     * be null, which indicates that the default log level should be used.
     *
     * @param string|callable|null
     */
    public function setLogLevel($logLevel)
    {
        $this->logLevel = $logLevel;
    }

    /**
     * Returns a log level for a given response.
     *
     * @param ResponseInterface $response The response being logged.
     *
     * @return string LogLevel
     */
    protected function getLogLevel(Response $response)
    {
        if ( ! $this->logLevel) {
            return $this->getDefaultLogLevel($response);
        }

        if (is_callable($this->logLevel)) {
            return call_user_func($this->logLevel, $response);
        }

        return (string) $this->logLevel;
    }

    /**
     * Convenient internal logger entry point.
     */
    private function log($message, $bundle)
    {
        if (isset($this->logger)) {

            $level   = $this->getLogLevel($bundle['response']);
            $message = $message;
            $context = $bundle;

            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Logs that a bundle has been stored in the cache.
     *
     * @param Request $request The request.
     * @param array $bundle The stored response bundle.
     */
    protected function logStoredBundle(Request $request, array $bundle)
    {
        $this->log($this->getStoredLogMessage($request, $bundle), $bundle);
    }

    /**
     * Logs that a bundle has been fetched from the cache.
     *
     * @param Request $request The request that produced the response.
     * @param array $bundle The fetched response bundle.
     */
    protected function logFetchedBundle(Request $request, array $bundle)
    {
        $this->log($this->getFetchedLogMessage($request, $bundle), $bundle);
    }

    /**
     * Internal abstraction for log messages.
     */
    private function getLogMessage(Request $request, array $bundle, $format)
    {
        return vsprintf($format, [
            gmdate("d/M/Y:H:i:s O"),
            $request->getMethod(),
            $request->getUri(),
            $bundle['expires'] - time(),
        ]);
    }

    /**
     * Returns the log message for when a bundle is stored in the cache.
     *
     * @param Request $request The request that produced the response.
     * @param array $bundle The stored response bundle.
     *
     * @return string The log message.
     */
    protected function getStoredLogMessage(Request $request, array $bundle)
    {
        return $this->getLogMessage(
            $request,
            $bundle,
            "[%s] %s %s stored in cache (expires in %ss)"
        );
    }

    /**
     * Returns the log message for when a bundle is fetched from the cache.
     *
     * @param Request $request The request that produced the response.
     * @param array $bundle The stored response bundle.
     *
     * @return string The log message.
     */
    protected function getFetchedLogMessage(Request $request, array $bundle)
    {
        return $this->getLogMessage(
            $request,
            $bundle,
            "[%s] %s %s fetched from cache (expires in %ss)"
        );
    }
}
