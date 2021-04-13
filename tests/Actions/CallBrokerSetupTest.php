<?php

namespace RTippin\Messenger\Tests\Actions;

use RTippin\Messenger\Actions\Calls\CallBrokerSetup;
use RTippin\Messenger\Contracts\VideoDriver;
use RTippin\Messenger\Exceptions\CallBrokerException;
use RTippin\Messenger\Models\Call;
use RTippin\Messenger\Models\Thread;
use RTippin\Messenger\Tests\FeatureTestCase;

class CallBrokerSetupTest extends FeatureTestCase
{
    private Thread $group;
    private Call $call;

    protected function setUp(): void
    {
        parent::setUp();

        $this->group = $this->createGroupThread($this->tippin);
        $this->call = Call::factory()->for($this->group)->owner($this->tippin)->create();
    }

    /** @test */
    public function it_updates_call_with_room_data()
    {
        $this->mock(VideoDriver::class)->shouldReceive([
            'create' => true,
            'getRoomId' => '123456',
            'getRoomPin' => 'TEST-PIN',
            'getRoomSecret' => 'TEST-SECRET',
            'getExtraPayload' => 'TEST-EXTRA-PAYLOAD',
        ]);

        app(CallBrokerSetup::class)->execute(
            $this->group,
            $this->call
        );

        $this->assertDatabaseHas('calls', [
            'id' => $this->call->id,
            'setup_complete' => true,
            'teardown_complete' => false,
            'room_id' => '123456',
            'room_pin' => 'TEST-PIN',
            'room_secret' => 'TEST-SECRET',
            'payload' => 'TEST-EXTRA-PAYLOAD',
        ]);
    }

    /** @test */
    public function it_throws_exception_if_setup_failed()
    {
        $this->mock(VideoDriver::class)->shouldReceive([
            'create' => false,
        ]);

        $this->expectException(CallBrokerException::class);
        $this->expectExceptionMessage('Setup with video provider failed.');

        app(CallBrokerSetup::class)->execute(
            $this->group,
            $this->call
        );
    }

    /** @test */
    public function it_throws_exception_if_call_already_setup()
    {
        $this->call->update([
            'setup_complete' => true,
        ]);

        $this->expectException(CallBrokerException::class);
        $this->expectExceptionMessage('Call does not need to be setup.');

        app(CallBrokerSetup::class)->execute(
            $this->group,
            $this->call
        );
    }
}
