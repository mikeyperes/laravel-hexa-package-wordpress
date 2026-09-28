<?php

namespace HexaPackageTests\WordPress;

use hexa_core\Operations\OperationLog;
use hexa_package_wordpress\Media\WordPressMediaOperationEvents;
use hexa_package_wordpress\Media\WordPressMediaOperationStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Media uploads started by an operation show as that operation's steps. */
final class WordPressMediaOperationEventsTest extends TestCase
{
    private WordPressMediaOperationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('category');
            $table->text('description');
            $table->json('context')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_timezone')->nullable();
            $table->timestamps();
        });
        $this->store = app(WordPressMediaOperationStore::class);
    }

    public function test_named_uploads_become_labelled_steps_with_stage_progress(): void
    {
        $this->store->record('media-upload-a', ['stage' => 'download', 'state' => 'ok', 'message' => 'Downloaded photo.']);
        $this->store->record('media-upload-a', ['stage' => 'upload', 'state' => 'working', 'message' => 'Uploading.']);
        $this->store->record('media-upload-b', ['stage' => 'assign', 'state' => 'ok', 'message' => 'Assigned.']);
        $this->store->finish('media-upload-b', ['success' => true]);

        $log = OperationLog::for('tool');
        $log->run('run-1')->step('photos', 'Copy photos', 'running', 'Copying.', 30, [
            'operation_child_media_ids' => [['id' => 'media-upload-a', 'label' => 'Site A'], ['id' => 'media-upload-b', 'label' => 'Site B']],
        ]);

        $operation = $log->snapshot('run-1', [new WordPressMediaOperationEvents($this->store)]);
        $titles = array_column($operation['steps'], 'title');
        $this->assertSame(['Copy photos', 'Site A: Download', 'Site A: Upload', 'Site B: Assign'], $titles);
        $this->assertSame(['success', 'success', 'success', 'success'], array_column($operation['steps'], 'state'), 'a running step is done once a later step starts');
        $this->assertSame((int) floor((68 + 100) / 2), $operation['progress'], 'upload A at 68, finished B at 100');
        $this->assertSame('running', $operation['status']);
        $this->assertStringStartsWith('Site A: ', $operation['logs'][1]['message']);
    }

    public function test_a_failed_upload_fails_the_run_and_the_run_id_itself_is_checked(): void
    {
        $this->store->record('run-upload-2', ['stage' => 'upload', 'state' => 'error', 'message' => 'Upload refused.']);
        $this->store->finish('run-upload-2', ['success' => false, 'message' => 'Upload refused.']);
        $log = OperationLog::for('tool');
        $log->record('run-upload-2', 'working', 'Started.');

        $operation = $log->snapshot('run-upload-2', [new WordPressMediaOperationEvents($this->store)]);
        $this->assertSame(['error', 100], [$operation['status'], $operation['progress']]);
        $this->assertSame('error', $operation['steps'][0]['state']);

        $source = new WordPressMediaOperationEvents($this->store);
        $this->assertSame(['events' => [], 'progress' => null, 'failed' => false], $source->operationEvents('no-uploads', []));
    }
}
