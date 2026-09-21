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
            'normalizeTarget', 'usesWpToolkit', 'connectionMode', 'connectionLabel', 'publicationFeatures',
            'warmConnection', 'discoverInstallsForAccount', 'testConnection',
            'testWriteAccess', 'inspectPlugin', 'syncPluginFromGitHub',
            'getAcfFieldInventory', 'getAcfValues', 'listAuthors',
            'resolvePreferredTaxonomy', 'listTerms', 'ensureTerms', 'createPost',
            'updatePost', 'getPost', 'getPostSnapshot', 'listPosts', 'listMedia', 'getUserProfile',
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

    public function test_publication_feature_contract_tracks_the_nineteen_article_stages_for_every_mode(): void
    {
        $manager = app(WordPressManagerService::class);
        foreach (['wptoolkit', 'rest', 'hws_base_tools'] as $mode) {
            $contract = $manager->publicationFeatures(['mode' => $mode]);
            $this->assertCount(19, $contract['features']);
            $this->assertSame(19, count(array_unique(array_column($contract['features'], 'key'))));
            $this->assertContains('post_write', array_column($contract['features'], 'key'));
            $this->assertContains('inline_media', array_column($contract['features'], 'key'));
            $this->assertContains('faq_repeater', array_column($contract['features'], 'key'));
        }
    }

    public function test_external_warmup_accepts_only_the_selected_transport_credentials(): void
    {
        $manager = app(WordPressManagerService::class);
        $bridge = $manager->warmConnection([
            'mode' => 'hws_base_tools',
            'url' => 'https://wordpress.example.com',
            'hws_key_id' => 'hws_0123456789abcdef01234567',
            'hws_api_secret' => str_repeat('s', 64),
        ]);
        $rest = $manager->warmConnection([
            'mode' => 'rest',
            'url' => 'https://wordpress.example.com',
            'username' => 'editor',
            'application_password' => 'application-password',
        ]);

        $this->assertTrue($bridge['success']);
        $this->assertTrue($rest['success']);
        $this->assertFalse($manager->warmConnection(['mode' => 'hws_base_tools'])['success']);
        $this->assertFalse($manager->warmConnection(['mode' => 'rest'])['success']);
    }

    public function test_manager_units_stay_below_the_architecture_threshold(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            [$root.'/src/Services/WordPressManagerService.php'],
            glob($root.'/src/Services/Concerns/WordPressManager/*.php') ?: [],
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $sourceWithoutEmbeddedPrograms = preg_replace("/<<<'PHP'\\R.*?^PHP;/ms", "<<<'PHP'\\n[embedded WordPress program]\\nPHP;", $source) ?? $source;
            $lines = substr_count($sourceWithoutEmbeddedPrograms, "\n") + 1;
            $this->assertLessThan(700, $lines, basename($file));
        }
    }

    public function test_target_normalization_preserves_absolute_wordpress_paths(): void
    {
        $manager = app(WordPressManagerService::class);

        $absolute = $manager->normalizeTarget([
            'wp_path' => '/home/hexaprwire/public_html/',
        ]);
        $default = $manager->normalizeTarget([]);

        $this->assertSame('/home/hexaprwire/public_html', $absolute['wp_path']);
        $this->assertSame('public_html', $default['wp_path']);
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

    public function test_post_detail_readback_never_generates_missing_image_sizes(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root."/src/Services/Concerns/WordPressManager/ManagesWordPressUserAccounts.php");

        $this->assertStringNotContainsString("get_intermediate_image_sizes()", $source);
        $this->assertStringContainsString("\"_wp_attachment_metadata\"", $source);
        $this->assertStringContainsString("array_keys(", $source);
        $this->assertStringContainsString("catch (\\Throwable", $source);
    }

    public function test_rest_finalization_binds_the_wordpress_generated_slug_before_final_readback(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents(
            $root.'/src/Services/Concerns/WordPressManager/VerifiesWordPressPostMutations.php'
        );

        $capture = strpos($source, "data_get(\$finalizeResponse, 'data.slug', '')");
        $expected = strpos($source, "\$finalExpected['post_name'] = \$finalTransitionSlug;");
        $compare = strpos($source, '$finalMismatches = $this->compareRestPostState(');

        $this->assertNotFalse($capture);
        $this->assertNotFalse($expected);
        $this->assertNotFalse($compare);
        $this->assertLessThan($expected, $capture);
        $this->assertLessThan($compare, $expected);
        $this->assertStringContainsString("trim((string) (\$stageState['post_name'] ?? '')) === ''", $source);
        $this->assertStringContainsString("! in_array(\$requestedStatus, ['draft', 'pending', 'auto-draft'], true)", $source);
    }

    public function test_bulk_user_inventory_carries_distinct_post_and_content_counts_with_real_roles(): void
    {
        $root = dirname(__DIR__, 2);
        $inventory = (string) file_get_contents($root.'/src/Services/Concerns/WordPressManager/ManagesWordPressUserAccounts.php');
        $normalizer = (string) file_get_contents($root.'/src/Services/Concerns/WordPressManager/HandlesWordPressRestAndToolkit.php');

        $this->assertStringContainsString('$args=["fields"=>"all","number"=>9999]', $inventory);
        $this->assertStringNotContainsString('"user_url","roles"],"number"=>9999', $inventory);
        $this->assertStringContainsString('count_user_posts((int) $user->ID,"post",false)', $inventory);
        $this->assertStringContainsString('GROUP BY post_author', $inventory);
        $this->assertStringContainsString('"post_count"=>$postCount', $inventory);
        $this->assertStringContainsString('"post_count_known"=>true', $inventory);
        $this->assertStringContainsString('"content_count"=>$contentCount', $inventory);
        $this->assertStringContainsString('"content_count_known"=>true', $inventory);
        $this->assertStringContainsString('"post_count" => $postCount', $normalizer);
        $this->assertStringContainsString('"post_count_known" => $postCountKnown', $normalizer);
        $this->assertStringContainsString('"content_count" => $contentCount', $normalizer);
        $this->assertStringContainsString('"content_count_known" => $contentCountKnown', $normalizer);
    }
}
