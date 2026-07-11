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

function zjh_action_cost($currentBet, $seen, $base = 0)
{
    $currentBet = max(0, (int)$currentBet);
    return $seen ? $currentBet * 2 + max(0, (int)$base) : $currentBet;
}

function zjh_requires_showdown($stack, $currentBet, $seen, $base = 0)
{
    return (int)$stack < zjh_action_cost($currentBet, $seen, $base);
}

function zjh_unrevealed_active_seats(array $players)
{
    $seats = [];
    foreach ($players as $seat => $player) {
        if (($player['status'] ?? '') === 'active' && empty($player['revealed'])) $seats[] = (int)$seat;
    }
    return $seats;
}

function zjh_may_view_cards($finished, $isViewer, $seen, $revealed)
{
    return (bool)$finished || (bool)$revealed || ((bool)$isViewer && (bool)$seen);
}

function zjh_card_view($card)
{
    $ranks = [2=>'2',3=>'3',4=>'4',5=>'5',6=>'6',7=>'7',8=>'8',9=>'9',10=>'10',11=>'J',12=>'Q',13=>'K',14=>'A'];
    $suits = ['♠', '♥', '♣', '♦'];
    $suit = zjh_card_suit($card);
    return ['rank' => $ranks[zjh_card_rank($card)], 'suit' => $suits[$suit], 'red' => in_array($suit, [1, 3], true)];
}

/** 可重复测试的机器人决策核心；只接收自身牌力和桌面公开压力。 */
function zjh_bot_decide($difficulty, $strength, $pressure, $canRaise, $activeCount, $roll, $hasRaiseStack = true)
{
    $difficulty = in_array($difficulty, ['simple', 'hard', 'hell'], true) ? $difficulty : 'simple';
    $strength = max(0, min(1, (float)$strength));
    $pressure = max(0, min(1, (float)$pressure));
    $roll = max(1, min(100, (int)$roll));
    if ($difficulty === 'simple') {
        if ($roll <= 18) return 'fold';
        if ($roll <= 68) return 'call';
        if ($roll <= 84 && $canRaise) return 'raise';
        return $activeCount > 1 ? 'compare' : 'call';
    }
    if ($difficulty === 'hard') {
        if ($strength < 0.27 + $pressure * 0.65 && $roll <= 72) return 'fold';
        if ($strength > 0.7 && $canRaise && $roll <= 48) return 'raise';
        if ($strength > 0.57 && $activeCount > 1 && $roll <= 36) return 'compare';
        return 'call';
    }
    $bluff = $strength < 0.32 && $pressure < 0.2 && $roll <= 16;
    if (($strength > 0.76 || $bluff) && $canRaise && $hasRaiseStack) return 'raise';
    if ($strength > 0.6 && $activeCount > 1 && ($activeCount === 2 || $roll <= 42)) return 'compare';
    if ($strength < 0.25 && $pressure > 0.12 && !$bluff) return 'fold';
    if ($strength < 0.38 && $pressure > 0.28 && $roll <= 76) return 'fold';
    return 'call';
}

/** 机器人只有在已经看牌，且牌型为对子或单张时才允许弃牌。 */
function zjh_bot_can_fold($seen, array $score)
{
    return (bool)$seen && (int)($score[0] ?? 0) <= 1;
}

/** 返回全比牌局中最大手牌的下标。 */
function zjh_compare_all_winner(array $hands)
{
    if (!$hands) throw new InvalidArgumentException('全比至少需要一副手牌');
    $winner = 0;
    $best = zjh_evaluate($hands[0]);
    foreach (array_slice($hands, 1, null, true) as $index => $cards) {
        $score = zjh_evaluate($cards);
        if (zjh_compare_scores($score, $best) > 0) {
            $winner = (int)$index;
            $best = $score;
        }
    }
    return $winner;
}
