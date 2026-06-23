<?php

namespace App\Console\Commands;

use App\Models\Torrent;
use App\Models\TorrentExtra;
use Illuminate\Console\Command;
use Nexus\PTGen\PTGen;
use Throwable;

class FillTorrentPtGenRating extends Command
{
    protected $signature = 'torrent:fill_pt_gen_rating
        {--limit=200 : Maximum candidate torrents to process in one run}
        {--scan-limit=5000 : Maximum torrents to scan in one run}
        {--order=desc : Scan order by torrent id, asc or desc}
        {--begin_id= : Minimum torrent id}
        {--end_id= : Maximum torrent id}
        {--sleep=1 : Seconds to sleep after each updated torrent}
        {--force : Ignore __updated_at and refresh again}
        {--include-url : Also create pt_gen from torrents.url when torrent_extras.pt_gen is empty}
        {--dry-run : Only print candidates without updating}';

    protected $description = 'Fill missing PT-Gen rating cache for torrents in batches.';

    public function handle(): int
    {
        $limit = max(1, (int)$this->option('limit'));
        $scanLimit = max(1, (int)$this->option('scan-limit'));
        $order = strtolower((string)$this->option('order')) === 'asc' ? 'asc' : 'desc';
        $sleep = max(0, (int)$this->option('sleep'));
        $beginId = $this->option('begin_id') !== null ? (int)$this->option('begin_id') : null;
        $endId = $this->option('end_id') !== null ? (int)$this->option('end_id') : null;
        $force = (bool)$this->option('force');
        $includeUrl = (bool)$this->option('include-url');
        $dryRun = (bool)$this->option('dry-run');

        $ptGen = new PTGen();
        $scanned = 0;
        $candidates = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        $query = Torrent::query()
            ->with('extra')
            ->where('visible', 'yes')
            ->where('banned', 'no')
            ->orderBy('id', $order);

        if ($beginId !== null) {
            $query->where('id', '>=', $beginId);
        }
        if ($endId !== null) {
            $query->where('id', '<=', $endId);
        }

        foreach ($query->cursor() as $torrent) {
            if ($scanned >= $scanLimit || $candidates >= $limit) {
                break;
            }
            $scanned++;

            if (!$this->shouldProcess($torrent, $force, $includeUrl, $ptGen)) {
                $skipped++;
                continue;
            }

            $candidates++;
            $this->line(sprintf('candidate torrent_id=%s name=%s', $torrent->id, $torrent->name));

            if ($dryRun) {
                continue;
            }

            try {
                $this->preparePtGenFromUrl($torrent, $includeUrl);
                $result = $ptGen->updateTorrentPtGen((int)$torrent->id, $force);
                if ($result === false) {
                    $skipped++;
                    continue;
                }
                $updated++;
                $this->info(sprintf('updated torrent_id=%s', $torrent->id));
            } catch (Throwable $throwable) {
                $failed++;
                $this->error(sprintf('failed torrent_id=%s: %s', $torrent->id, $throwable->getMessage()));
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        $this->info(sprintf(
            'done scanned=%d candidates=%d updated=%d skipped=%d failed=%d',
            $scanned,
            $candidates,
            $updated,
            $skipped,
            $failed
        ));

        if ($candidates === 0 && !$includeUrl) {
            $this->warn('No candidates found. If torrents only have IMDb/Douban URL but no pt_gen data, rerun with --include-url.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function shouldProcess(Torrent $torrent, bool $force, bool $includeUrl, PTGen $ptGen): bool
    {
        $extra = $torrent->extra;
        $ptGenInfo = $extra ? $extra->pt_gen : null;

        if ($force && $this->hasPtGenLink($ptGenInfo, $ptGen)) {
            return true;
        }

        if (is_array($ptGenInfo)) {
            foreach (PTGen::$validSites as $site => $siteInfo) {
                if (!isset($ptGenInfo[$site])) {
                    continue;
                }
                $siteEntry = $ptGenInfo[$site];
                $data = is_array($siteEntry) && isset($siteEntry['data']) && is_array($siteEntry['data'])
                    ? $siteEntry['data']
                    : [];
                if (!isset($data['__rating']) || $data['__rating'] === '') {
                    return true;
                }
            }

            return $force && $this->hasPtGenLink($ptGenInfo, $ptGen);
        }

        if (is_string($ptGenInfo) && trim($ptGenInfo) !== '') {
            return true;
        }

        return $includeUrl && is_string($torrent->url) && trim($torrent->url) !== '';
    }

    private function hasPtGenLink($ptGenInfo, PTGen $ptGen): bool
    {
        if (is_array($ptGenInfo)) {
            return $ptGen->getLink($ptGenInfo) !== '';
        }

        return is_string($ptGenInfo) && trim($ptGenInfo) !== '';
    }

    private function preparePtGenFromUrl(Torrent $torrent, bool $includeUrl): void
    {
        if (!$includeUrl || empty($torrent->url)) {
            return;
        }

        $extra = $torrent->extra;
        if ($extra && !empty($extra->pt_gen)) {
            return;
        }

        TorrentExtra::query()->updateOrCreate(
            ['torrent_id' => $torrent->id],
            ['pt_gen' => trim((string)$torrent->url)]
        );
    }
}
