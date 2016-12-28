<?php

require_once "../init.php";

use cvweiss\redistools\RedisTimeQueue;

$charID = @$argv[1];
$esi = new RedisTimeQueue('tqApiESI', 3600);
if ($charID !== null) {
    pullEsiKills((int) $charID, $esi);
    exit();
}


if (date('i') == 22 || $esi->size() == 0) {
    Util::out("Loading esi's");
    $esis = $mdb->find("apisESI");
    foreach ($esis as $row) {
        $charID = $row['characterID'];
        $esi->add($charID);
    }
}

$redirect = str_replace("/cron/", "/cron/logs/", __FILE__) . ".log";
$minutely = date('Hi');
while ($minutely == date('Hi')) {
    $charID = (int) $esi->next();
    if ($charID == 0) {
        sleep(1);
        continue;
    }
    exec("cd " . __DIR__ . " ; php " . __FILE__ . " $charID >>$redirect 2>>$redirect &");
    usleep(100000);
}

function pullEsiKills($charID, $esi) {
    global $redis, $mdb;
    $sso = new RedisTimeQueue('tqApiSSO', 3600);
    $row = $mdb->findDoc("apisESI", ['characterID' => $charID]);
    if ($row === null) {
        $esi->remove($charID);
        return;
    }

    $killsAdded = 0;
    $scopes = $row['scopes'];
    $prevMaxKillID = isset($row['maxKillID']) ? $row['maxKillID'] : 0;
    $maxKillID = 0;
    $minKillID = 999999999999;

    if (in_array('esi-killmails.read_killmails.v1', $scopes)) {
        $refreshToken = $row['refreshToken'];
        $charID = $row['characterID'];
        $fullStop = false;

        $accessToken = CrestSSO::getAccessToken($charID, null, $refreshToken);
        if (isset($accessToken['error'])) {
            switch ($accessToken['error']) {
                case "invalid_grant":
                    $mdb->remove("apisESI", $row);
                    break;
                default:
                    Util::out("Unknown ESI error:\n" . print_r($accessToken, true));
            }
            return;
        }
        if (!isset($accessToken['error'])) {
            $headers = [];
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Authorization: Bearer ' . $accessToken;


            do {
                $url = "https://esi.tech.ccp.is/latest/characters/$charID/killmails/recent/";
                $fields = ['max_count' => 50, 'datasource' => 'tranquility'];
                if ($minKillID !== 999999999999) $fields['max_kill_id'] = $minKillID;

                $raw = doCall($url, $fields, $accessToken);
                $json = json_decode($raw, true);

                foreach ($json as $kill) {
                    if (!isset($kill['killmail_id'])) {
                        $fullStop = true;
                        break;
                    }
                    $killID = $kill['killmail_id'];
                    $hash = $kill['killmail_hash'];
                    $minKillID = min($minKillID, $killID);
                    //$maxKillID = max($maxKillID, $killID);

                    $exists = $mdb->exists('crestmails', ['killID' => $killID]);
                    if (!$exists) {
                        try {
                            $mdb->getCollection('crestmails')->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'esi', 'added' => $mdb->now()]);
                            $killsAdded++;
                        } catch (MongoDuplicateKeyException $ex) {
                            // ignore it *sigh*
                        }
                    }
                }
            } while (sizeof($json) > 0 && $fullStop == false && $prevMaxKillID < $minKillID);
        }
        $mdb->set("apisESI", $row, ['lastFetch' => $mdb->now(), 'maxKillID' => $maxKillID]);
        $mdb->remove("apisCrest", ['characterID' => $charID]);
        $mdb->remove("apis", ['type' => 'char', 'userID' => $charID]);
        $sso->remove($charID);
        $redis->setex("apiVerified:$charID", 86400, time());
    }
    else {
        $mdb->remove("apisESI", $row);
    }
    if ($killsAdded > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        if ($name === null) $name = $charID;
        while (strlen("$killsAdded") < 3) $killsAdded = " " . $killsAdded;
        Util::out("$killsAdded kills added by char $name (ESI)");
        if ($killsAdded >= 10) User::sendMessage("$killsAdded kills added for char $name", $charID);
    }
}

function doCall($url, $fields, $accessToken, $callType = 'GET')
{
    $callType = strtoupper($callType);
    $headers = ['Authorization: Bearer ' . $accessToken];

    $fieldsString = buildParams($fields);
    $url = $callType != 'GET' ? $url : $url . "?" . $fieldsString;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERAGENT, "curl fetcher for zkillboard.com");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    switch ($callType) {
        case 'DELETE':
        case 'PUT':
        case 'POST_JSON':
            $headers[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(empty($fields) ? (object) NULL : $fields, JSON_UNESCAPED_SLASHES));
            $callType = $callType == 'POST_JSON' ? 'POST' : $callType;
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldsString);
            break;
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $callType);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $result = curl_exec($ch);
    if (curl_errno($ch) !== 0) {
        Util::out("request error " . curl_errno($ch));
        throw new \Exception(curl_error($ch), curl_errno($ch));
    }
    return $result;
}

function buildParams($fields)
{
    $string = "";
    foreach ($fields as $field=>$value) {
        $string .= $string == "" ? "" : "&";
        $string .= "$field=" . rawurlencode($value);
    }
    return $string;
}