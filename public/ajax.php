<?php
require "../include/bittorrent.php";
dbconn();

$action = $_POST['action'] ?? '';
$params = $_POST['params'] ?? [];

if ($action != 'getPasskeyGetArgs' && $action != 'processPasskeyGet') {
    loggedinorreturn();
}

class AjaxInterface{

    public static function toggleUserMedalStatus($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\MedalRepository();
        return $rep->toggleUserMedalStatus($params['id'], $CURUSER['id']);
    }


    public static function attendanceRetroactive($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\AttendanceRepository();
        return $rep->retroactive($CURUSER['id'], $params['date']);
    }

    public static function getPtGen($params)
    {
        $rep = new Nexus\PTGen\PTGen();
        $result = $rep->generate($params['url']);
        if ($rep->isRawPTGen($result)) {
            return $result;
        } elseif ($rep->isIyuu($result)) {
            return $result['data'];
        } else {
            return '';
        }
    }

    public static function parseExternalDescription($params)
    {
        $doubanUrl = trim((string)($params['douban_url'] ?? ''));
        $imdbUrl = trim((string)($params['imdb_url'] ?? ''));
        $imdbBrowserData = $params['imdb_browser_data'] ?? [];
        if (!is_array($imdbBrowserData)) {
            $imdbBrowserData = [];
        }
        $service = new \App\Services\ExternalDescriptionParser();
        return $service->parse($doubanUrl, $imdbUrl, $imdbBrowserData);
    }

    public static function addClaim($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\ClaimRepository();
        return $rep->store($CURUSER['id'], $params['torrent_id']);
    }

    public static function removeClaim($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\ClaimRepository();
        return $rep->delete($params['id'], $CURUSER['id']);
    }

    public static function removeUserLeechWarn($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\UserRepository();
        return $rep->removeLeechWarn($CURUSER['id'], $params['uid']);
    }

    public static function getOffer($params)
    {
        $offer = \App\Models\Offer::query()->findOrFail($params['id']);
        return $offer->toArray();
    }

    public static function approvalModal($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\TorrentRepository();
        return $rep->buildApprovalModal($CURUSER['id'], $params['torrent_id']);
    }

    public static function approval($params)
    {
        global $CURUSER;
        foreach (['torrent_id', 'approval_status',] as $field) {
            if (!isset($params[$field])) {
                throw new \InvalidArgumentException("Require $field");
            }
        }
        $rep = new \App\Repositories\TorrentRepository();
        return $rep->approval($CURUSER['id'], $params);
    }

    public static function addSeedBoxRecord($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\SeedBoxRepository();
        $params['uid'] = $CURUSER['id'];
        $params['type'] = \App\Models\SeedBoxRecord::TYPE_USER;
        $params['status'] = \App\Models\SeedBoxRecord::STATUS_UNAUDITED;
        return $rep->store($params);
    }

    public static function removeSeedBoxRecord($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\SeedBoxRepository();
        return $rep->delete($params['id'], $CURUSER['id']);
    }

    public static function removeHitAndRun($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\BonusRepository();
        return $rep->consumeToCancelHitAndRun($CURUSER['id'], $params['id']);
    }

    public static function consumeBenefit($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\UserRepository();
        return $rep->consumeBenefit($CURUSER['id'], $params);
    }

    public static function clearShoutBox($params)
    {
        global $CURUSER;
        user_can('sbmanage', true);
        \Nexus\Database\NexusDB::table('shoutbox')->delete();
        return true;
    }

    public static function buyMedal($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\BonusRepository();
        return $rep->consumeToBuyMedal($CURUSER['id'], $params['medal_id']);
    }

    public static function giftMedal($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\BonusRepository();
        return $rep->consumeToGiftMedal($CURUSER['id'], $params['medal_id'], $params['uid']);
    }

    public static function saveUserMedal($params)
    {
        global $CURUSER;
        $data = [];
        foreach ($params as $param) {
            $fieldAndId = explode('_', $param['name']);
            $field = $fieldAndId[0];
            $id = $fieldAndId[1];
            $value = $param['value'];
            $data[$id][$field] = $value;
        }
    //    dd($params, $data);
        $rep = new \App\Repositories\MedalRepository();
        return $rep->saveUserMedal($CURUSER['id'], $data);
    }

