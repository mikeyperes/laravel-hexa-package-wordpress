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
            ->with($server, 17, $this->callback(fn($code) => str_contains($code, base64_encode('echo "one execution";')) && str_contains($code, 'HEXA_EVAL_BEGIN:')), 120)
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
    private function managerFor(WpToolkitService $toolkit): WordPressManagerService
    {
        return new class($toolkit,$this->createMock(WordPressService::class)) extends WordPressManagerService {
            public function normalizeTarget(array $target):array{return ['mode'=>'wptoolkit','server'=>new WhmServer(),'install_id'=>17,'default_author'=>''];}
            public function usesWpToolkit(array $target):bool{return true;}
        };
    }
    public function test_clean_native_exit_without_execution_uses_one_documented_pre_execution_recovery():void
    {
        $tk=$this->createMock(WpToolkitService::class);$receipt='';
        $tk->expects($this->once())->method('wpCliEvalWithPlugins')->willReturnCallback(function($server,$id,$code)use(&$receipt){preg_match('/HEXA_EVAL_BEGIN:([a-f0-9]{32})/',$code,$m);$receipt=$m[1];return ['success'=>true,'exit_code'=>0,'stdout'=>''];});
        $tk->expects($this->once())->method('wpCliRaw')->willReturnCallback(function()use(&$receipt){return ['success'=>true,'exit_code'=>0,'stdout'=>"\nHEXA_EVAL_BEGIN:$receipt\nresult\nHEXA_EVAL_END:$receipt\n"];});
        $r=$this->managerFor($tk)->evaluatePhp([],'echo "result";');$this->assertTrue($r['success']);$this->assertSame('result',$r['stdout']);$this->assertTrue($r['evaluation_completed']);$this->assertSame('toolkit_pre_execution_recovery',$r['execution_route']);
    }
    public function test_started_payload_is_never_replayed_even_when_it_exits_cleanly_before_completion():void
    {
        $tk=$this->createMock(WpToolkitService::class);$tk->method('wpCliEvalWithPlugins')->willReturnCallback(function($server,$id,$code){preg_match('/HEXA_EVAL_BEGIN:([a-f0-9]{32})/',$code,$m);return ['success'=>true,'exit_code'=>0,'stdout'=>"\nHEXA_EVAL_BEGIN:$m[1]\npartial"];});$tk->expects($this->never())->method('wpCliRaw');$r=$this->managerFor($tk)->evaluatePhp([],'exit;');$this->assertFalse($r['success']);$this->assertSame('evaluation_incomplete',$r['error_code']);$this->assertSame('partial',$r['stdout']);
    }
    public function test_transport_failure_without_receipt_is_uncertain_and_is_not_replayed():void
    {
        $tk=$this->createMock(WpToolkitService::class);$tk->method('wpCliEvalWithPlugins')->willReturn(['success'=>false,'exit_code'=>null,'stdout'=>'','message'=>'Transport timeout']);$tk->expects($this->never())->method('wpCliRaw');$r=$this->managerFor($tk)->evaluatePhp([],'echo "x";');$this->assertFalse($r['success']);$this->assertFalse($r['evaluation_started']);
    }
    public function test_second_route_without_execution_receipt_is_reported_as_not_executed():void
    {
        $tk=$this->createMock(WpToolkitService::class);$tk->method('wpCliEvalWithPlugins')->willReturn(['success'=>true,'exit_code'=>0,'stdout'=>'']);$tk->method('wpCliRaw')->willReturn(['success'=>true,'exit_code'=>0,'stdout'=>'']);$r=$this->managerFor($tk)->evaluatePhp([],'echo "x";');$this->assertFalse($r['success']);$this->assertSame('evaluation_not_executed',$r['error_code']);
    }

    public function test_trimmed_native_output_still_has_valid_receipts_and_preserves_the_payload():void
    {
        $tk=$this->createMock(WpToolkitService::class);$tk->method('wpCliEvalWithPlugins')->willReturnCallback(function($server,$id,$code){preg_match('/HEXA_EVAL_BEGIN:([a-f0-9]{32})/',$code,$m);return ['success'=>true,'exit_code'=>0,'stdout'=>trim("\nHEXA_EVAL_BEGIN:$m[1]\nHWS_FORMS:{\"forms\":[]}\nHWS_FORMS_END\nHEXA_EVAL_END:$m[1]\n")];});$tk->expects($this->never())->method('wpCliRaw');$r=$this->managerFor($tk)->evaluatePhp([],'echo "json";');$this->assertTrue($r['success']);$this->assertTrue($r['evaluation_started']);$this->assertTrue($r['evaluation_completed']);$this->assertSame("HWS_FORMS:{\"forms\":[]}\nHWS_FORMS_END",$r['stdout']);
    }

}
