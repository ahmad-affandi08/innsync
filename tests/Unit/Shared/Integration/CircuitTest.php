<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Integration;

use App\Shared\Application\Integration\Circuit;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CircuitTest extends TestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-10-01 {$time}", new DateTimeZone('UTC'));
    }

    public function test_it_opens_only_when_consecutive_failures_reach_the_threshold(): void
    {
        $circuit = Circuit::closed();

        foreach ([1, 2] as $i) {
            $circuit = $circuit->afterFailure($this->at('03:00:00'), 3);
            self::assertFalse($circuit->isOpen(), "after {$i}");
        }

        self::assertTrue($circuit->afterFailure($this->at('03:00:00'), 3)->isOpen());
    }

    public function test_a_success_resets_the_count(): void
    {
        $circuit = Circuit::closed()->afterFailure($this->at('03:00:00'), 3)->afterFailure($this->at('03:00:00'), 3)->afterSuccess();

        self::assertSame(0, $circuit->consecutiveFailures);
        self::assertFalse($circuit->afterFailure($this->at('03:00:00'), 3)->isOpen());
    }

    public function test_an_open_circuit_admits_one_trial_per_window_and_a_failed_trial_reopens_it(): void
    {
        $open = Circuit::closed()->afterFailure($this->at('03:00:00'), 1);

        self::assertFalse($open->admits($this->at('03:00:59'), 60));
        self::assertTrue($open->admits($this->at('03:01:00'), 60));

        $trial = $open->withTrial($this->at('03:01:00'));
        self::assertFalse($trial->admits($this->at('03:01:30'), 60), 'a second caller during the trial is refused');
        self::assertTrue($trial->admits($this->at('03:02:00'), 60), 'a stuck trial is retried after another window');

        $reopened = $trial->afterFailure($this->at('03:01:05'), 5);
        self::assertTrue($reopened->isOpen());
        self::assertFalse($reopened->admits($this->at('03:01:30'), 60));
        self::assertFalse($trial->afterSuccess()->isOpen());
    }

    public function test_a_closed_circuit_always_admits(): void
    {
        self::assertTrue(Circuit::closed()->admits($this->at('03:00:00'), 60));
    }
}
