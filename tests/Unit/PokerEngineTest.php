<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../public/games/poker/engine.php';

class PokerEngineTest extends TestCase
{
    private function card($rank, $suit)
    {
        return $suit * 13 + ($rank - 2);
    }

    public function test_detects_royal_flush_as_ace_high_straight_flush()
    {
        $cards = [10, 11, 12, 13, 14, 2, 3];
        $ids = array_map(fn($rank) => $this->card($rank, 0), $cards);
        $score = poker_evaluate($ids);
        $this->assertSame([8, 14], $score);
    }

    public function test_wheel_straight_is_five_high()
    {
        $cards = [
            $this->card(14, 0), $this->card(2, 1), $this->card(3, 2),
            $this->card(4, 3), $this->card(5, 0), $this->card(13, 1), $this->card(9, 2),
        ];
        $this->assertSame([4, 5], poker_evaluate($cards));
    }

    public function test_full_house_uses_highest_trip_and_pair()
    {
        $cards = [
            $this->card(14, 0), $this->card(14, 1), $this->card(14, 2),
            $this->card(13, 0), $this->card(13, 1), $this->card(12, 0), $this->card(12, 1),
        ];
        $this->assertSame([6, 14, 13], poker_evaluate($cards));
    }

    public function test_kicker_breaks_one_pair_tie()
    {
        $pairAceKing = [1, 14, 13, 9];
        $pairAceQueen = [1, 14, 12, 11];
        $this->assertGreaterThan(0, poker_compare_scores($pairAceKing, $pairAceQueen));
    }

    public function test_rejects_duplicate_cards()
    {
        $this->expectException(\InvalidArgumentException::class);
        poker_evaluate([0, 0, 1, 2, 3, 4, 5]);
    }
}

