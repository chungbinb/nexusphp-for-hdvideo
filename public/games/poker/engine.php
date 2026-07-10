<?php

/**
 * Texas Hold'em card/evaluation helpers.
 *
 * Cards are integers 0..51. Rank is 2..14 (Ace high), suit is 0..3.
 * Evaluation arrays sort lexicographically: category first, then kickers.
 */

function poker_card_rank($card)
{
    return ((int)$card % 13) + 2;
}

function poker_card_suit($card)
{
    return intdiv((int)$card, 13);
}

function poker_card_label($card)
{
    $ranks = [2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => '8', 9 => '9', 10 => '10', 11 => 'J', 12 => 'Q', 13 => 'K', 14 => 'A'];
    $suits = ['♠', '♥', '♣', '♦'];
    return $suits[poker_card_suit($card)] . $ranks[poker_card_rank($card)];
}

function poker_card_is_red($card)
{
    $suit = poker_card_suit($card);
    return $suit === 1 || $suit === 3;
}

function poker_straight_high($ranks)
{
    $ranks = array_values(array_unique(array_map('intval', $ranks)));
    rsort($ranks, SORT_NUMERIC);
    if (in_array(14, $ranks, true)) {
        $ranks[] = 1;
    }
    $run = 1;
    for ($i = 1, $n = count($ranks); $i < $n; $i++) {
        if ($ranks[$i - 1] - 1 === $ranks[$i]) {
            $run++;
            if ($run >= 5) {
                return $ranks[$i - 4];
            }
        } else {
            $run = 1;
        }
    }
    return 0;
}

function poker_evaluate_five($cards)
{
    if (count($cards) !== 5) {
        throw new InvalidArgumentException('Exactly five cards are required.');
    }
    $ranks = array_map('poker_card_rank', $cards);
    $suits = array_map('poker_card_suit', $cards);
    $counts = array_count_values($ranks);
    $groups = [];
    foreach ($counts as $rank => $count) {
        $groups[] = ['count' => (int)$count, 'rank' => (int)$rank];
    }
    usort($groups, function ($a, $b) {
        return $a['count'] === $b['count'] ? $b['rank'] <=> $a['rank'] : $b['count'] <=> $a['count'];
    });
    $flush = count(array_unique($suits)) === 1;
    $straightHigh = poker_straight_high($ranks);

    if ($flush && $straightHigh) return [8, $straightHigh];
    if ($groups[0]['count'] === 4) return [7, $groups[0]['rank'], $groups[1]['rank']];
    if ($groups[0]['count'] === 3 && $groups[1]['count'] === 2) return [6, $groups[0]['rank'], $groups[1]['rank']];
    if ($flush) {
        rsort($ranks, SORT_NUMERIC);
        return array_merge([5], $ranks);
    }
    if ($straightHigh) return [4, $straightHigh];
    if ($groups[0]['count'] === 3) {
        $kickers = [];
        foreach ($groups as $group) if ($group['count'] === 1) $kickers[] = $group['rank'];
        rsort($kickers, SORT_NUMERIC);
        return array_merge([3, $groups[0]['rank']], $kickers);
    }
    if ($groups[0]['count'] === 2 && $groups[1]['count'] === 2) {
        $highPair = max($groups[0]['rank'], $groups[1]['rank']);
        $lowPair = min($groups[0]['rank'], $groups[1]['rank']);
        return [2, $highPair, $lowPair, $groups[2]['rank']];
    }
    if ($groups[0]['count'] === 2) {
        $kickers = [];
        foreach ($groups as $group) if ($group['count'] === 1) $kickers[] = $group['rank'];
        rsort($kickers, SORT_NUMERIC);
        return array_merge([1, $groups[0]['rank']], $kickers);
    }
    rsort($ranks, SORT_NUMERIC);
    return array_merge([0], $ranks);
}

function poker_compare_scores($a, $b)
{
    $length = max(count($a), count($b));
    for ($i = 0; $i < $length; $i++) {
        $av = (int)($a[$i] ?? 0);
        $bv = (int)($b[$i] ?? 0);
        if ($av !== $bv) return $av <=> $bv;
    }
    return 0;
}

function poker_evaluate($cards)
{
    $originalCount = count($cards);
    $cards = array_values(array_unique(array_map('intval', $cards)));
    $n = count($cards);
    if ($n !== $originalCount || $n < 5 || $n > 7) {
        throw new InvalidArgumentException('Five to seven unique cards are required.');
    }
    $best = null;
    for ($a = 0; $a < $n - 4; $a++) {
        for ($b = $a + 1; $b < $n - 3; $b++) {
            for ($c = $b + 1; $c < $n - 2; $c++) {
                for ($d = $c + 1; $d < $n - 1; $d++) {
                    for ($e = $d + 1; $e < $n; $e++) {
                        $score = poker_evaluate_five([$cards[$a], $cards[$b], $cards[$c], $cards[$d], $cards[$e]]);
                        if ($best === null || poker_compare_scores($score, $best) > 0) $best = $score;
                    }
                }
            }
        }
    }
    return $best;
}

function poker_score_name($score)
{
    $names = ['高牌', '一对', '两对', '三条', '顺子', '同花', '葫芦', '四条', '同花顺'];
    return $names[(int)($score[0] ?? 0)] ?? '未知牌型';
}

function poker_preflop_strength($cards)
{
    if (count($cards) !== 2) return 0.0;
    $a = poker_card_rank($cards[0]);
    $b = poker_card_rank($cards[1]);
    $high = max($a, $b);
    $low = min($a, $b);
    $pair = $a === $b;
    $suited = poker_card_suit($cards[0]) === poker_card_suit($cards[1]);
    $gap = $high - $low;
    $score = ($high - 2) / 12 * 0.38 + ($low - 2) / 12 * 0.16;
    if ($pair) $score = 0.48 + ($high - 2) / 12 * 0.5;
    if ($suited) $score += 0.08;
    if ($gap === 1) $score += 0.07;
    elseif ($gap === 2) $score += 0.03;
    elseif ($gap >= 4 && $high < 12) $score -= 0.08;
    if ($high >= 13 && $low >= 10) $score += 0.08;
    return max(0.02, min(0.99, $score));
}
