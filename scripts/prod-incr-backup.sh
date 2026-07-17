#!/bin/bash
# 生产增量快照备份(rsync --link-dest 硬链接方案)
# 每天生成一个完整快照目录,未变化文件用硬链接指向前一天,磁盘只占"一份全量+每日增量"。
# 恢复方法:直接整目录拷回,如 cp -a /www/backup/incr/torrents/2026-07-17/. /www/wwwroot/hdvideo.top/torrents/
# 建议用宝塔计划任务(Shell脚本, root, 每天 01:30)执行:bash /www/backup/scripts/prod-incr-backup.sh
set -uo pipefail

ROOT=/www/backup/incr
KEEP_DAYS=30
DATE=$(date +%F)

mkdir -p "$ROOT"
exec >> "$ROOT/backup.log" 2>&1
echo "===== $(date '+%F %T') backup start ====="

snap() {
    local name="$1" src="$2"
    [ -d "$src" ] || { echo "[skip] $src not found"; return 0; }
    local dest="$ROOT/$name"
    mkdir -p "$dest"
    local args=(-a --delete)
    [ -d "$dest/latest" ] && args+=(--link-dest="$dest/latest")
    rm -rf "$dest/$DATE.part"
    if rsync "${args[@]}" "$src/" "$dest/$DATE.part/"; then
        rm -rf "$dest/$DATE"
        mv "$dest/$DATE.part" "$dest/$DATE"
        ln -sfn "$dest/$DATE" "$dest/latest"
        echo "[ok] $name -> $DATE"
    else
        echo "[FAIL] $name rsync error, snapshot kept as $DATE.part"
    fi
    find "$dest" -maxdepth 1 -type d -name '20??-*' -mtime +"$KEEP_DAYS" -exec rm -rf {} +
}

# Git 仓库之外的用户数据(站点代码走 GitHub 同步,无需备份)
snap torrents    /www/wwwroot/hdvideo.top/torrents
snap attachments /www/wwwroot/hdvideo.top/attachments
snap bitbucket   /www/wwwroot/hdvideo.top/bitbucket
snap subs        /www/wwwroot/hdvideo.top/subs
snap img-site    /www/wwwroot/img.hdvideo.top

# .env 单独留档(含密钥,600 权限)
if [ -f /www/wwwroot/hdvideo.top/.env ]; then
    install -D -m 600 /www/wwwroot/hdvideo.top/.env "$ROOT/env/hdvideo.top.env.$DATE"
    find "$ROOT/env" -type f -mtime +"$KEEP_DAYS" -delete 2>/dev/null
fi

du -sh "$ROOT" 2>/dev/null | awk '{print "incr total on disk: " $1}'
df -h / | tail -1
echo "===== $(date '+%F %T') backup done ====="
