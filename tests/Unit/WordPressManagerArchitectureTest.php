<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressManagerService;
use Tests\TestCase;

class WordPressManagerArchitectureTest extends TestCase
{
    public function test_manager_api_survives_the_concern_split(): void
    {
        $this->requireInstalledPackage(
            'hexawebsystems/laravel-hexa-package-wordpress',
            WordPressManagerService::class,
        );

        foreach ([
            'normalizeTarget', 'usesWpToolkit', 'connectionMode', 'connectionLabel',
            'warmConnection', 'discoverInstallsForAccount', 'testConnection',
            'testWriteAccess', 'inspectPlugin', 'syncPluginFromGitHub',
            'getAcfFieldInventory', 'getAcfValues', 'listAuthors',
            'resolvePreferredTaxonomy', 'listTerms', 'ensureTerms', 'createPost',
            'updatePost', 'getPost', 'listPosts', 'listMedia', 'getUserProfile',
            'setUserAvatar', 'updateNativeField', 'updateUserMeta', 'updateOption',
            'updateAcfField', 'normalizeAcfMediaIdList', 'updateAcfGallery',
            'getOption', 'getSiteIcon', 'purgeSiteCache', 'createLetterSiteIcon',
            'setSiteIcon', 'clearSiteIcon', 'uploadMedia', 'updateMedia',
            'renameMediaFile', 'deletePost', 'deleteMedia', 'setPostTerms',
            'evaluatePhp', 'createUser', 'deleteUser', 'recreateUserWithUsername',
            'generateLoginUrl', 'getCredentials', 'getInstallInfo',
            'getPostDetailsByIds', 'getUserRole', 'listUsers', 'setUserRole',
            'updatePostMeta', 'updateUser',
        ] as $method) {
            $this->assertTrue(method_exists(WordPressManagerService::class, $method), $method);
        }
    }

    public function test_manager_units_stay_below_the_architecture_threshold(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            [$root.'/src/Services/WordPressManagerService.php'],
            glob($root.'/src/Services/Concerns/WordPressManager/*.php') ?: [],
        );

        foreach ($files as $file) {
            $lines = count(file($file, FILE_IGNORE_NEW_LINES));
            $this->assertLessThan(700, $lines, basename($file));
        }
    }

    public function test_legacy_traits_are_composition_shims_not_duplicate_implementations(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['ManagesWordPressContent.php', 'ManagesWordPressUsers.php'] as $name) {
            $source = (string) file_get_contents($root.'/src/Services/Concerns/'.$name);

            $this->assertLessThan(80, substr_count($source, "\n") + 1, $name);
            $this->assertDoesNotMatchRegularExpression('/\bfunction\s+[A-Za-z_]/', $source);
        }
    }
}
