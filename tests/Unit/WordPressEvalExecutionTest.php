<?php

namespace Tests\Unit;

use hexa_package_whm\Models\WhmServer;
use hexa_package_wordpress\Services\WordPressManagerService;
use hexa_package_wordpress\Services\WordPressService;
use hexa_package_wptoolkit\Services\WpToolkitService;
use Tests\TestCase;

class WordPressEvalExecutionTest extends TestCase
{
    public function test_failed_native_evaluation_is_never_replayed_through_toolkit(): void
    {
        $server = new WhmServer();
        $toolkit = $this->createMock(WpToolkitService::class);
        $toolkit->expects($this->once())
            ->method('wpCliEvalWithPlugins')
            ->with($server, 17, 'echo "one execution";', 120)
            ->willReturn([
                'success' => false,
                'stdout' => 'partial mutation output',
                'message' => 'Native evaluation returned a non-zero exit code.',
                'exit_code' => 1,
            ]);
        $toolkit->expects($this->never())->method('wpCliEval');

        $rest = $this->createMock(WordPressService::class);
        $manager = new class($toolkit, $rest, $server) extends WordPressManagerService
        {
            public function __construct(WpToolkitService $toolkit, WordPressService $rest, private WhmServer $server)
            {
                parent::__construct($toolkit, $rest);
            }

            public function normalizeTarget(array $target): array
            {
                return [
                    'mode' => 'wptoolkit',
                    'server' => $this->server,
                    'install_id' => 17,
                    'default_author' => '',
                ];
            }

            public function usesWpToolkit(array $target): bool
            {
                return true;
            }
        };

        $result = $manager->evaluatePhp([], 'echo "one execution";');

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('partial mutation output', $result['stdout']);
    }
}
