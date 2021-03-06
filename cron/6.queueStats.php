<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

MongoCursor::$timeout = -1;
$queueStats = new RedisQueue('queueStats');
$maxSequence = $mdb->findField("killmails", "sequence", [], ['sequence' => -1]);

$minute = date('Hi');
while ($minute == date('Hi')) {
    $row = $queueStats->pop();
    if ($row == null) break;
    calcStats($row, $maxSequence);
}

while ($minute == date('Hi')) {
    $row = null;
    $resetRow = $mdb->findDoc("statistics", ['reset' => true]);
    if ($resetRow != null) {
        $row = ['type' => $resetRow['type'], 'id' => $resetRow['id'], 'sequence' => $maxSequence];
    }
    if ($row == null) break;
    calcStats($row, $maxSequence);
}


function calcStats($row, $maxSequence)
{
    global $mdb, $debug;

    $type = $row['type'];
    $id = $row['id'];
    if ($id == 0) return;
    $newSequence = $row['sequence'];

    $key = ['type' => $type, 'id' => $id];
    $stats = $mdb->findDoc('statistics', $key);
    if ($stats === null || isset($stats['reset'])) {
        $id_ = isset($stats['_id']) ? $stats['_id'] : null;
        $topAllTime = isset($stats['topAllTime']) ? $stats['topAllTime'] : null;
        $stats = [];
        $stats['type'] = $type;
        $stats['id'] = $id;
        if ($id_ !== null) {
            $stats['_id'] = $id_;
            $stats['topAllTime'] = $topAllTime;
        }
    }

    $oldSequence = (int) @$stats['sequence'];
    if ($newSequence <= $oldSequence) {
        return;
    }

    for ($i = 0; $i <= 1; ++$i) {
        $isVictim = ($i == 0);
        if (($type == 'locationID' || $type == 'regionID' || $type == 'solarSystemID') && $isVictim == true) {
            continue;
        }

        // build the query
        $query = [$row['type'] => $row['id'], 'isVictim' => $isVictim, 'npc' => false];
        $query = MongoFilter::buildQuery($query);
        // set the proper sequence values
        $query = ['$and' => [['sequence' => ['$gt' => $oldSequence]], ['sequence' => ['$lte' => $newSequence]], $query]];

        $allTime = $mdb->group('killmails', [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);
        mergeAllTime($stats, $allTime, $isVictim);

        $groups = $mdb->group('killmails', 'vGroupID', $query, 'killID', ['zkb.points', 'zkb.totalValue'], ['vGroupID' => 1]);
        mergeGroups($stats, $groups, $isVictim);

        $months = $mdb->group('killmails', ['year' => 'dttm', 'month' => 'dttm'], $query, 'killID', ['zkb.points', 'zkb.totalValue'], ['year' => 1, 'month' => 1]);
        mergeMonths($stats, $months, $isVictim);

        $query = [$row['type'] => $row['id'], 'isVictim' => $isVictim, 'npc' => false, 'solo' => true];
        $query = MongoFilter::buildQuery($query);
        $key = "solo" . ($isVictim ? "Losses" : "Kills");
        if (isset($stats[$key])) {
            $query = ['$and' => [['sequence' => ['$gt' => $oldSequence]], ['sequence' => ['$lte' => $newSequence]], $query]];
        }
        $count = $mdb->count('killmails', $query);
        $stats[$key] = isset($stats[$key]) ? $stats[$key] + $count : $count;
    }

    // Update the sequence
    $stats['sequence'] = $newSequence;
    if ($type == 'characterID') $stats['calcTrophies'] = true;
    // save it
    $mdb->getCollection('statistics')->save($stats);

    $r = $mdb->getDb()->command(['getLastError' => 1]);
    if ($r['ok'] != 1) {
        die('stats update failure');
    }
    if ($debug) {
        Util::out("Stats completed for: $type $id $newSequence");
    }
}

function mergeAllTime(&$stats, $result, $isVictim)
{
    if (sizeof($result) == 0) {
        return;
    }

    $row = $result[0];
    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    if (!isset($stats["ships$dl"])) {
        $stats["ships$dl"] = 0;
    }
    $stats["ships$dl"] += $row['killIDCount'];
    if (!isset($stats["points$dl"])) {
        $stats["points$dl"] = 0;
    }
    $stats["points$dl"] += $row['zkb_pointsSum'];
    if (!isset($stats["isk$dl"])) {
        $stats["isk$dl"] = 0;
    }
    $stats["isk$dl"] += (int) $row['zkb_totalValueSum'];
}

function mergeGroups(&$stats, $result, $isVictim)
{
    if (sizeof($result) == 0) {
        return;
    }

    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    if (!isset($stats['groups'])) {
        $stats['groups'] = [];
    }
    $groups = $stats['groups'];
    foreach ($result as $row) {
        $groupID = $row['vGroupID'];
        if (!isset($groups[$groupID])) {
            $groups[$groupID] = [];
        }
        $groupStats = $groups[$groupID];
        $groupStats['groupID'] = $groupID;

        @$groupStats["ships$dl"] += $row['killIDCount'];
        @$groupStats["points$dl"] += $row['zkb_pointsSum'];
        @$groupStats["isk$dl"] += (int) $row['zkb_totalValueSum'];

        $groups[$groupID] = $groupStats;
    }
    $stats['groups'] = $groups;
}

function mergeMonths(&$stats, $result, $isVictim)
{
    if (sizeof($result) == 0) {
        return;
    }

    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    if (!isset($stats['months'])) {
        $stats['months'] = [];
    }
    $months = $stats['months'];
    foreach ($result as $row) {
        $year = $row['year'];
        $month = $row['month'];
        if (strlen($month) < 2) {
            $month = "0$month";
        }
        $yearMonth = "$year$month";

        if (!isset($months[$yearMonth])) {
            $months[$yearMonth] = [];
        }
        $monthStats = $months[$yearMonth];
        $monthStats['year'] = $year;
        $monthStats['month'] = (int) $month;

        @$monthStats["ships$dl"] += $row['killIDCount'];
        @$monthStats["points$dl"] += $row['zkb_pointsSum'];
        @$monthStats["isk$dl"] += (int) $row['zkb_totalValueSum'];

        $months[$yearMonth] = $monthStats;
    }
    $stats['months'] = $months;
}
