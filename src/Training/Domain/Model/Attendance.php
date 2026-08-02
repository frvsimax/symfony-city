<?php

namespace App\Training\Domain\Model;

final class Attendance
{
    private function __construct(
        private readonly int $trainingId,
        private readonly int $playerId,
        private bool $present,
        private bool $debitApplied,
    ) {
    }

    public static function create(int $trainingId, int $playerId): self
    {
        return new self(
            trainingId: $trainingId,
            playerId: $playerId,
            present: false,
            debitApplied: false,
        );
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function isDebitApplied(): bool
    {
        return $this->debitApplied;
    }

    public function markPresent(): bool
    {
        $this->present = true;

        if ($this->debitApplied) {
            return false;
        }

        $this->debitApplied = true;

        return true;
    }

    public function markAbsent(): bool
    {
        $this->present = false;
        if (!$this->debitApplied) {
            return false;
        }
        $this->debitApplied = false;

        return true;
    }
}
