<?php

/**
 * This file is part of the contentful/contentful-management package.
 *
 * @copyright 2015-2025 Contentful GmbH
 * @license   MIT
 */

declare(strict_types=1);

namespace Contentful\Management\Mapper\ContentType\Validation;

use Contentful\Management\Mapper\BaseMapper;
use Contentful\Management\Resource\ContentType\Validation\UniqueValidation as ResourceClass;

/**
 * UniqueValidation class.
 */
class UniqueValidation extends BaseMapper
{
    public function map($resource, array $data): ResourceClass
    {
        return new ResourceClass();
    }
}
