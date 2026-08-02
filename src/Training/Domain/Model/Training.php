<?php

namespace App\Training\Domain\Model;

use App\Training\Domain\ValueObject\TrainingStatus;
use App\Training\Domain\Exception\TrainingAlreadyCancelled;
use App\Training\Domain\Exception\TrainingNotDeletable;
use DateTimeImmutable;


final class Training
{
    private function __construct(
        private readonly int $groupId,
        private readonly int $areaId,
        private readonly int $coachId,
        private readonly DateTimeImmutable $startsAt,
        private readonly DateTimeImmutable $endsAt,
        private readonly int $additionalCapacity,
        private TrainingStatus $status,
    ) {
    }

    public static function create(
        int $groupId,
        int $areaId,
        int $coachId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        int $additionalCapacity,
    ): self {
        return new self(
            groupId: $groupId,
            areaId: $areaId,
            coachId: $coachId,
            startsAt: $startsAt,
            endsAt: $endsAt,
            additionalCapacity: $additionalCapacity,
            status: TrainingStatus::New,
        );
    }

    public function cancel(): void
    {
        if ($this->isCancelled()) {
            throw new TrainingAlreadyCancelled('Training already cancelled');
        }
        $this->status = TrainingStatus::Cancelled;
    }

    public function assertDeletable(bool $hasAttendances): void
    {
        if ($hasAttendances) {
            throw new TrainingNotDeletable('Training cannot be deleted when attendances exist');
        }
    }

    public function isNew(): bool
    {
        return $this->status === TrainingStatus::New;
    }

    public function isFinished(): bool
    {
        return $this->status === TrainingStatus::Finished;
    }

    public function isCancelled(): bool
    {
        return $this->status === TrainingStatus::Cancelled;
    }
}
