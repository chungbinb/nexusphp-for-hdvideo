<?php
require "../../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();

$GLOBALS['nexus_base_href'] = get_protocol_prefix() . $BASEURL . '/';
$GLOBALS['nexus_hide_top_banner'] = true;
$isMobile = preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry|webOS|HarmonyOS/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1;
$hallUrl = $isMobile ? '/games/' : '/games/?pc=1';

if ($isMobile) {
    require_once ROOT_PATH . 'include/mobile_shell.php';
    mobile_shell_page_head('游戏大厅规则', 'games', 'page-games');
} else {
    stdhead('游戏大厅规则');
}
?>
<style>
body:not(.inframe) { background: #0d1824 !important; }
.ghr { max-width: 1120px; margin: 0 auto; padding: 14px 18px 42px; color: #dce8f6; }
.ghr a { color: inherit !important; }
.ghr-tabs { display: flex; flex-wrap: wrap; gap: 28px; margin: 2px 0 22px; color: #9eb4ca; font-size: 18px; }
.ghr-tab { position: relative; padding-bottom: 8px; color: inherit !important; text-decoration: none !important; }
.ghr-tab.is-active { color: #fff !important; }
.ghr-tab.is-active::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; border-radius: 3px; background: var(--bili-primary, #35b8f1); }
.ghr-hero { position: relative; overflow: hidden; padding: 30px; border: 1px solid rgba(91,129,166,.28); border-radius: 16px; background: linear-gradient(135deg, #19324a, #142334 54%, #173d36); box-shadow: 0 18px 45px rgba(0,0,0,.2); }
.ghr-hero::after { content: ""; position: absolute; width: 260px; height: 260px; top: -150px; right: -70px; border: 38px solid rgba(53,184,241,.08); border-radius: 50%; }
.ghr-kicker { color: #75cef5; font-size: 13px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.ghr-hero h1 { position: relative; z-index: 1; margin: 8px 0 10px !important; color: #fff !important; font-size: clamp(28px, 4vw, 44px); line-height: 1.16; }
.ghr-version { display: inline-flex; align-items: center; min-height: 28px; padding: 0 10px; border: 1px solid rgba(117,206,245,.38); border-radius: 999px; background: rgba(10,29,44,.46); color: #bce9ff; font-size: 12px; font-weight: 800; vertical-align: middle; }
.ghr-lead { position: relative; z-index: 1; max-width: 780px; margin: 0; color: #b7cada; font-size: 15px; line-height: 1.8; }
.ghr-toc { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 9px; margin: 16px 0 0; }
.ghr-toc a { display: flex; align-items: center; min-height: 42px; padding: 0 12px; border: 1px solid rgba(91,129,166,.24); border-radius: 8px; background: #162636; color: #c7d8e8 !important; text-decoration: none !important; transition: border-color .18s ease, background .18s ease; }
.ghr-toc a:hover { border-color: rgba(53,184,241,.55); background: #20384d; }
.ghr-toc a:focus-visible, .ghr-tab:focus-visible, .ghr-back:focus-visible { outline: 3px solid rgba(53,184,241,.45); outline-offset: 2px; }
.ghr-chapter { scroll-margin-top: 18px; margin-top: 18px; padding: 22px 24px; border: 1px solid rgba(91,129,166,.22); border-radius: 14px; background: #162636; }
.ghr-chapter > h2 { margin: 0 0 4px !important; color: #fff !important; font-size: 23px; }
.ghr-article { padding: 18px 0; border-top: 1px solid rgba(91,129,166,.18); }
.ghr-chapter > h2 + .ghr-article { margin-top: 12px; }
.ghr-article h3 { margin: 0 0 9px !important; color: #75cef5 !important; font-size: 17px; }
.ghr-article p { margin: 7px 0; color: #c9d8e6; line-height: 1.85; }
.ghr-article ul { margin: 10px 0 4px; padding-left: 22px; color: #c9d8e6; }
.ghr-article li { margin: 7px 0; line-height: 1.65; }
.ghr-article strong { color: #fff; }
.ghr-penalties { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 10px; margin-top: 12px; }
.ghr-penalty { padding: 14px; border: 1px solid rgba(91,129,166,.2); border-radius: 9px; background: #101f2d; }
.ghr-penalty h4 { margin: 0 0 8px; color: #fff; font-size: 15px; }
.ghr-penalty ul { margin-bottom: 0; }
.ghr-callout { margin-top: 14px; padding: 13px 15px; border-left: 4px solid #e3a83b; border-radius: 0 8px 8px 0; background: rgba(227,168,59,.1); color: #f2d79f; line-height: 1.7; }
.ghr-table-wrap { margin-top: 14px; overflow-x: auto; border: 1px solid rgba(91,129,166,.24); border-radius: 10px; }
.ghr-table { width: 100%; min-width: 680px; border-collapse: collapse; background: #101f2d; }
.ghr-table caption { padding: 12px 14px; background: #1b3449; color: #fff; font-weight: 800; text-align: left; }
.ghr-table th, .ghr-table td { padding: 12px 14px; border-top: 1px solid rgba(91,129,166,.2); color: #c9d8e6; text-align: left; vertical-align: top; }
.ghr-table th { width: 32%; color: #fff; }
.ghr-back-row { display: flex; justify-content: center; margin-top: 22px; }
.ghr-back { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 0 22px; border-radius: 8px; background: var(--bili-primary, #278ac1); color: #fff !important; font-weight: 800; text-decoration: none !important; }
@media (max-width: 760px) {
    .ghr { padding: 8px 12px 28px; }
    .ghr-tabs { gap: 20px; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; font-size: 16px; scrollbar-width: none; }
    .ghr-tabs::-webkit-scrollbar { display: none; }
    .ghr-tab { white-space: nowrap; }
    .ghr-hero { padding: 22px 18px; border-radius: 13px; }
    .ghr-toc { grid-template-columns: repeat(2, minmax(0,1fr)); }
    .ghr-chapter { padding: 18px 16px; }
    .ghr-penalties { grid-template-columns: 1fr; }
}
@media (max-width: 420px) {
    .ghr-toc { grid-template-columns: 1fr; }
    .ghr-hero h1 { font-size: 27px; }
}
@media (prefers-reduced-motion: reduce) { .ghr-toc a { transition: none; } }
</style>

<main class="ghr">
    <nav class="ghr-tabs" aria-label="游戏大厅导航">
        <a class="ghr-tab" href="<?php echo htmlspecialchars($hallUrl) ?>#game-list">游戏列表</a>
        <a class="ghr-tab is-active" href="#rules-top" aria-current="page">游戏规则</a>
        <a class="ghr-tab" href="<?php echo htmlspecialchars($hallUrl) ?>#coming-soon">即将推出</a>
    </nav>

    <header class="ghr-hero" id="rules-top">
        <div class="ghr-kicker">HDV Game Hall Policy</div>
        <h1>🎮 HDV 游戏大厅规则 <span class="ghr-version">V1.0</span></h1>
        <p class="ghr-lead">本规则适用于 HDV 游戏大厅全部游戏。参与游戏即表示用户已阅读并同意本规则，请理性娱乐并遵守站点秩序。</p>
    </header>

    <nav class="ghr-toc" aria-label="规则章节目录">
        <a href="#chapter-1">第一章 总则</a><a href="#chapter-2">第二章 参与规则</a>
        <a href="#chapter-3">第三章 游戏限制</a><a href="#chapter-4">第四章 禁止行为</a>
        <a href="#chapter-5">第五章 风险控制</a><a href="#chapter-6">第六章 违规处罚</a>
        <a href="#chapter-7">第七章 游戏说明</a><a href="#chapter-8">第八章 特殊说明</a>
        <a href="#anti-abuse">统一防刷限制</a><a href="#cooldown">冷却保护建议</a>
    </nav>

    <section class="ghr-chapter" id="chapter-1">
        <h2>第一章 总则</h2>
        <article class="ghr-article"><h3>第一条</h3><p>HDV 游戏大厅（以下简称“游戏大厅”）为站内娱乐系统，所有游戏均使用站内虚拟资产（魔力、电影票等）进行娱乐，不具有任何现实货币价值。</p><p>游戏大厅旨在丰富站点玩法，提高用户互动性，请理性参与。</p></article>
        <article class="ghr-article"><h3>第二条</h3><p>所有游戏均采用服务器随机算法生成结果。</p><p>任何用户不得以任何方式干预、修改、预测、破解或利用游戏结果。</p><p>服务器开奖结果为最终结果。</p></article>
        <article class="ghr-article"><h3>第三条</h3><p>参与游戏即表示用户已阅读并同意本规则。</p><p>所有游戏均遵循公平、公正、公开原则。</p><p>HDV 保留调整游戏玩法、赔率、奖励、概率及开放时间的权利。</p></article>
    </section>

    <section class="ghr-chapter" id="chapter-2">
        <h2>第二章 参与规则</h2>
        <article class="ghr-article"><h3>第四条</h3><p>游戏大厅开放对象：</p><ul><li>注册用户</li><li>未被封禁账号</li><li>未被限制游戏权限账号</li></ul><p>管理员可根据运营情况限制部分账号参与。</p></article>
        <article class="ghr-article"><h3>第五条</h3><p>游戏消耗资产包括：</p><ul><li>电影票（目前）</li><li>游戏币（如有后续开放）</li></ul><p>不同游戏使用不同资产，具体以游戏页面显示为准。</p></article>
        <article class="ghr-article"><h3>第六条</h3><p>游戏奖励包括：</p><ul><li>电影票</li><li>上传</li><li>下载</li><li>邀请</li><li>VIP 等</li></ul><p>奖励实时发放。</p><p>若因系统异常导致发放错误，HDV 有权追回异常奖励。</p></article>
    </section>

    <section class="ghr-chapter" id="chapter-3">
        <h2>第三章 游戏限制</h2>
        <article class="ghr-article"><h3>第七条</h3><p>为保证服务器稳定，系统将限制游戏频率，包括但不限于：</p><ul><li>单次点击间隔限制</li><li>每分钟参与次数限制</li><li>每小时参与次数限制</li><li>每日参与次数限制</li></ul><p>具体限制由系统自动控制。</p></article>
        <article class="ghr-article"><h3>第八条</h3><p>部分游戏设有：</p><ul><li>最低投注</li><li>单次最高投注</li><li>每日最高投注</li><li>每日最高中奖额度</li></ul><p>超过限制后系统将自动拒绝参与。</p></article>
        <article class="ghr-article"><h3>第九条</h3><p>游戏奖励可能受以下因素影响：</p><ul><li>VIP 等级</li><li>活动状态</li><li>特殊节日</li><li>系统倍率</li></ul><p>最终奖励以系统实际结算为准。</p></article>
    </section>

    <section class="ghr-chapter" id="chapter-4">
        <h2>第四章 禁止行为</h2>
        <article class="ghr-article"><h3>第十条</h3><p>严禁使用任何自动化程序参与游戏，包括但不限于：</p><ul><li>自动点击器、脚本程序、浏览器插件、宏程序</li><li>Selenium、Playwright、Puppeteer、Tampermonkey、AutoHotkey</li><li>Python 脚本、API 调用、模拟器、机器人（Bot）</li><li>其他自动化工具</li></ul></article>
        <article class="ghr-article"><h3>第十一条</h3><p>严禁通过任何技术手段影响游戏结果，包括但不限于：</p><ul><li>修改请求数据、重放请求、篡改参数</li><li>修改 Cookie、修改 Token、模拟服务器请求</li><li>抓包作弊、修改浏览器环境</li><li>利用缓存漏洞、逻辑漏洞、概率漏洞</li><li>利用网络延迟重复领奖</li></ul></article>
        <article class="ghr-article"><h3>第十二条</h3><p>禁止利用任何方式恶意获利，包括：</p><ul><li>无限领奖、无限抽奖、无限领取奖励</li><li>无限刷魔力、无限刷电影票、无限刷积分</li><li>无限刷排行榜</li></ul><p>所有异常收益均可追回。</p></article>
        <article class="ghr-article"><h3>第十三条</h3><p>严禁：</p><ul><li>多账号对刷、多账号刷奖励、多账号对刷榜</li><li>多账号转移奖励</li><li>恶意套利</li><li>利用关联账号参与活动</li></ul><p>一经发现，将按作弊处理。</p></article>
    </section>

    <section class="ghr-chapter" id="chapter-5">
        <h2>第五章 风险控制</h2>
        <article class="ghr-article"><h3>第十四条</h3><p>系统将自动检测：</p><ul><li>点击频率、请求频率、请求间隔</li><li>浏览器指纹、登录环境、IP 地址、User-Agent</li><li>操作行为、鼠标轨迹、页面停留时间</li><li>服务器日志</li></ul><p>若系统判定存在异常行为，可自动限制游戏资格。</p></article>
        <article class="ghr-article"><h3>第十五条</h3><p>系统可自动执行：</p><ul><li>暂停游戏</li><li>限制投注</li><li>暂停领奖</li><li>冻结奖励</li><li>人工审核</li><li>回收异常奖励</li></ul><p>无需另行通知。</p></article>
    </section>

    <section class="ghr-chapter" id="chapter-6">
        <h2>第六章 违规处罚</h2>
        <article class="ghr-article"><h3>第十六条</h3><p>根据违规程度，可采取以下处罚：</p>
            <div class="ghr-penalties">
                <div class="ghr-penalty"><h4>一级处罚</h4><ul><li>警告</li><li>暂停游戏 24 小时</li></ul></div>
                <div class="ghr-penalty"><h4>二级处罚</h4><ul><li>回收全部异常收益</li><li>扣除违规所得</li><li>扣除魔力值</li><li>扣除电影票</li></ul></div>
                <div class="ghr-penalty"><h4>三级处罚</h4><ul><li>禁止参与全部游戏</li><li>清空游戏积分</li><li>清空排行榜数据</li></ul></div>
                <div class="ghr-penalty"><h4>四级处罚</h4><ul><li>永久取消游戏资格</li><li>永久封禁账号</li><li>封禁关联账号</li></ul></div>
            </div>
        </article>
    </section>

    <section class="ghr-chapter" id="chapter-7">
        <h2>第七章 游戏说明</h2>
        <article class="ghr-article"><h3>第十七条</h3><p>游戏开奖结果均为随机结果。</p><p>HDV 不保证任何用户能够获奖。</p><p>任何连续中奖或连续未中奖均属于随机事件。</p><p>请理性娱乐。</p></article>
        <article class="ghr-article"><h3>第十八条</h3><p>如因以下情况导致游戏异常：</p><ul><li>系统维护</li><li>网络异常</li><li>数据库异常</li><li>服务器异常</li><li>程序更新</li></ul><p>HDV 有权回滚数据、重置游戏、撤销异常奖励或重新结算。</p></article>
    </section>

    <section class="ghr-chapter" id="chapter-8">
        <h2>第八章 特殊说明</h2>
        <article class="ghr-article"><h3>第十九条</h3><p>若用户利用任何漏洞、程序缺陷、脚本工具、自动化程序或其他非正常方式获取游戏奖励，HDV 有权追回全部奖励，并依据站点规则采取处罚。</p></article>
        <article class="ghr-article"><h3>第二十条</h3><p>游戏大厅所有解释权归 HDV 所有。</p><p>HDV 有权根据运营情况调整：</p><ul><li>游戏规则</li><li>奖励</li><li>概率</li><li>倍率</li><li>消耗</li><li>开放时间</li><li>参与条件</li></ul><p>恕不另行通知。</p></article>
    </section>

    <section class="ghr-chapter" id="anti-abuse">
        <h2>统一防刷限制</h2>
        <p class="ghr-callout">以下为游戏大厅统一建议值，具体限制可按游戏实际配置调整，并以系统当前生效设置为准。</p>
        <div class="ghr-table-wrap">
            <table class="ghr-table">
                <caption>防止用户利用脚本刷游戏的统一限制建议</caption>
                <thead><tr><th scope="col">项目</th><th scope="col">建议值</th></tr></thead>
                <tbody>
                    <tr><th scope="row">两次游戏最小间隔</th><td>1 秒</td></tr>
                    <tr><th scope="row">每分钟最多游戏</th><td>30 次</td></tr>
                    <tr><th scope="row">每小时最多游戏</th><td>500 次</td></tr>
                    <tr><th scope="row">每日最高游戏次数</th><td>5000 次（按游戏可调整）</td></tr>
                    <tr><th scope="row">单次最高投注</th><td>不超过用户资产的 10%</td></tr>
                    <tr><th scope="row">每日最高中奖</th><td>根据游戏设置上限（如 500 万魔力）</td></tr>
                    <tr><th scope="row">单日最高净盈利</th><td>超出部分进入人工审核</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="ghr-chapter" id="cooldown">
        <h2>冷却保护建议</h2>
        <article class="ghr-article">
            <p>为了减少冲动投注和脚本连续请求，建议加入：</p>
            <ul><li>连续游戏 <strong>100 次</strong>，强制休息 <strong>30 秒</strong>。</li><li>连续游戏 <strong>500 次</strong>，强制休息 <strong>5 分钟</strong>。</li><li>单日累计游戏达到设定次数后，系统可限制继续参与或降低投注上限。</li><li>游戏过程中若检测到异常点击频率或固定请求间隔，可立即暂停游戏资格并进入人工审核。</li></ul>
            <p>这样既能降低服务器压力，又能有效遏制自动化脚本连续刷取奖励，同时保持正常用户的游戏体验。</p>
        </article>
    </section>

    <div class="ghr-back-row"><a class="ghr-back" href="<?php echo htmlspecialchars($hallUrl) ?>">返回游戏大厅</a></div>
</main>
<?php
if ($isMobile) {
    mobile_shell_page_foot('games');
} else {
    stdfoot();
}
?>
