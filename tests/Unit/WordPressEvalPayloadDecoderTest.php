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
}
