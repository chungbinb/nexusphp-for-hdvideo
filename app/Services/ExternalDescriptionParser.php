<?php

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

class ExternalDescriptionParser
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 12,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            ],
        ]);
    }

    public function parse(string $doubanUrl = '', string $imdbUrl = '', array $imdbBrowserData = []): array
    {
        $doubanUrl = trim($doubanUrl);
        $imdbUrl = trim($imdbUrl);
        if ($doubanUrl === '' && $imdbUrl === '') {
            throw new RuntimeException('请先填写豆瓣链接或IMDb链接');
        }

        $sections = [];
        $errors = [];
        $smallDescr = '';

        if ($doubanUrl !== '') {
            try {
                $douban = $this->parseDouban($doubanUrl);
                $sections[] = $douban['section'];
                if (!empty($douban['small_descr'])) {
                    $smallDescr = $douban['small_descr'];
                }
                if ($imdbUrl === '' && !empty($douban['imdb_url'])) {
                    $imdbUrl = $douban['imdb_url'];
                }
            } catch (\Throwable $e) {
                $errors[] = '豆瓣解析失败: ' . $e->getMessage();
            }
        }

        if ($imdbUrl !== '') {
            try {
                $imdb = $this->parseImdb($imdbUrl, $imdbBrowserData);
                $sections[] = $imdb['section'];
            } catch (\Throwable $e) {
                $errors[] = 'IMDb解析失败: ' . $e->getMessage();
            }
        }

        if (empty($sections)) {
            throw new RuntimeException(implode('；', $errors));
        }

        return [
            'descr' => implode("\n\n", $sections),
            'small_descr' => $smallDescr,
            'warnings' => $errors,
        ];
    }

    private function parseDouban(string $url): array
    {
        $subjectId = $this->extractFirst('/subject\/(\d+)/i', $url);
        if ($subjectId === '') {
            $subjectId = $this->extractFirst('/(\d{5,})/', $url);
        }
        if ($subjectId === '') {
            throw new RuntimeException('无法识别豆瓣条目ID');
        }

        try {
            return $this->parseDoubanByApi($subjectId);
        } catch (\Throwable $e) {
            return $this->parseDoubanByHtml($subjectId);
        }
    }

    private function parseDoubanByApi(string $subjectId): array
    {
        $canonicalUrl = "https://movie.douban.com/subject/{$subjectId}/";
        $apiUrl = "https://m.douban.com/rexxar/api/v2/movie/{$subjectId}?for_mobile=1";
        $data = $this->getJson($apiUrl, [
            'Referer' => 'https://m.douban.com/',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || $title === '豆瓣') {
            throw new RuntimeException('豆瓣API未返回有效标题');
        }

        $year = trim((string)($data['year'] ?? ''));
        $poster = trim((string)($data['pic']['large'] ?? ($data['cover_url'] ?? '')));
        $summary = $this->normalizeText((string)($data['intro'] ?? ''));

        $rating = '';
        $votes = '';
        if (!empty($data['rating']) && is_array($data['rating'])) {
            $rating = trim((string)($data['rating']['value'] ?? ''));
            $votes = trim((string)($data['rating']['count'] ?? ''));
        }

        $directors = $this->collectPersonNames($data['directors'] ?? [], 4);
        $actors = $this->collectPersonNames($data['actors'] ?? [], 10);
        $genres = $this->collectScalarList($data['genres'] ?? []);
        $countries = $this->collectScalarList($data['countries'] ?? []);
        $languages = $this->collectScalarList($data['languages'] ?? []);
        $pubdate = $this->collectScalarList($data['pubdate'] ?? []);
        $durations = $this->collectScalarList($data['durations'] ?? []);
        $aka = $this->collectScalarList($data['aka'] ?? []);

        $smallDescr = $title;
        if ($aka !== '') {
            $smallDescr = $title . '/' . preg_replace('/\s*\/\s*/u', '/', $aka);
        }

        $imdbUrl = '';
        if (!empty($data['imdb'])) {
            $imdbUrl = $this->normalizeImdbUrl((string)$data['imdb']);
        }

        $lines = [
            '【豆瓣链接】' . $canonicalUrl,
            '【片　　名】' . $title,
        ];
        if ($year !== '') {
            $lines[] = '【年　　代】' . $year;
        }
        if ($rating !== '' && $rating !== '0') {
            $lines[] = '【豆瓣评分】' . $rating . ($votes !== '' ? " ({$votes}人评价)" : '');
        }
        if ($directors !== '') {
            $lines[] = '【导　　演】' . $directors;
        }
        if ($actors !== '') {
            $lines[] = '【主　　演】' . $actors;
        }
        if ($genres !== '') {
            $lines[] = '【类　　型】' . $genres;
        }
        if ($countries !== '') {
            $lines[] = '【制片国家/地区】' . $countries;
        }
        if ($languages !== '') {
            $lines[] = '【语　　言】' . $languages;
        }
        if ($pubdate !== '') {
            $lines[] = '【上映日期】' . $pubdate;
        }
        if ($durations !== '') {
            $lines[] = '【片　　长】' . $durations;
        }
        if ($aka !== '') {
            $lines[] = '【又　　名】' . $aka;
        }
        if ($imdbUrl !== '') {
            $lines[] = '【IMDb链接】' . $imdbUrl;
        }
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = '【简　　介】';
            $lines[] = $summary;
        }

        return [
            'section' => $this->buildSection('豆瓣信息', $poster, $lines),
            'small_descr' => $smallDescr,
            'imdb_url' => $imdbUrl,
        ];
    }

    private function parseDoubanByHtml(string $subjectId): array
    {
        $canonicalUrl = "https://movie.douban.com/subject/{$subjectId}/";
        $html = $this->getHtml($canonicalUrl);

        $title = $this->extractMeta($html, 'og:title');
        if ($title === '') {
            $title = $this->extractFirst('/<title>(.*?)<\/title>/is', $html);
            $title = trim(str_replace('(豆瓣)', '', html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        if ($title === '' || $title === '豆瓣') {
            throw new RuntimeException('豆瓣页面返回反爬内容，请稍后重试');
        }
        $year = $this->extractFirst('/<span\s+class="year">\((\d{4})\)<\/span>/i', $html);
        $rating = $this->extractFirst('/property="v:average">([\d\.]+)</i', $html);
        $votes = $this->extractFirst('/property="v:votes">([\d,]+)</i', $html);
        $summaryRaw = $this->extractFirst('/<span[^>]*property="v:summary"[^>]*>(.*?)<\/span>/is', $html);
        $summary = $this->normalizeText($summaryRaw);
        $poster = $this->extractMeta($html, 'og:image');
        $imdbId = $this->extractFirst('/imdb\.com\/title\/(tt\d+)/i', $html);
        $imdbUrl = $imdbId !== '' ? "https://www.imdb.com/title/{$imdbId}/" : '';

        $infoBlock = $this->extractFirst('/<div\s+id="info">(.*?)<\/div>/is', $html);
        $infoText = $this->normalizeText(str_replace('<br/>', "\n", str_replace('<br />', "\n", $infoBlock)));

        $aka = $this->extractInfoField($infoText, '又名');
        $smallDescr = $title;
        if ($aka !== '') {
            $smallDescr = $title . '/' . preg_replace('/\s*\/\s*/u', '/', $aka);
        }

        $lines = [
            '【豆瓣链接】' . $canonicalUrl,
            '【片　　名】' . ($title !== '' ? $title : 'N/A'),
        ];
        if ($year !== '') {
            $lines[] = '【年　　代】' . $year;
        }
        if ($rating !== '') {
            $lines[] = '【豆瓣评分】' . $rating . ($votes !== '' ? " ({$votes}人评价)" : '');
        }
        foreach (['导演', '编剧', '主演', '类型', '制片国家/地区', '语言', '上映日期', '片长', '又名'] as $label) {
            $value = $this->extractInfoField($infoText, $label);
            if ($value !== '') {
                $lines[] = '【' . $label . '】' . $value;
            }
        }
        if ($imdbUrl !== '') {
            $lines[] = '【IMDb链接】' . $imdbUrl;
        }
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = '【简　　介】';
            $lines[] = $summary;
        }

        return [
            'section' => $this->buildSection('豆瓣信息', $poster, $lines),
            'small_descr' => $smallDescr,
            'imdb_url' => $imdbUrl,
        ];
    }

    private function parseImdb(string $url, array $imdbBrowserData = []): array
    {
        $imdbId = $this->extractFirst('/(tt\d{5,})/i', $url);
        if ($imdbId === '') {
            throw new RuntimeException('无法识别IMDb条目ID');
        }
        $canonicalUrl = "https://www.imdb.com/title/{$imdbId}/";

        if (!empty($imdbBrowserData)) {
            return $this->parseImdbByApiPayload($imdbBrowserData, $imdbId, $canonicalUrl, '');
        }

        try {
            $html = $this->getHtml($canonicalUrl);
        } catch (\Throwable $e) {
            return $this->parseImdbByPublicApi($imdbId, $canonicalUrl, $e->getMessage());
        }

        $jsonLd = $this->extractImdbJsonLd($html);
        if (empty($jsonLd)) {
            return $this->parseImdbByPublicApi($imdbId, $canonicalUrl, '未获取到IMDb结构化数据');
        }
        $title = (string)($jsonLd['name'] ?? '');
        $datePublished = (string)($jsonLd['datePublished'] ?? '');
        $year = '';
        if (preg_match('/^(\d{4})/', $datePublished, $m)) {
            $year = $m[1];
        }
        $description = $this->normalizeText((string)($jsonLd['description'] ?? ''));
        $poster = (string)($jsonLd['image'] ?? '');

        $rating = '';
        $ratingCount = '';
        if (!empty($jsonLd['aggregateRating']) && is_array($jsonLd['aggregateRating'])) {
            $rating = (string)($jsonLd['aggregateRating']['ratingValue'] ?? '');
            $ratingCount = (string)($jsonLd['aggregateRating']['ratingCount'] ?? '');
        }

        $genre = '';
        if (!empty($jsonLd['genre'])) {
            $genre = is_array($jsonLd['genre']) ? implode(' / ', $jsonLd['genre']) : (string)$jsonLd['genre'];
        }

        $director = $this->collectJsonLdNames($jsonLd['director'] ?? null);
        $actor = $this->collectJsonLdNames($jsonLd['actor'] ?? null, 8);

        $lines = [
            '【IMDb链接】' . $canonicalUrl,
            '【片　　名】' . ($title !== '' ? $title : strtoupper($imdbId)),
        ];
        if ($year !== '') {
            $lines[] = '【年　　代】' . $year;
        }
        if ($rating !== '') {
            $lines[] = '【IMDb评分】' . $rating . ($ratingCount !== '' ? " ({$ratingCount}人评价)" : '');
        }
        if ($genre !== '') {
            $lines[] = '【类　　型】' . $genre;
        }
        if ($director !== '') {
            $lines[] = '【导　　演】' . $director;
        }
        if ($actor !== '') {
            $lines[] = '【主　　演】' . $actor;
        }
        if ($description !== '') {
            $lines[] = '';
            $lines[] = '【简　　介】';
            $lines[] = $description;
        }

        return ['section' => $this->buildSection('IMDb信息', $poster, $lines)];
    }

    private function parseImdbByApiPayload(array $data, string $imdbId, string $canonicalUrl, string $tip = ''): array
    {
        $title = trim((string)($data['primaryTitle'] ?? $data['originalTitle'] ?? strtoupper($imdbId)));
        $year = trim((string)($data['startYear'] ?? ''));
        $poster = trim((string)($data['primaryImage']['url'] ?? ''));
        $plot = $this->normalizeText((string)($data['plot'] ?? ''));
        $runtimeSeconds = intval($data['runtimeSeconds'] ?? 0);
        $runtimeMinutes = $runtimeSeconds > 0 ? (string)floor($runtimeSeconds / 60) : '';

        $genres = '';
        if (!empty($data['genres']) && is_array($data['genres'])) {
            $genres = $this->collectScalarList($data['genres']);
        }

        $countries = '';
        if (!empty($data['originCountries']) && is_array($data['originCountries'])) {
            $arr = [];
            foreach ($data['originCountries'] as $item) {
                if (is_array($item) && !empty($item['name'])) {
                    $arr[] = trim((string)$item['name']);
                }
            }
            $countries = implode(' / ', $arr);
        }

        $languages = '';
        if (!empty($data['spokenLanguages']) && is_array($data['spokenLanguages'])) {
            $arr = [];
            foreach ($data['spokenLanguages'] as $item) {
                if (is_array($item) && !empty($item['name'])) {
                    $arr[] = trim((string)$item['name']);
                }
            }
            $languages = implode(' / ', $arr);
        }

        $rating = '';
        $votes = '';
        if (!empty($data['rating']) && is_array($data['rating'])) {
            $rating = trim((string)($data['rating']['aggregateRating'] ?? ''));
            $votes = trim((string)($data['rating']['voteCount'] ?? ''));
        }

        $directors = '';
        if (!empty($data['directors']) && is_array($data['directors'])) {
            $directors = $this->collectDisplayNameList($data['directors'], 4);
        }

        $writers = '';
        if (!empty($data['writers']) && is_array($data['writers'])) {
            $writers = $this->collectDisplayNameList($data['writers'], 6);
        }

        $stars = '';
        if (!empty($data['stars']) && is_array($data['stars'])) {
            $stars = $this->collectDisplayNameList($data['stars'], 10);
        }

        $lines = [
            '【IMDb链接】' . $canonicalUrl,
            '【片　　名】' . $title,
        ];
        if ($year !== '') {
            $lines[] = '【年　　代】' . $year;
        }
        if ($rating !== '') {
            $lines[] = '【IMDb评分】' . $rating . ($votes !== '' ? " ({$votes}人评价)" : '');
        }
        if ($genres !== '') {
            $lines[] = '【类　　型】' . $genres;
        }
        if ($runtimeMinutes !== '') {
            $lines[] = '【片　　长】' . $runtimeMinutes . '分钟';
        }
        if ($countries !== '') {
            $lines[] = '【制片国家/地区】' . $countries;
        }
        if ($languages !== '') {
            $lines[] = '【语　　言】' . $languages;
        }
        if ($directors !== '') {
            $lines[] = '【导　　演】' . $directors;
        }
        if ($writers !== '') {
            $lines[] = '【编　　剧】' . $writers;
        }
        if ($stars !== '') {
            $lines[] = '【主　　演】' . $stars;
        }
        if ($plot !== '') {
            $lines[] = '';
            $lines[] = '【简　　介】';
            $lines[] = $plot;
        }
        if ($tip !== '') {
            $lines[] = '【提示】' . $tip;
        }

        return ['section' => $this->buildSection('IMDb信息', $poster, $lines)];
    }

    private function parseImdbByPublicApi(string $imdbId, string $canonicalUrl, string $reason): array
    {
        try {
            $apiUrl = 'https://api.imdbapi.dev/titles/' . strtolower($imdbId);
            $data = $this->getJson($apiUrl, ['Referer' => 'https://www.imdb.com/']);

            $section = $this->parseImdbByApiPayload($data, $imdbId, $canonicalUrl, 'IMDb正文页受限（' . $reason . '），已使用公开API补全。');
            return $section;
        } catch (\Throwable $e) {
            return $this->parseImdbBySuggestion($imdbId, $canonicalUrl, $reason . '; public api失败: ' . $e->getMessage());
        }
    }

    private function parseImdbBySuggestion(string $imdbId, string $canonicalUrl, string $reason): array
    {
        try {
            $suggestionUrl = 'https://v3.sg.media-imdb.com/suggestion/t/' . strtolower($imdbId) . '.json';
            $data = $this->getJson($suggestionUrl, ['Referer' => 'https://www.imdb.com/']);
            $items = $data['d'] ?? [];
            if (is_array($items) && !empty($items[0]) && is_array($items[0])) {
                $item = $items[0];
                $title = trim((string)($item['l'] ?? strtoupper($imdbId)));
                $year = trim((string)($item['y'] ?? ''));
                $cast = trim((string)($item['s'] ?? ''));
                $type = trim((string)($item['q'] ?? ''));
                $poster = trim((string)($item['i']['imageUrl'] ?? ''));

                $lines = [
                    '【IMDb链接】' . $canonicalUrl,
                    '【片　　名】' . $title,
                ];
                if ($year !== '') {
                    $lines[] = '【年　　代】' . $year;
                }
                if ($type !== '') {
                    $lines[] = '【类　　型】' . $type;
                }
                if ($cast !== '') {
                    $lines[] = '【主　　演】' . $cast;
                }
                $lines[] = '【提示】IMDb正文页受限（' . $reason . '），已使用公开索引数据补全。';

                return ['section' => $this->buildSection('IMDb信息', $poster, $lines)];
            }
        } catch (\Throwable $e) {
            return $this->buildImdbFallbackSection($canonicalUrl, $imdbId, $reason . '; suggestion失败: ' . $e->getMessage());
        }

        return $this->buildImdbFallbackSection($canonicalUrl, $imdbId, $reason . '; suggestion无有效数据');
    }

    private function buildImdbFallbackSection(string $canonicalUrl, string $imdbId, string $reason): array
    {
        $lines = [
            '【IMDb链接】' . $canonicalUrl,
            '【条目ID】' . strtolower($imdbId),
            '【提示】IMDb当前返回受限（' . $reason . '），已保留基础信息。',
        ];
        return ['section' => $this->buildSection('IMDb信息', '', $lines)];
    }

    private function getHtml(string $url): string
    {
        $response = $this->http->request('GET', $url);
        $code = $response->getStatusCode();
        if ($code !== 200) {
            throw new RuntimeException("请求失败，HTTP {$code}");
        }
        $html = (string)$response->getBody();
        if ($html === '') {
            throw new RuntimeException('响应为空');
        }
        return $html;
    }

    private function getJson(string $url, array $headers = []): array
    {
        $response = $this->http->request('GET', $url, ['headers' => $headers]);
        $code = $response->getStatusCode();
        if ($code !== 200) {
            throw new RuntimeException("请求失败，HTTP {$code}");
        }
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('JSON解析失败');
        }
        return $data;
    }

    private function extractMeta(string $html, string $property): string
    {
        if (preg_match('/<meta[^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . preg_quote($property, '/') . '["\']/i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private function extractFirst(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    private function extractInfoField(string $infoText, string $field): string
    {
        if ($infoText === '') {
            return '';
        }
        if (preg_match('/^' . preg_quote($field, '/') . '\s*[:：]\s*(.+)$/mui', $infoText, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function normalizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_filter(array_map(static fn($line) => trim($line), explode("\n", $text)), static fn($line) => $line !== '');
        return implode("\n", $lines);
    }

    private function extractImdbJsonLd(string $html): array
    {
        if (!preg_match_all('/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return [];
        }
        foreach ($matches[1] as $jsonText) {
            $jsonText = trim($jsonText);
            if ($jsonText === '') {
                continue;
            }
            $decoded = json_decode($jsonText, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (isset($decoded['@type']) && in_array($decoded['@type'], ['Movie', 'TVSeries', 'TVEpisode'], true)) {
                return $decoded;
            }
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $item) {
                    if (is_array($item) && isset($item['@type']) && in_array($item['@type'], ['Movie', 'TVSeries', 'TVEpisode'], true)) {
                        return $item;
                    }
                }
            }
        }
        return [];
    }

    private function collectJsonLdNames(mixed $value, int $limit = 3): string
    {
        if (empty($value)) {
            return '';
        }
        $names = [];
        $arr = is_array($value) && array_is_list($value) ? $value : [$value];
        foreach ($arr as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
            if (count($names) >= $limit) {
                break;
            }
        }
        return implode(' / ', $names);
    }

    private function collectPersonNames(array $arr, int $limit = 8): string
    {
        $names = [];
        foreach ($arr as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
            if (count($names) >= $limit) {
                break;
            }
        }
        return implode(' / ', $names);
    }

    private function collectScalarList(array $arr): string
    {
        $result = [];
        foreach ($arr as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return implode(' / ', $result);
    }

    private function collectDisplayNameList(array $arr, int $limit = 10): string
    {
        $result = [];
        foreach ($arr as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['displayName'] ?? $item['name'] ?? ''));
            if ($name !== '') {
                $result[] = $name;
            }
            if (count($result) >= $limit) {
                break;
            }
        }
        return implode(' / ', $result);
    }

    private function normalizeImdbUrl(string $imdb): string
    {
        $imdb = trim($imdb);
        if ($imdb === '') {
            return '';
        }
        if (preg_match('/tt\d{5,}/i', $imdb, $m)) {
            return 'https://www.imdb.com/title/' . strtolower($m[0]) . '/';
        }
        if (preg_match('#^https?://#i', $imdb)) {
            return $imdb;
        }
        return '';
    }

    private function buildSection(string $title, string $poster, array $lines): string
    {
        $parts = [];
        if ($poster !== '') {
            $parts[] = '[img]' . $poster . '[/img]';
        }
        $parts[] = '[quote][b]' . $title . "[/b]\n" . implode("\n", $lines) . '[/quote]';
        return implode("\n", $parts);
    }
}
