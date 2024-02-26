<?php

/**
 * This file is part of the contentful/contentful-management package.
 *
 * @copyright 2015-2024 Contentful GmbH
 * @license   MIT
 */

declare(strict_types=1);

namespace Contentful\Management\Resource\ContentType\Validation;

/**
 * NodesValidation class stub.
 */
class NodesValidation implements ValidationInterface
{
    public static function getValidFieldTypes(): array
    {
        return [];
    }

    public function jsonSerialize(): mixed
    {
        return [];
    }
}
