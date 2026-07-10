<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../public/games/zjh/engine.php';

class ZjhEngineTest extends TestCase
{
    private function card($rank, $suit) { return $suit * 13 + ($rank - 2); }

    public function test_detects_three_of_a_kind()
    {
        $score = zjh_evaluate([$this->card(14, 0), $this->card(14, 1), $this->card(14, 2)]);
        $this->assertSame([5, 14], $score);
        $this->assertSame('豹子', zjh_score_name($score));
    }

    public function test_straight_flush_beats_flush()
    {
        $straightFlush = zjh_evaluate([$this->card(10, 0), $this->card(11, 0), $this->card(12, 0)]);
        $flush = zjh_evaluate([$this->card(14, 1), $this->card(10, 1), $this->card(4, 1)]);
        $this->assertGreaterThan(0, zjh_compare_scores($straightFlush, $flush));
    }

    public function test_a23_is_lowest_straight()
    {
        $wheel = zjh_evaluate([$this->card(14, 0), $this->card(2, 1), $this->card(3, 2)]);
        $fourHigh = zjh_evaluate([$this->card(2, 0), $this->card(3, 1), $this->card(4, 2)]);
        $this->assertSame([2, 3], $wheel);
        $this->assertGreaterThan(0, zjh_compare_scores($fourHigh, $wheel));
    }

    public function test_pair_kicker_breaks_tie()
    {
        $king = zjh_evaluate([$this->card(9, 0), $this->card(9, 1), $this->card(13, 2)]);
        $queen = zjh_evaluate([$this->card(9, 2), $this->card(9, 3), $this->card(12, 0)]);
        $this->assertGreaterThan(0, zjh_compare_scores($king, $queen));
    }

    public function test_rejects_duplicate_cards()
    {
        $this->expectException(\InvalidArgumentException::class);
        zjh_evaluate([0, 0, 1]);
    }

    public function test_simple_bot_is_intentionally_unpredictable()
    {
        $this->assertSame('fold', zjh_bot_decide('simple', 0.95, 0.01, true, 3, 10));
        $this->assertSame('raise', zjh_bot_decide('simple', 0.05, 0.8, true, 3, 75));
    }

    public function test_hell_bot_uses_strength_and_pressure_without_opponent_cards()
    {
        $this->assertSame('raise', zjh_bot_decide('hell', 0.9, 0.2, true, 3, 60, true));
        $this->assertSame('fold', zjh_bot_decide('hell', 0.15, 0.4, true, 3, 80, true));
        $this->assertSame('compare', zjh_bot_decide('hell', 0.7, 0.2, true, 2, 90, true));
    }

    public function test_compare_all_requires_challenger_to_beat_every_opponent()
    {
        $pairKings = [$this->card(13, 0), $this->card(13, 1), $this->card(8, 2)];
        $pairQueens = [$this->card(12, 0), $this->card(12, 1), $this->card(14, 2)];
        $pairAces = [$this->card(14, 0), $this->card(14, 1), $this->card(2, 2)];
        $this->assertSame(-1, zjh_compare_all_outcome($pairKings, [$pairQueens]));
        $this->assertSame(1, zjh_compare_all_outcome($pairKings, [$pairQueens, $pairAces]));
        $this->assertSame(0, zjh_compare_all_outcome($pairKings, [$pairKings]));
    }
}
