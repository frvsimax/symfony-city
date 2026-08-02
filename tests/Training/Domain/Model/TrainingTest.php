<?php

namespace App\Tests\Training\Domain\Model;

use App\Training\Domain\Model\Training;
use PHPUnit\Framework\TestCase;
use App\Training\Domain\Exception\TrainingAlreadyCancelled;
use App\Training\Domain\Exception\TrainingNotDeletable;

class TrainingTest extends TestCase
{
    public function testNewTrainingStartsWithNewStatus(): void
    {
        $training = Training::create(
            groupId: 1,
            areaId: 2,
            coachId: 3,
            startsAt: new \DateTimeImmutable('2026-08-10 18:00:00'),
            endsAt: new \DateTimeImmutable('2026-08-10 19:00:00'),
            additionalCapacity: 12,
        );

        $this->assertTrue($training->isNew());
        $this->assertFalse($training->isCancelled());
        $this->assertFalse($training->isFinished());
    }

    public function testCancelMarksTrainingAsCancelled(): void
    {
        $training = Training::create(
            groupId: 1,
            areaId: 2,
            coachId: 3,
            startsAt: new \DateTimeImmutable('2026-08-10 18:00:00'),
            endsAt: new \DateTimeImmutable('2026-08-10 19:00:00'),
            additionalCapacity: 12,
        );

        $training->cancel();

        $this->assertFalse($training->isNew());
        $this->assertTrue($training->isCancelled());
        $this->assertFalse($training->isFinished());
    }

    public function testCancelAlreadyCancelledTrainingThrows(): void
    {
        $training = Training::create(
            groupId: 1,
            areaId: 2,
            coachId: 3,
            startsAt: new \DateTimeImmutable('2026-08-10 18:00:00'),
            endsAt: new \DateTimeImmutable('2026-08-10 19:00:00'),
            additionalCapacity: 12,
        );

        $training->cancel();
        $this->expectException(TrainingAlreadyCancelled::class);
        $training->cancel();
    }

    public function testAssertDeletableWhenNoAttendancesPasses(): void
    {
        $training = Training::create(
            groupId: 1,
            areaId: 2,
            coachId: 3,
            startsAt: new \DateTimeImmutable('2026-08-10 18:00:00'),
            endsAt: new \DateTimeImmutable('2026-08-10 19:00:00'),
            additionalCapacity: 12,
        );

        $this->expectNotToPerformAssertions();
        $training->assertDeletable(hasAttendances: false);
    }

    public function testAssertDeletableWhenAttendancesThrows(): void
    {
        $training = Training::create(
            groupId: 1,
            areaId: 2,
            coachId: 3,
            startsAt: new \DateTimeImmutable('2026-08-10 18:00:00'),
            endsAt: new \DateTimeImmutable('2026-08-10 19:00:00'),
            additionalCapacity: 12,
        );

        $this->expectException(TrainingNotDeletable::class);
        $training->assertDeletable(hasAttendances: true);
    }
}