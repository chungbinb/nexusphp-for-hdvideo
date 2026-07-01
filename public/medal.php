<?php
require "../include/bittorrent.php";
dbconn();
require_once ROOT_PATH . 'include/mobile_shell.php';
loggedinorreturn();
$query = \App\Models\Medal::query()->where('display_on_medal_page', 1)
    ->orderBy('priority', 'desc')->orderBy("id", 'desc');
$q = htmlspecialchars($_REQUEST['q'] ?? '');
if (!empty($q)) {
    $query->where('username', 'name', "%{$q}%");
}
$total = (clone $query)->count();
$perPage = 20;
list($paginationTop, $paginationBottom, $limit, $offset) = pager($perPage, $total, "?");
$rows = (clone $query)->offset($offset)->take($perPage)->orderBy('id', 'desc')->get();
$q = htmlspecialchars($q);
$title = nexus_trans('medal.label');
$columnNameLabel = nexus_trans('label.name');
$columnImageLargeLabel = nexus_trans('medal.fields.image_large');
$columnPriceLabel = nexus_trans('medal.fields.price');
$columnDurationLabel = nexus_trans('medal.fields.duration');
$columnDescriptionLabel = nexus_trans('medal.fields.description');
$columnBuyLabel = nexus_trans('medal.buy_btn');
$columnSaleBeginEndTimeLabel = nexus_trans('medal.fields.sale_begin_end_time');
$columnInventoryLabel = nexus_trans('medal.fields.inventory');
$columnBonusAdditionLabel = nexus_trans('medal.fields.bonus_addition');
$columnGiftLabel = nexus_trans('medal.gift_btn');
$columnGiftFeeLabel = nexus_trans('medal.fields.gift_fee');
$header = '<h1 style="text-align: center">'.$title.'</h1>';
$filterForm = <<<FORM
<div>
    <form id="filterForm" action="{$_SERVER['REQUEST_URI']}" method="get">
        <input id="q" type="text" name="q" value="{$q}" placeholder="username">
        <input type="submit">
        <input type="reset" onclick="document.getElementById('q').value='';document.getElementById('filterForm').submit();">
    </form>
