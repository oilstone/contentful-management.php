<?php

/**
 * This file is part of the contentful/contentful-management package.
 *
 * @copyright 2015-2025 Contentful GmbH
 * @license   MIT
 */

declare(strict_types=1);

namespace Contentful\Management\Resource;

use Contentful\Core\Api\Link;
use Contentful\Core\Resource\SystemPropertiesInterface;
use Contentful\Management\Client;

/**
 * BaseResource class.
 */
abstract class BaseResource implements ResourceInterface
{
    /**
     * @var SystemPropertiesInterface
     */
    protected $sys;

    /**
     * @var Client|null
     */
    protected $client;

    public function getId(): string
    {
        return $this->getSystemProperties()->getId();
    }

    public function getType(): string
    {
        return $this->getSystemProperties()->getType();
    }

    public function asLink(): Link
    {
        return new Link($this->getId(), $this->getType());
    }

    public function asRequestBody()
    {
        $body = $this->jsonSerialize();

        unset($body['sys']);

        return \GuzzleHttp\Utils::jsonEncode((object) $body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Sets the current Client object instance.
     * This is done automatically when performing API calls,
     * so it shouldn't be used manually.
     *
     * @return static
     */
    public function setClient(Client $client)
    {
        $this->client = $client;

        return $this;
    }
}
