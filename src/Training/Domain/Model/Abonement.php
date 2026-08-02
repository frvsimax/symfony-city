<?php

namespace App\Training\Domain\Model;

use App\Training\Domain\Exception\AbonementEmpty;
use App\Training\Domain\Exception\InvalidAbonementSize;
use App\Training\Domain\ValueObject\AbonementStatus;
use DateTimeImmutable;

final class Abonement
{
    private function __construct(
        private readonly int $playerId,
        private readonly int $groupId,
        private readonly int $productId,
        private int $trainingsLeft,
        private AbonementStatus $status,
        private readonly DateTimeImmutable $createdOn,
    ) {
    }

    public static function create(
        int $playerId,
        int $groupId,
        int $productId,
        int $trainingsCount,
        DateTimeImmutable $createdOn,
    ): self {
        if ($trainingsCount <= 0) {
            throw new InvalidAbonementSize('Abonement trainings count must be greater than zero.');
        }

        return new self(
            playerId: $playerId,
            groupId: $groupId,
            productId: $productId,
            trainingsLeft: $trainingsCount,
            status: AbonementStatus::Unused,
            createdOn: $createdOn,
        );
    }

    public function debitOne(): void
    {
        if ($this->trainingsLeft === 0) {
            throw new AbonementEmpty('Cannot debit an abonement with zero trainings left.');
        }

        $this->trainingsLeft--;

        if ($this->status === AbonementStatus::Unused) {
            $this->status = AbonementStatus::Active;
        }

        if ($this->trainingsLeft === 0) {
            $this->status = AbonementStatus::Inactive;
        }
    }

    public function creditOne(): void
    {
        $this->trainingsLeft++;

        if ($this->status === AbonementStatus::Inactive){
            $this->status = AbonementStatus::Active;
        }
    }

    public function trainingsLeft(): int
    {
        return $this->trainingsLeft;
    }

    public function isUnused(): bool
    {
        return $this->status === AbonementStatus::Unused;
    }

    public function isActive(): bool
    {
        return $this->status === AbonementStatus::Active;
    }

    public function isInactive(): bool
    {
        return $this->status === AbonementStatus::Inactive;
    }
}