</div>
FORM;
mp_head($title);
begin_main_frame();
$table = <<<TABLE
<table border="1" cellspacing="0" cellpadding="5" width="100%">
<thead>
<tr>
<td class="colhead">ID</td>
<td class="colhead">$columnImageLargeLabel</td>
<td class="colhead">$columnDescriptionLabel</td>
<td class="colhead" style="width: 115px">$columnSaleBeginEndTimeLabel</td>
<td class="colhead">$columnDurationLabel</td>
<td class="colhead">$columnBonusAdditionLabel</td>
<td class="colhead">$columnPriceLabel</td>
<td class="colhead">$columnInventoryLabel</td>
<td class="colhead">$columnBuyLabel</td>
<td class="colhead">$columnGiftLabel</td>
</tr>
</thead>
TABLE;
$now = now();
$user = \App\Models\User::query()->findOrFail($CURUSER['id']);
$table .= '<tbody>';
$userMedals = $user->valid_medals->keyBy('id');
foreach ($rows as $row) {
    $buyDisabled = $giftDisabled = ' disabled';
    $buyClass = $giftClass = '';
    try {
        $row->checkCanBeBuy();
        if ($userMedals->has($row->id)) {
            $buyBtnText = nexus_trans('medal.buy_already');
        } elseif ($CURUSER['seedbonus'] < $row->price) {
            $buyBtnText = nexus_trans('medal.require_more_bonus');
        } else {
            $buyBtnText = nexus_trans('medal.buy_btn');
            $buyDisabled = '';
            $buyClass = 'buy';
        }
        if ($CURUSER['seedbonus'] < $row->price * (1 + ($row->gift_fee_factor ?? 0))) {
            $giftBtnText = nexus_trans('medal.require_more_bonus');
        } else {
            $giftBtnText = nexus_trans('medal.gift_btn');
            $giftDisabled = '';
            $giftClass = 'gift';
        }
    } catch (\Exception $exception) {
        $buyBtnText = $giftBtnText = $exception->getMessage();
    }
    $buyAction = sprintf(
        '<input type="button" class="%s" data-id="%s" value="%s"%s>',
        $buyClass, $row->id, $buyBtnText, $buyDisabled
    );
    $giftAction = sprintf(
        '<input type="number" class="uid" %s style="width: 60px" placeholder="UID"><input type="button" class="%s" data-id="%s" value="%s"%s><span class="nowrap">%s: %s</span>',
         $giftDisabled, $giftClass, $row->id, $giftBtnText, $giftDisabled, $columnGiftFeeLabel, (($row->gift_fee_factor ?? 0) * 100).'%'
    );
    $table .= sprintf(
        '<tr><td class="md-id">%s</td><td class="md-img"><img src="%s" style="max-width: 60px;max-height: 60px;" class="preview" /></td><td class="md-desc"><h1>%s</h1>%s</td><td data-label="' . htmlspecialchars($columnSaleBeginEndTimeLabel, ENT_QUOTES) . '">%s ~<br>%s</td><td data-label="' . htmlspecialchars($columnDurationLabel, ENT_QUOTES) . '">%s</td><td data-label="' . htmlspecialchars($columnBonusAdditionLabel, ENT_QUOTES) . '">%s</td><td data-label="' . htmlspecialchars($columnPriceLabel, ENT_QUOTES) . '">%s</td><td data-label="' . htmlspecialchars($columnInventoryLabel, ENT_QUOTES) . '">%s</td><td class="md-buy" data-label="' . htmlspecialchars($columnBuyLabel, ENT_QUOTES) . '">%s</td><td class="md-gift" data-label="' . htmlspecialchars($columnGiftLabel, ENT_QUOTES) . '">%s</td>',
        $row->id,$row->image_large, $row->name, $row->description, $row->sale_begin_time ?? nexus_trans('nexus.no_limit'), $row->sale_end_time ?? nexus_trans('nexus.no_limit'), $row->durationText, (($row->bonus_addition_factor ?? 0) * 100).'%', number_format($row->price),  $row->inventory ?? nexus_trans('label.infinite'), $buyAction, $giftAction
    );
}
$table .= '</tbody></table>';
echo $header . $table . $paginationBottom;
end_main_frame();
$confirmBuyMsg = nexus_trans('medal.confirm_to_buy');
$confirmGiftMsg = nexus_trans('medal.confirm_to_gift');
$js = <<<JS
jQuery('.buy').on('click', function (e) {
    let medalId = jQuery(this).attr('data-id')
    layer.confirm("{$confirmBuyMsg}", function (index) {
        let params = {
            action: "buyMedal",
            params: {medal_id: medalId}
        }
        console.log(params)
        jQuery.post('ajax.php', params, function(response) {
            console.log(response)
            if (response.ret != 0) {
                layer.alert(response.msg)
                return
            }
            window.location.reload()
        }, 'json')
    })
})
jQuery('.gift').on('click', function (e) {
    let medalId = jQuery(this).attr('data-id')
    let uid = jQuery(this).prev().val()
    if (!uid) {
        layer.alert('Require UID')
        return
    }
    layer.confirm("{$confirmGiftMsg}" + uid + " ?", function (index) {
        let params = {
            action: "giftMedal",
            params: {medal_id: medalId, uid: uid}
        }
        console.log(params)
        jQuery.post('ajax.php', params, function(response) {
            console.log(response)
            if (response.ret != 0) {
                layer.alert(response.msg)
                return
            }
            window.location.reload()
        }, 'json')
    })
})
JS;
\Nexus\Nexus::js($js, 'footer', false);
mp_foot();

