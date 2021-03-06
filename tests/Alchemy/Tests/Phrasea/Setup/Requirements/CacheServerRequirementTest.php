<?php

namespace Alchemy\Tests\Phrasea\Setup\Requirements;

use Alchemy\Phrasea\Setup\Requirements\CacheServerRequirement;

/**
 * @group functional
 * @group legacy
 */
class CacheServerRequirementTest extends RequirementsTestCase
{
    protected function provideRequirements()
    {
        return new CacheServerRequirement;
    }
}
