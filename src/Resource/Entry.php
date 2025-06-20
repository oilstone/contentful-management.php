<?php

/**
 * This file is part of the contentful/contentful-management package.
 *
 * @copyright 2015-2025 Contentful GmbH
 * @license   MIT
 */

declare(strict_types=1);

namespace Contentful\Management\Resource;

use Contentful\Core\Api\DateTimeImmutable;
use Contentful\Core\Resource\EntryInterface;
use Contentful\Management\Proxy\Extension\EntryProxyExtension;
use Contentful\Management\Resource\Behavior\ArchivableTrait;
use Contentful\Management\Resource\Behavior\CreatableInterface;
use Contentful\Management\Resource\Behavior\DeletableTrait;
use Contentful\Management\Resource\Behavior\PublishableTrait;
use Contentful\Management\Resource\Behavior\UpdatableTrait;
use Contentful\Management\SystemProperties\Entry as SystemProperties;

/**
 * Entry class.
 *
 * This class represents a resource with type "Entry" in Contentful.
 *
 * @see https://www.contentful.com/developers/docs/references/content-management-api/#/reference/entries
 */
class Entry extends BaseResource implements EntryInterface, CreatableInterface
{
    use ArchivableTrait;
    use DeletableTrait;
    use EntryProxyExtension;
    use PublishableTrait;
    use UpdatableTrait;

    /**
     * @var SystemProperties
     */
    protected $sys;

    /**
     * @var string
     */
    protected $contentTypeId;

    /**
     * @var array[]
     */
    protected $fields = [];

    /**
     * @var array[]
     */
    protected $metadata = [];

    /**
     * Entry constructor.
     */
    public function __construct(string $contentTypeId)
    {
        $this->contentTypeId = $contentTypeId;
    }

    public function getSystemProperties(): SystemProperties
    {
        return $this->sys;
    }

    public function jsonSerialize(): array
    {
        $fields = [];

        foreach ($this->fields as $fieldName => $fieldData) {
            $fields[$fieldName] = [];

            foreach ($fieldData as $locale => $data) {
                $fields[$fieldName][$locale] = $this->getFormattedData($data);
            }
        }

        $entry = [
            'sys' => $this->sys,
            'fields' => (object) $fields,
        ];

        if ($this->metadata) {
            $entry['metadata'] = (object) $this->metadata;
        }

        return $entry;
    }

    public function asUriParameters(): array
    {
        return [
            'space' => $this->sys->getSpace()->getId(),
            'environment' => $this->sys->getEnvironment()->getId(),
            'entry' => $this->sys->getId(),
        ];
    }

    protected function getSpaceId(): string
    {
        return $this->sys->getSpace()->getId();
    }

    protected function getEnvironmentId(): string
    {
        return $this->sys->getEnvironment()->getId();
    }

    protected function getEntryId(): string
    {
        return $this->sys->getId();
    }

    public function getHeadersForCreation(): array
    {
        return ['X-Contentful-Content-Type' => $this->contentTypeId];
    }

    /**
     * Formats data for JSON encoding.
     */
    private function getFormattedData($data)
    {
        if ($data instanceof DateTimeImmutable) {
            return (string) $data;
        }

        if (\is_array($data)) {
            if (isset($data['nodeType'])) {
                return $this->formatRichTextField($data);
            }

            return \array_map([$this, 'getFormattedData'], $data);
        }

        return $data;
    }

    /**
     * Rich text fields have a data object which PHP converts
     * to a simple array when empty.
     * The Management API does not recognize the value and throws an errors,
     * so we make an educated guess and force the data property to be an object.
     */
    private function formatRichTextField(array $value): array
    {
        if (\array_key_exists('data', $value) && !$value['data']) {
            $value['data'] = new \stdClass();
        }

        if (isset($value['content']) && \is_array($value['content'])) {
            foreach ($value['content'] as $index => $content) {
                if (\is_array($content) && isset($content['nodeType'])) {
                    $value['content'][$index] = $this->formatRichTextField($content);
                }
            }
        }

        return $value;
    }

    public function getField(string $name, string $locale)
    {
        return $this->fields[$name][$locale] ?? null;
    }

    public function getFields(?string $locale = null): array
    {
        if (null === $locale) {
            return $this->fields;
        }

        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$name] = $field[$locale] ?? null;
        }

        return $fields;
    }

    /**
     * @return static
     */
    public function setField(string $name, string $locale, $value)
    {
        if (!isset($this->fields[$name])) {
            $this->fields[$name] = [];
        }

        $this->fields[$name][$locale] = $value;

        return $this;
    }

    /**
     * @param string $name
     * @return array|null
     */
    public function getMetadataValue(string $name)
    {
        return $this->metadata[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return $this
     */
    public function setMetadataValue(string $name, $value)
    {
        $this->metadata[$name] = $value;

        return $this;
    }

    /**
     * @param string $tagId
     * @return $this
     */
    public function addTag(string $tagId)
    {
        $tags = $this->getMetadataValue('tags') ?? [];

        // Prevent attempting to set a duplicate tag

        foreach ($tags as $tag) {
            if ($tag['sys']['id'] === $tagId) {
                return;
            }
        }

        $tags[] = [
            'sys' => [
                'type' => 'Link',
                'linkType' => 'Tag',
                'id' => $tagId,
            ],
        ];

        return $this->setMetadataValue('tags', $tags);
    }

    /**
     * @param string $tagId
     * @return $this
     */
    public function removeTag(string $tagId)
    {
        return $this->setMetadataValue('tags', array_values(array_filter($this->getMetadataValue('tags') ?? [], function (array $tag) use ($tagId) {
            return $tag['sys']['id'] !== $tagId;
        })));
    }

    /**
     * Provides simple setX/getX capabilities,
     * without recurring to code generation.
     */
    public function __call(string $name, array $arguments)
    {
        $action = \mb_substr($name, 0, 3);
        if ('get' !== $action && 'set' !== $action) {
            \trigger_error(\sprintf(
                'Call to undefined method %s::%s()',
                static::class,
                $name
            ), \E_USER_ERROR);
        }

        $field = $this->extractFieldName($name);

        return 'get' === $action
            ? $this->getField($field, ...$arguments)
            : $this->setField($field, ...$arguments);
    }

    private function extractFieldName(string $name): string
    {
        return \lcfirst(\mb_substr($name, 3));
    }
}
