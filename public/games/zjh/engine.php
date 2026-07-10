<?php

/** 炸金花三张牌牌型：豹子 > 同花顺 > 金花 > 顺子 > 对子 > 单张。 */
function zjh_card_rank($card)
{
    $card = (int)$card;
    if ($card < 0 || $card > 51) throw new InvalidArgumentException('Invalid card.');
    return ($card % 13) + 2;
}

function zjh_card_suit($card)
{
    $card = (int)$card;
    if ($card < 0 || $card > 51) throw new InvalidArgumentException('Invalid card.');
    return intdiv($card, 13);
}

function zjh_evaluate(array $cards)
{
    if (count($cards) !== 3 || count(array_unique($cards)) !== 3) {
        throw new InvalidArgumentException('Exactly three unique cards are required.');
    }
    $ranks = array_map('zjh_card_rank', $cards);
    rsort($ranks, SORT_NUMERIC);
    $counts = array_count_values($ranks);
    arsort($counts, SORT_NUMERIC);
    $flush = count(array_unique(array_map('zjh_card_suit', $cards))) === 1;
    $straightHigh = 0;
    $unique = array_values(array_unique($ranks));
    if (count($unique) === 3) {
        if ($unique[0] - $unique[2] === 2) $straightHigh = $unique[0];
        elseif ($unique === [14, 3, 2]) $straightHigh = 3; // A23 为最小顺子
    }
    if (max($counts) === 3) return [5, $ranks[0]];
    if ($flush && $straightHigh) return [4, $straightHigh];
    if ($flush) return array_merge([3], $ranks);
    if ($straightHigh) return [2, $straightHigh];
    if (max($counts) === 2) {
        $pair = 0; $kicker = 0;
        foreach ($counts as $rank => $count) {
            if ($count === 2) $pair = (int)$rank; else $kicker = (int)$rank;
        }
        return [1, $pair, $kicker];
    }
    return array_merge([0], $ranks);
}

function zjh_compare_scores(array $a, array $b)
{
    $length = max(count($a), count($b));
    for ($i = 0; $i < $length; $i++) {
        $left = $a[$i] ?? 0; $right = $b[$i] ?? 0;
        if ($left !== $right) return $left <=> $right;
    }
    return 0;
}

function zjh_score_name(array $score)
{
    return ['单张', '对子', '顺子', '金花', '同花顺', '豹子'][$score[0] ?? 0] ?? '未知牌型';
}

function zjh_card_view($card)
{
    $ranks = [2=>'2',3=>'3',4=>'4',5=>'5',6=>'6',7=>'7',8=>'8',9=>'9',10=>'10',11=>'J',12=>'Q',13=>'K',14=>'A'];
    $suits = ['♠', '♥', '♣', '♦'];
    $suit = zjh_card_suit($card);
    return ['rank' => $ranks[zjh_card_rank($card)], 'suit' => $suits[$suit], 'red' => in_array($suit, [1, 3], true)];
}
