<?php

namespace hexa_package_wordpress\Services\Concerns;

use hexa_package_wordpress\Services\Concerns\WordPressManager\ManagesWordPressUserAccounts;

/**
 * Backward-compatible composition point for WordPress account workflows.
 */
trait ManagesWordPressUsers
{
    use ManagesWordPressUserAccounts;
}
