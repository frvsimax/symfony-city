<?php

namespace App\Tests\Training\Domain\Model;

use App\Training\Domain\Model\Abonement;
use PHPUnit\Framework\TestCase;
use App\Training\Domain\Exception\AbonementEmpty;
use App\Training\Domain\Exception\InvalidAbonementSize;

class AbonementTest extends TestCase
{
    public function testNewAbonementStartsUnusedWithFullBalance(): void
    {
        $abonement = Abonement::create(
            playerId: 10,
            groupId: 20,
            productId: 30,
            trainingsCount: 8,
            createdOn: new \DateTimeImmutable('2026-08-01'),
        );

        $this->assertTrue($abonement->isUnused());
        $this->assertSame(8, $abonement->trainingsLeft());
    }

    public function testDebitOneDecreasesBalanceAndActivatesUnusedAbonement(): void
    {
        $abonement = Abonement::create(
            playerId: 10,
            groupId: 20,
            productId: 30,
            trainingsCount: 8,
            createdOn: new \DateTimeImmutable('2026-08-01'),
        );

        $abonement->debitOne();

        $this->assertSame(7,$abonement->trainingsLeft());
        $this->assertSame(true,$abonement->isActive());
        $this->assertFalse($abonement->isUnused());
    }

    public function testDebitOneOnEmptyAbonementThrows(): void
    {
        $abonement = Abonement::create(
            playerId: 10,
            groupId : 20,
            productId: 30,
            trainingsCount: 1,
            createdOn: new \DateTimeImmutable('2026-08-01'),
        );

        $abonement->debitOne();

        $this->expectException(AbonementEmpty::class);

        $abonement->debitOne();
    }

    public function testLastDebitMakesAbonementInactive(): void
    {
        $abonement = Abonement::create(
            playerId: 10,
            groupId : 20,
            productId: 30,
            trainingsCount: 1,
            createdOn: new \DateTimeImmutable('2026-08-01'),
        );

        $abonement->debitOne();

        $this->assertSame(0,$abonement->trainingsLeft());
        $this->assertSame(true,$abonement->isInactive());
        $this->assertSame(false,$abonement->isActive());
    }

    public function testCreditOneIncreasesBalanceAndReactivatesInactiveAbonement(): void
    {
        $abonement = Abonement::create(
            playerId: 10,
            groupId : 20,
            productId: 30,
            trainingsCount: 1,
            createdOn: new \DateTimeImmutable('2026-08-01'),
        );

        $abonement->debitOne();
        $this->assertSame(0,$abonement->trainingsLeft());
        $abonement->creditOne();

        $this->assertSame(1,$abonement->trainingsLeft());
        $this->assertTrue($abonement->isActive());
        $this->assertFalse($abonement->isInactive());
    }

    public function testDebitOneOnActiveAbonementKeepsActiveStatus(): void
    {
        $abonement = Abonement::create(
            playerId: 10,
            groupId : 20,
            productId: 30,
            trainingsCount: 8,
            createdOn: new \DateTimeImmutable('2026-08-01'),
        );

        $abonement->debitOne();
        $abonement->debitOne();

        $this->assertSame(6,$abonement->trainingsLeft());
        $this->assertTrue($abonement->isActive());
        $this->assertFalse($abonement->isUnused());
        $this->assertFalse($abonement->isInactive());
    }

    public function testCreateWithNonPositiveTrainingsCountThrows(): void
    {
        $this->expectException(InvalidAbonementSize::class);
            Abonement::create(
            playerId: 10,
            groupId : 20,
            productId: 30,
            trainingsCount: -1,
            createdOn: new \DateTimeImmutable('2026-08-01'),
        );
    }
}