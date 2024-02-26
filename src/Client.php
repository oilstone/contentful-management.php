<?php

/**
 * This file is part of the contentful/contentful-management package.
 *
 * @copyright 2015-2024 Contentful GmbH
 * @license   MIT
 */

declare(strict_types=1);

namespace Contentful\Management;

use Contentful\Core\Api\BaseClient;
use Contentful\Core\Api\Link;
use Contentful\Core\Exception\RateLimitExceededException;
use Contentful\Core\Resource\ResourceArray;
use Contentful\Core\Resource\ResourceInterface as CoreResourceInterface;
use Contentful\Management\Exception\RateWaitTooLongException;
use Contentful\Management\Resource\Behavior\CreatableInterface;
use Contentful\Management\Resource\ResourceInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Client class.
 *
 * This class is responsible for querying Contentful's Content Management API.
 */
class Client extends BaseClient
{
    use ClientExtension\OrganizationExtension;
    use ClientExtension\SpaceExtension;
    use ClientExtension\UserExtension;

    /**
     * The default URI to which all requests should be made.
     *
     * @var string
     */
    public const URI_MANAGEMENT = 'https://api.contentful.com';

    /**
     * The special URI for uploading files.
     *
     * @var string
     */
    public const URI_UPLOAD = 'https://upload.contentful.com';

    /**
     * A ResourceBuilder instance,
     * which is responsible for converting API responses into PHP objects.
     *
     * @var ResourceBuilder
     */
    private $builder;

    /**
     * An instance of the configuration class that is used for handling API calls.
     *
     * @var ApiConfiguration
     */
    private $configuration;

    /**
     * @var RequestUriBuilder
     */
    private $requestUriBuilder;

    /**
     * @var LinkResolver
     */
    private $linkResolver;

    /**
     * @var int|null
     */
    private $maxRateLimitRetries;

    /**
     * @var int
     */
    private $maxRateLimitWait = 60;

    /**
     * Client constructor.
     *
     * @param string $accessToken A OAuth token or personal access token generated by Contentful
     * @param array  $options     An array of options, with the following supported values:
     *                            * guzzle: an instance of the Guzzle client
     *                            * logger: a PSR-3 logger
     *                            * host: a string that will replace the default Contentful URI
     */
    public function __construct(string $accessToken, array $options = [])
    {
        parent::__construct(
            $accessToken,
            $options['host'] ?? self::URI_MANAGEMENT,
            $options['logger'] ?? null,
            $options['guzzle'] ?? null
        );

        $this->builder = new ResourceBuilder();
        $this->configuration = new ApiConfiguration();
        $this->maxRateLimitRetries = $options['max_rate_limit_retries'] ?? 0;
        $this->requestUriBuilder = new RequestUriBuilder();
        $this->linkResolver = new LinkResolver($this, $this->configuration, $this->requestUriBuilder);
    }

    /**
     * Returns the active ResourceBuilder instance.
     */
    public function getBuilder(): ResourceBuilder
    {
        return $this->builder;
    }

    public function request(string $method, string $uri, array $options = []): CoreResourceInterface
    {
        try {
            $response = $this->callApi($method, \rtrim($uri, '/'), $options);
        } catch (\Exception $exception) {
            if ($exception instanceof RateLimitExceededException) {
                $secondsRemaing = $exception->getResponse()->getHeader('X-Contentful-RateLimit-Second-Remaining');
                if ($secondsRemaing) {
                    $secondsRemaing = (int) $secondsRemaing[0];
                } else {
                    $secondsRemaing = 0;
                }

                if ($secondsRemaing > $this->maxRateLimitWait) {
                    if ($exception->getPrevious() instanceof RequestException) {
                        throw new RateWaitTooLongException($exception->getPrevious(), "X-Contentful-RateLimit-Second-Remaining limit is over {$this->maxRateLimitWait} seconds");
                    }

                    throw $exception;
                }

                if ($this->maxRateLimitRetries > 0) {
                    --$this->maxRateLimitRetries;
                    \sleep($secondsRemaing);

                    return $this->request($method, $uri, $options);
                }

                throw $exception;
            }

            throw $exception;
        }

        $resource = $options['resource'] ?? null;

        if ($response) {
            /** @var ResourceInterface|ResourceArray|null $resource */
            $resource = $this->builder->build($response, $resource);
        }

        if ($resource) {
            // If it's not an instance of ResourceInterface,
            // it's an instance of ResourceArray
            foreach ($resource instanceof ResourceArray ? $resource : [$resource] as $resourceObject) {
                $resourceObject->setClient($this);
            }
        }

        return $resource;
    }

