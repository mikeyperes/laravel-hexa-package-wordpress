<?php

namespace hexa_package_wordpress\Services\Concerns;

use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressMedia;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressPosts;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressTaxonomies;
use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressUsersAndMeta;

/**
 * Backward-compatible composition point for WordPress content workflows.
 */
trait ManagesWordPressContent
{
    use ManagesWordPressMedia;
    use ManagesWordPressPosts;
    use ManagesWordPressTaxonomies;
    use ManagesWordPressUsersAndMeta;
}
