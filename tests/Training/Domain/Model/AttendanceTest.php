<?php

namespace App\Tests\Training\Domain\Model;

use PHPUnit\Framework\TestCase;
use App\Training\Domain\Model\Attendance;

class AttendanceTest extends TestCase
{
    public function testNewAttendanceStartsAbsentWithoutDebit(): void
    {
        $attendance = Attendance::create(
            trainingId: 100,
            playerId: 100,
        );

        $this->assertFalse($attendance->isPresent());
        $this->assertFalse($attendance->isDebitApplied());
    }

    public function testMarkPresentAppliesDebit(): void
    {
        $attendance = Attendance::create(
            trainingId: 100,
            playerId: 100,
        );

        $needsDebit = $attendance->markPresent();

        $this->assertTrue($needsDebit);
        $this->assertTrue($attendance->isPresent());
        $this->assertTrue($attendance->isDebitApplied());
    }

    public function testMarkPresentTwiceDoesNotRequestSecondDebit(): void
    {
        $attendance = Attendance::create(
            trainingId: 100,
            playerId: 100,
        );

        $attendance->markPresent();

        $needsDebit = $attendance->markPresent();

        $this->assertFalse($needsDebit);
        $this->assertTrue($attendance->isPresent());
        $this->assertTrue($attendance->isDebitApplied());
    }

    public function testMarkAbsentAfterPresentRequestsCredit(): void
    {
        $attendance = Attendance::create(
            trainingId: 100,
            playerId: 100,
        );

        $attendance->markPresent();

        $needsCredit = $attendance->markAbsent();

        $this->assertTrue($needsCredit);
        $this->assertFalse($attendance->isPresent());
        $this->assertFalse($attendance->isDebitApplied());
    }

    public function testMarkAbsentWhenAlreadyAbsentDoesNotRequestCredit(): void
    {
        $attendance = Attendance::create(
            trainingId: 100,
            playerId: 100,
        );

        $needsCredit = $attendance->markAbsent();

        $this->assertFalse($needsCredit);
        $this->assertFalse($attendance->isPresent());
        $this->assertFalse($attendance->isDebitApplied());
    }
}