    public static function claimTask($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\ExamRepository();
        return $rep->assignToUser($CURUSER['id'], $params['exam_id']);
    }

    public static function addToken($params)
    {
        global $CURUSER;
        if (empty($params['name'])) {
            throw new \InvalidArgumentException("Name is required");
        }
        $user = \App\Models\User::query()->findOrFail($CURUSER['id'], \App\Models\User::$commonFields);
        $user->createToken($params['name']);
        return true;
    }

    public static function removeToken($params)
    {
        global $CURUSER;
        if (empty($params['id'])) {
            throw new \InvalidArgumentException("id is required");
        }
        $user = \App\Models\User::query()->findOrFail($CURUSER['id'], \App\Models\User::$commonFields);
        $user->tokens()->where('id', $params['id'])->delete();
        return true;
    }

    public static function getPasskeyCreateArgs($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\UserPasskeyRepository();
        return $rep->getCreateArgs($CURUSER['id'], $CURUSER['username']);
    }

    public static function processPasskeyCreate($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\UserPasskeyRepository();
        return $rep->processCreate($CURUSER['id'], $params['challengeId'], $params['clientDataJSON'], $params['attestationObject']);
    }

    public static function deletePasskey($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\UserPasskeyRepository();
        return $rep->delete($CURUSER['id'], $params['credentialId']);
    }

    public static function getPasskeyList($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\UserPasskeyRepository();
        return $rep->getList($CURUSER['id']);
    }

    public static function getPasskeyGetArgs($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\UserPasskeyRepository();
        return $rep->getGetArgs();
    }

    public static function processPasskeyGet($params)
    {
        global $CURUSER;
        $rep = new \App\Repositories\UserPasskeyRepository();
        return $rep->processGet($params['challengeId'], $params['id'], $params['clientDataJSON'], $params['authenticatorData'], $params['signature'], $params['userHandle']);
    }

    public static function savePersonalize($params)
    {
        global $CURUSER;
        $uid = (int)$CURUSER['id'];
        $allow = ['--bili-primary', '--bili-accent', '--bili-bg', '--bili-surface', '--bili-text'];
        $arr = json_decode((string)($params['data'] ?? ''), true);
        $clean = [];
        if (is_array($arr)) {
            foreach ($allow as $k) {
                if (isset($arr[$k]) && is_string($arr[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', $arr[$k])) {
                    $clean[$k] = $arr[$k];
                }
            }
        }
        \App\Models\UserMeta::query()->updateOrCreate(
            ['uid' => $uid, 'meta_key' => 'PERSONALIZE'],
            ['meta_value' => json_encode($clean), 'status' => \App\Models\UserMeta::STATUS_NORMAL, 'deadline' => null]
        );
        \Nexus\Database\NexusDB::cache_del("qd_personalize_$uid");
        return ['saved' => count($clean)];
    }

    public static function clearPersonalize($params)
    {
        global $CURUSER;
        $uid = (int)$CURUSER['id'];
        \App\Models\UserMeta::query()->where('uid', $uid)->where('meta_key', 'PERSONALIZE')->delete();
        \Nexus\Database\NexusDB::cache_del("qd_personalize_$uid");
        return ['cleared' => true];
    }
}

$class = 'AjaxInterface';
$reflection = new \ReflectionClass($class);

try {
    if($reflection->hasMethod($action) && $reflection->getMethod($action)->isStatic()) {
        $result = $class::$action($params);
        exit(json_encode(success($result)));
    } else {
        do_log("hacking attempt made by {$CURUSER['username']},uid {$CURUSER['id']}", 'error');
        throw new \RuntimeException("Invalid action: $action");
    }
}catch(\Throwable $exception){
    do_log($exception->getMessage() . $exception->getTraceAsString(), "error");
    exit(json_encode(fail($exception->getMessage(), $_POST)));
}
