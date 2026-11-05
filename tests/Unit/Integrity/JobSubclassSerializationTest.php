<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use PlaylogiqUtils\Integrity\Jobs\DispatchFleetIntegrityCheck;
use PlaylogiqUtils\Integrity\Jobs\VerifyControlProgram;

/**
 * Applications extend the integrity jobs (to keep their own class names in queue payloads,
 * unique locks and job ids), and a queued job is serialized through SerializesModels.
 *
 * Its __serialize() reflects on the concrete class, which does not see a parent's private
 * properties: a private $trigger on the library job would be dropped from the payload of
 * every subclass and come back as null on the worker. These pin that the state survives.
 */
class JobSubclassSerializationTest extends TestCase
{
    public function testFleetCheckSubclassKeepsItsArgumentsThroughTheQueue(): void
    {
        $job = new AppDispatchFleetIntegrityCheck(2, 7, 'manual:abc');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(AppDispatchFleetIntegrityCheck::class, $restored);
        $this->assertSame(2, $this->read($restored, 'trigger'));
        $this->assertSame(7, $this->read($restored, 'createdBy'));
        $this->assertSame('manual:abc', $this->read($restored, 'groupKey'));
    }

    public function testVerifySubclassKeepsItsArgumentsThroughTheQueue(): void
    {
        $job = new AppVerifyControlProgram(2, 7);

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(AppVerifyControlProgram::class, $restored);
        $this->assertSame(2, $this->read($restored, 'trigger'));
        $this->assertSame(7, $this->read($restored, 'createdBy'));
        $this->assertSame('sync', $restored->connection);
    }

    /** @return mixed */
    private function read(object $job, string $property)
    {
        $reflection = new \ReflectionProperty($job, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($job);
    }
}

class AppDispatchFleetIntegrityCheck extends DispatchFleetIntegrityCheck
{
}

class AppVerifyControlProgram extends VerifyControlProgram
{
}
