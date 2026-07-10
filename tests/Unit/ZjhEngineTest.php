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
}
