<?php

namespace Tests\Unit;

use hexa_package_wordpress\Services\WordPressEvalPayloadDecoder;
use PHPUnit\Framework\TestCase;

class WordPressEvalPayloadDecoderTest extends TestCase
{
    public function test_it_decodes_marked_json_amid_command_noise(): void
    {
        $decoder = new WordPressEvalPayloadDecoder();
        $output = "Warning: cache is stale\nHEXA_RESULT:{\"success\":true,\"post_id\":42}\nDone";

        $this->assertSame([
            'success' => true,
            'post_id' => 42,
        ], $decoder->decode($output, 'HEXA_RESULT:'));
    }

    public function test_it_skips_invalid_matches_and_rejects_missing_markers(): void
    {
        $decoder = new WordPressEvalPayloadDecoder();
        $output = "HEXA_RESULT:not-json\nnoise HEXA_RESULT:{\"success\":false}";

        $this->assertSame(['success' => false], $decoder->decode($output, 'HEXA_RESULT:'));
        $this->assertNull($decoder->decode($output, ''));
        $this->assertNull($decoder->decode('no payload', 'HEXA_RESULT:'));
    }

    public function test_it_decodes_multiline_json_with_trailing_plugin_output(): void
    {
        $decoder = new WordPressEvalPayloadDecoder();
        $output = "plugin notice before\nHEXA_RESULT:{\n"
            . "  \"success\": true,\n"
            . "  \"data\": {\"message\": \"value with } and [ braces\", \"ids\": [4, 8]}\n"
            . "}plugin shutdown notice on the same line\nDone";

        $this->assertSame([
            'success' => true,
            'data' => [
                'message' => 'value with } and [ braces',
                'ids' => [4, 8],
            ],
        ], $decoder->decode($output, 'HEXA_RESULT:'));
    }

    public function test_it_ignores_noise_brackets_and_uses_the_next_balanced_json_value(): void
    {
        $decoder = new WordPressEvalPayloadDecoder();
        $output = 'HEXA_RESULT:[plugin warning] {"success":true,"data":[{"id":9}]}; more output';

        $this->assertSame([
            'success' => true,
            'data' => [['id' => 9]],
        ], $decoder->decode($output, 'HEXA_RESULT:'));
    }

    public function test_it_rejects_truncated_json_without_consuming_a_later_marker(): void
    {
        $decoder = new WordPressEvalPayloadDecoder();
        $output = 'HEXA_RESULT:{"success":true'
            . "\nHEXA_RESULT:{\"success\":false,\"reason\":\"rolled back\"} trailing";

        $this->assertSame([
            'success' => false,
            'reason' => 'rolled back',
        ], $decoder->decode($output, 'HEXA_RESULT:'));
    }
}
