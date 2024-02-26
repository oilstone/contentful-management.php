<?php

/**
 * This file is part of the contentful/contentful-management package.
 *
 * @copyright 2015-2024 Contentful GmbH
 * @license   MIT
 */

declare(strict_types=1);

namespace Contentful\Tests\Delivery\Unit\SystemProperties;

use Contentful\Management\SystemProperties\Locale;
use Contentful\Tests\Management\BaseTestCase;

class LocaleTest extends BaseTestCase
{
    public function testAll()
    {
        $fixture = $this->getParsedFixture('serialize.json');
        $sys = new Locale($fixture);

        $this->assertJsonStructuresAreEqual($fixture, $sys);
    }
}
