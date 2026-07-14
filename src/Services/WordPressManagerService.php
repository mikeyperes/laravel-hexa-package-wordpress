<?php

namespace hexa_package_wordpress\Services;

use hexa_package_wptoolkit\Services\WpToolkitService;

class WordPressManagerService
{
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressConnections;
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressAcf;
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressAcfMutations;
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressTaxonomies;
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressPosts;
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressUsersAndMeta;
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressMedia;
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\HandlesWordPressRestAndToolkit;
    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressAvatars;

    use \hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressUserAccounts;

    public function __construct(
        protected WpToolkitService $wptoolkit,
        protected WordPressService $rest,
    ) {
    }

}