    /**
     * Persists the current resource in the given scope.
     * You can use this method in 2 ways.
     *
     * Creating using an actual resource object
     * ``` php
     * // $environment is an instance of Contentful\Management\Resource\Environment
     * $client->create($entry, $environment);
     * ```
     *
     * Creating using an array with the required IDs
     * ``` php
     * $client->create($entry, $entryCustomId, ['space' => $spaceId, 'environment' => $environmentId]);
     * ```
     *
     * @param CreatableInterface         $resource   The resource that needs to be created in Contentful
     * @param string                     $resourceId If this parameter is specified, the SDK will attempt
     *                                               to create a resource by making a PUT request on the endpoint
     *                                               by also specifying the ID
     * @param ResourceInterface|string[] $parameters Either an actual resource object,
     *                                               or an array containing the required IDs
     */
    public function create(CreatableInterface $resource, string $resourceId = '', $parameters = [])
    {
        if ($parameters instanceof ResourceInterface) {
            $parameters = $parameters->asUriParameters();
        }

        $config = $this->configuration->getConfigFor($resource);
        $uri = $this->requestUriBuilder->build($config, $parameters, $resourceId);

        $this->request($resourceId ? 'PUT' : 'POST', $uri, [
            'resource' => $resource,
            'body' => $resource->asRequestBody(),
            'headers' => $resource->getHeadersForCreation(),
            'host' => $config['host'] ?? null,
        ]);
    }

    /**
     * Make an API request using the given resource.
     * The object will be used to infer the API endpoint.
     *
     * @param ResourceInterface $resource An SDK resource object
     * @param string            $method   The HTTP method
     * @param string            $path     Optionally, a path to be added at the of the URI (like "/published")
     * @param array             $options  An array of valid options (host, body, headers)
     *
     * @return ResourceInterface|ResourceArray|null
     */
    public function requestWithResource(
        ResourceInterface $resource,
        string $method,
        string $path = '',
        array $options = []
    ) {
        $config = $this->configuration->getConfigFor($resource);
        $uri = $this->requestUriBuilder->build($config, $resource->asUriParameters());

        $options = \array_merge($options, [
            'host' => $config['host'] ?? null,
            'resource' => $resource,
        ]);

        $this->request($method, $uri.$path, $options);

        return $resource;
    }

    /**
     * @param string[] $parameters
     *
     * @return ResourceInterface|ResourceArray
     */
    public function fetchResource(
        string $class,
        array $parameters,
        ?Query $query = null,
        ?ResourceInterface $resource = null
    ) {
        $config = $this->configuration->getConfigFor($class);
        $uri = $this->requestUriBuilder->build($config, $parameters);

        /** @var ResourceInterface|ResourceArray $resource */
        $resource = $this->request('GET', $uri, [
            'resource' => $resource,
            'host' => $config['host'] ?? null,
            'query' => $query ? $query->getQueryData() : [],
        ]);

        return $resource;
    }

    /**
     * Resolves a link to a Contentful resource.
     *
     * @param string[] $parameters
     */
    public function resolveLink(Link $link, array $parameters = []): ResourceInterface
    {
        /** @var ResourceInterface $resource */
        $resource = $this->linkResolver->resolveLink($link, $parameters);

        return $resource;
    }

    /**
     * Resolves a collection of links to a Contentful resources.
     */
    public function resolveLinkCollection(array $links, array $parameters = []): array
    {
        return $this->linkResolver->resolveLinkCollection($links, $parameters);
    }

    public function getApi(): string
    {
        return 'MANAGEMENT';
    }

    protected function getExceptionNamespace()
    {
        return __NAMESPACE__.'\\Exception';
    }

    protected static function getSdkName(): string
    {
        return 'contentful-management.php';
    }

    protected static function getPackageName(): string
    {
        return 'contentful/contentful-management';
    }

    protected static function getApiContentType(): string
    {
        return 'application/vnd.contentful.management.v1+json';
    }
}
