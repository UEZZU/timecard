<?php
declare(strict_types=1);

/**
 * 打刻 / 现金入金 / 運転記録 用 API
 *
 * ・前端 index.html 通过 ?action=xxx 调用本文件的单一入口。
 * ・认证：用 users.token 匹配用户；token 不存在返回 404 invalid_token，
 *   users.active != 1 返回 403 user_inactive（所有需要身份的接口统一校验）。
 * ・输出：始终返回 JSON，格式为 {"ok":true/false, ...}。
 * ・日期/时间：以服务器时区 Asia/Tokyo 为准。
 */

require_once __DIR__ . '/timecard.config.php';

// 统一使用日本时间，避免与客户端时区不一致
date_default_timezone_set('Asia/Tokyo');

// 打刻类型（上班/中途外出/中途返回/下班），与 DB 中的 enum 对应
const PUNCH_TYPES = ['clock_in', 'leave_out', 'leave_in', 'clock_out'];
// 午餐选项（有/无）
const LUNCH_PICK   = ['yes', 'no'];

header('Content-Type: application/json; charset=utf-8');

/**
 * 输出 JSON 并结束请求。
 *
 * @param int   $code HTTP 状态码
 * @param array $data 要输出的数据
 */
function json_out(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 建立数据库连接（失败立即返回 db_connect）
$db = null;
try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    json_out(500, ['ok' => false, 'error' => 'db_connect']);
}

/**
 * 根据 token 查询用户（不校验 active）。
 *
 * @return array|null 找不到返回 null
 */
function get_user(PDO $db, string $token): ?array
{
    $st = $db->prepare('SELECT id, name, cash_enabled, remote_punch, drive_enabled, default_car_id, active FROM users WHERE token = ? LIMIT 1');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/**
 * 认证并返回用户。token 无效返回 404，账号被停用(active=0)返回 403。
 * 所有需要登录身份的接口都应先调用此函数。
 */
function require_user(PDO $db, string $token): array
{
    $user = get_user($db, $token);
    if (!$user) {
        json_out(404, ['ok' => false, 'error' => 'invalid_token']);
    }
    if ((int) ($user['active'] ?? 1) !== 1) {
        json_out(403, ['ok' => false, 'error' => 'user_inactive']);
    }
    return $user;
}

/**
 * 取得指定用户某一天的全部打刻记录（按时间、ID 升序）。
 */
function fetch_records(PDO $db, int $userId, string $date): array
{
    $st = $db->prepare(
        'SELECT punch_type AS type, punch_time AS time
         FROM punches WHERE user_id = ? AND punch_date = ?
         ORDER BY punch_time ASC, id ASC'
    );
    $st->execute([$userId, $date]);
    return $st->fetchAll();
}

/**
 * 取得某天是否吃午餐（'yes'/'no'），未设置返回 null。
 */
function fetch_lunch(PDO $db, int $userId, string $date): ?string
{
    $st = $db->prepare('SELECT lunch FROM daily_lunch WHERE user_id = ? AND punch_date = ?');
    $st->execute([$userId, $date]);
    $row = $st->fetch();
    return $row ? $row['lunch'] : null;
}

/**
 * 写入/更新某天的午餐设置（同一天已存在则覆盖）。
 */
function upsert_lunch(PDO $db, int $userId, string $date, string $lunch): void
{
    $db->prepare(
        'INSERT INTO daily_lunch (user_id, punch_date, lunch) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE lunch = VALUES(lunch)'
    )->execute([$userId, $date, $lunch]);
}

// 读取请求方法与 action 参数，进入分发
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

switch ($action) {
    // 存活检测（无需认证）
    case 'ping':
        json_out(200, ['ok' => true, 'time' => time()]);

    // 取得当前登录用户信息；附带服务器时间(epoch 秒)供前端校准时差
    case 'me':
        $user = require_user($db, $_GET['token'] ?? '');
        json_out(200, ['ok' => true, 'user' => $user, 'time' => time()]);

    // 今日打刻记录 + 午餐设置
    case 'today':
        $user = require_user($db, $_GET['token'] ?? '');
        $date = date('Y-m-d');
        json_out(200, [
            'ok'      => true,
            'date'    => $date,
            'lunch'   => fetch_lunch($db, (int) $user['id'], $date),
            'records' => fetch_records($db, (int) $user['id'], $date),
        ]);

    // 最近 30 天的打刻历史（按天分组，附带当天午餐）
    case 'history':
        $user = require_user($db, $_GET['token'] ?? '');
        $uid = (int) $user['id'];

        $st = $db->prepare(
            'SELECT punch_date AS d, punch_type AS t, punch_time AS tm
             FROM punches
             WHERE user_id = ? AND punch_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             ORDER BY punch_date DESC, punch_time ASC, id ASC'
        );
        $st->execute([$uid]);

        // 同一时间段内该用户的午餐记录：date => lunch
        $stLunch = $db->prepare(
            'SELECT punch_date AS d, lunch FROM daily_lunch
             WHERE user_id = ? AND punch_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        );
        $stLunch->execute([$uid]);
        $lunchMap = array_column($stLunch->fetchAll(), 'lunch', 'd');

        // 把扁平的打刻记录按日期分组
        $days = [];
        foreach ($st->fetchAll() as $row) {
            if (!isset($days[$row['d']])) {
                $days[$row['d']] = [];
            }
            $days[$row['d']][] = ['type' => $row['t'], 'time' => $row['tm']];
        }

        $out = [];
        foreach ($days as $date => $records) {
            $out[] = ['date' => $date, 'lunch' => $lunchMap[$date] ?? null, 'records' => $records];
        }
        json_out(200, ['ok' => true, 'days' => $out]);

    // 打刻（上班/中途外出/中途返回/下班）。同一天同类型只允许一次
    case 'punch':
        if ($method !== 'POST') {
            json_out(405, ['ok' => false, 'error' => 'method']);
        }
        $in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
        $user = require_user($db, (string) ($in['token'] ?? ''));
        $type = (string) ($in['type'] ?? '');
        if (!in_array($type, PUNCH_TYPES, true)) {
            json_out(400, ['ok' => false, 'error' => 'bad_type']);
        }
        $date = date('Y-m-d');
        $time = date('H:i:s');
        // 先查重，给出友好错误
        $stDup = $db->prepare(
            'SELECT 1 FROM punches WHERE user_id = ? AND punch_date = ? AND punch_type = ?'
        );
        $stDup->execute([(int) $user['id'], $date, $type]);
        if ($stDup->fetch()) {
            json_out(409, ['ok' => false, 'error' => 'duplicate']);
        }
        try {
            $db->prepare(
                'INSERT INTO punches (user_id, punch_date, punch_type, punch_time) VALUES (?, ?, ?, ?)'
            )->execute([(int) $user['id'], $date, $type, $time]);
        } catch (PDOException $e) {
            // 并发重复插入时由唯一键兜底，同样按重复处理
            if ($e->getCode() === '23000') {
                json_out(409, ['ok' => false, 'error' => 'duplicate']);
            }
            throw $e;
        }

        // 下班打刻时，如果当天还没记录午餐，则默认记为「有」，以便历史页面显示
        if ($type === 'clock_out') {
            $stExist = $db->prepare('SELECT 1 FROM daily_lunch WHERE user_id = ? AND punch_date = ?');
            $stExist->execute([(int) $user['id'], $date]);
            if (!$stExist->fetch()) {
                $db->prepare('INSERT INTO daily_lunch (user_id, punch_date, lunch) VALUES (?, ?, ?)')
                    ->execute([(int) $user['id'], $date, 'yes']);
            }
        }
        json_out(200, ['ok' => true, 'type' => $type, 'time' => $time]);

    // 设置当天午餐
    case 'set_lunch':
        if ($method !== 'POST') {
            json_out(405, ['ok' => false, 'error' => 'method']);
        }
        $in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
        $user = require_user($db, (string) ($in['token'] ?? ''));
        $lunch = (string) ($in['lunch'] ?? '');
        if (!in_array($lunch, LUNCH_PICK, true)) {
            json_out(400, ['ok' => false, 'error' => 'bad_lunch']);
        }
        // 已下班则不允许再修改午餐
        $stOut = $db->prepare("SELECT 1 FROM punches WHERE user_id = ? AND punch_date = ? AND punch_type = 'clock_out' LIMIT 1");
        $stOut->execute([(int) $user['id'], date('Y-m-d')]);
        if ($stOut->fetch()) {
            json_out(409, ['ok' => false, 'error' => 'already_clocked_out']);
        }
        upsert_lunch($db, (int) $user['id'], date('Y-m-d'), $lunch);
        json_out(200, ['ok' => true, 'lunch' => $lunch]);

    // 有效店铺一览
    case 'shops':
        require_user($db, (string) ($_GET['token'] ?? ''));
        $st = $db->query('SELECT code, name FROM shops WHERE active = 1 ORDER BY sort_order ASC, id ASC');
        json_out(200, ['ok' => true, 'shops' => $st->fetchAll()]);

    // 登记当天现金入金（同店同日则覆盖金额）
    case 'cash_log':
        if ($method !== 'POST') {
            json_out(405, ['ok' => false, 'error' => 'method']);
        }
        $in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
        $user = require_user($db, (string) ($in['token'] ?? ''));
        if (!(int) $user['cash_enabled']) {
            json_out(403, ['ok' => false, 'error' => 'no_permission']);
        }
        $shop = (string) ($in['shop'] ?? '');
        if (!$shop) {
            json_out(400, ['ok' => false, 'error' => 'bad_shop']);
        }
        // 校验店铺代码存在且启用
        $st = $db->prepare('SELECT code FROM shops WHERE code = ? AND active = 1 LIMIT 1');
        $st->execute([$shop]);
        if (!$st->fetch()) {
            json_out(400, ['ok' => false, 'error' => 'bad_shop']);
        }
        $amount = filter_var($in['amount'] ?? '', FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 0) {
            json_out(400, ['ok' => false, 'error' => 'bad_amount']);
        }
        $date = date('Y-m-d');
        // 同一天同一店铺则更新金额，否则新增
        $db->prepare(
            'INSERT INTO cash_logs (user_id, cash_date, shop, amount) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), noted_at = CURRENT_TIMESTAMP'
        )->execute([(int) $user['id'], $date, $shop, $amount]);
        json_out(200, ['ok' => true, 'id' => (int) $db->lastInsertId(), 'date' => $date]);

    // 最近 5 条现金入金记录
    case 'cash_history':
        $user = require_user($db, (string) ($_GET['token'] ?? ''));
        $st = $db->prepare(
            'SELECT shop, amount, cash_date, DATE_FORMAT(noted_at, "%Y-%m-%d %H:%i") AS noted_at
             FROM cash_logs WHERE user_id = ?
             ORDER BY cash_date DESC, id DESC LIMIT 5'
        );
        $st->execute([(int) $user['id']]);
        json_out(200, ['ok' => true, 'logs' => $st->fetchAll()]);

    // 更新某条運転記録的备注（仅限本人记录）
    case 'drive_note':
        if ($method !== 'POST') {
            json_out(405, ['ok' => false, 'error' => 'method']);
        }
        $in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
        $user = require_user($db, (string) ($in['token'] ?? ''));
        if (!(int) $user['drive_enabled']) {
            json_out(403, ['ok' => false, 'error' => 'no_permission']);
        }
        $rid = (int) ($in['id'] ?? 0);
        // 校验记录归属，防止越权修改他人记录
        $stOwn = $db->prepare('SELECT id FROM drive_logs WHERE id = ? AND user_id = ? LIMIT 1');
        $stOwn->execute([$rid, (int) $user['id']]);
        if (!$stOwn->fetch()) {
            json_out(400, ['ok' => false, 'error' => 'bad_record']);
        }
        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > 1000) {
            json_out(400, ['ok' => false, 'error' => 'bad_note']);
        }
        $db->prepare('UPDATE drive_logs SET notes = ? WHERE id = ?')->execute([$notes === '' ? null : $notes, $rid]);
        json_out(200, ['ok' => true, 'id' => $rid]);

    // 運転記録：phase=pre 登记運転前，phase=post 登记運転後
    case 'drive_log':
        if ($method !== 'POST') {
            json_out(405, ['ok' => false, 'error' => 'method']);
        }
        $in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
        $user = require_user($db, (string) ($in['token'] ?? ''));
        if (!(int) $user['drive_enabled']) {
            json_out(403, ['ok' => false, 'error' => 'no_permission']);
        }
        $phase = (string) ($in['phase'] ?? '');
        if (!in_array($phase, ['pre', 'post'], true)) {
            json_out(400, ['ok' => false, 'error' => 'bad_phase']);
        }

        // 数值校验闭包：酒精测定值 mg/L、确认方法 1=IT 2=当面 3=电话、酒气 0=无 1=有
        $normCheck = function ($db, $in) {
            $value = (float) ($in['value'] ?? -1);
            if (!is_numeric($in['value'] ?? 'x') || $value < 0 || $value > 9.99) {
                json_out(400, ['ok' => false, 'error' => 'bad_value']);
            }
            $time = trim((string) ($in['time'] ?? ''));
            if (preg_match('#^\\d{1,2}:\\d{2}$#', $time)) $time .= ':00';
            if (!preg_match('#^\\d{2}:\\d{2}:\\d{2}$#', $time)) {
                json_out(400, ['ok' => false, 'error' => 'bad_time']);
            }
            $method = (int) ($in['method'] ?? 0);
            if (!in_array($method, [1, 2, 3], true)) {
                json_out(400, ['ok' => false, 'error' => 'bad_method']);
            }
            $result = (int) ($in['result'] ?? 0) === 1 ? 1 : 0;
            return [number_format($value, 2), $time, $method, $result];
        };

        if ($phase === 'pre') {
            $date   = (string) ($in['date'] ?? '');
            // 日期非法则退回今天
            if (!preg_match('#^\\d{4}-\\d{2}-\\d{2}$#', $date)) {
                $date = date('Y-m-d');
            }
            $car_id = (int) ($in['car_id'] ?? 0);
            $stCar = $db->prepare('SELECT id FROM cars WHERE id = ? AND active = 1 LIMIT 1');
            $stCar->execute([$car_id]);
            if (!$stCar->fetch()) {
                json_out(400, ['ok' => false, 'error' => 'bad_car']);
            }
            // 当天已有「未登记運転後」的车时，不允许给别的车登记運転前
            $stPending = $db->prepare(
                'SELECT id, car_id FROM drive_logs
                 WHERE user_id = ? AND drv_date = ? AND post_value IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            $stPending->execute([(int) $user['id'], $date]);
            $pending = $stPending->fetch();
            if ($pending && (int) $pending['car_id'] !== $car_id) {
                json_out(409, ['ok' => false, 'error' => 'pending_post']);
            }
            [$value, $time, $method, $result] = $normCheck($db, $in);

            $st = $db->prepare('SELECT id, post_value FROM drive_logs WHERE user_id = ? AND drv_date = ? AND car_id = ? LIMIT 1');
            $st->execute([(int) $user['id'], $date, $car_id]);
            $row = $st->fetch();
            if ($row) {
                // 已登记過運転後的记录不再覆盖，避免前后测定值不一致
                if ($row['post_value'] !== null) {
                    json_out(409, ['ok' => false, 'error' => 'already_completed']);
                }
                $db->prepare(
                    'UPDATE drive_logs SET pre_value = ?, pre_time = ?, pre_method = ?, pre_result = ? WHERE id = ?'
                )->execute([$value, $time, $method, $result, (int) $row['id']]);
                $rid = (int) $row['id'];
            } else {
                $db->prepare(
                    'INSERT INTO drive_logs (user_id, drv_date, car_id, pre_value, pre_time, pre_method, pre_result)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([(int) $user['id'], $date, $car_id, $value, $time, $method, $result]);
                $rid = (int) $db->lastInsertId();
            }
            json_out(200, ['ok' => true, 'id' => $rid, 'phase' => 'pre']);
        }

        // phase === 'post'：按 id 更新運転後数据
        $rid = (int) ($in['id'] ?? 0);
        $stOwn = $db->prepare('SELECT id FROM drive_logs WHERE id = ? AND user_id = ? LIMIT 1');
        $stOwn->execute([$rid, (int) $user['id']]);
        if (!$stOwn->fetch()) {
            json_out(400, ['ok' => false, 'error' => 'bad_record']);
        }
        [$value, $time, $method, $result] = $normCheck($db, $in);
        $noteRaw = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($noteRaw) > 1000) {
            json_out(400, ['ok' => false, 'error' => 'bad_notes']);
        }
        $db->prepare(
            'UPDATE drive_logs SET post_value = ?, post_time = ?, post_method = ?, post_result = ?, notes = ? WHERE id = ?'
        )->execute([$value, $time, $method, $result, $noteRaw === '' ? null : $noteRaw, $rid]);
        json_out(200, ['ok' => true, 'id' => $rid, 'phase' => 'post']);

    // 有效车辆一览
    case 'cars':
        $user = require_user($db, (string) ($_GET['token'] ?? ''));
        $st = $db->query('SELECT id, plate FROM cars WHERE active = 1 ORDER BY sort_order ASC, id ASC');
        json_out(200, ['ok' => true, 'cars' => $st->fetchAll()]);

    // 取得某天指定车辆的最新運転記録（不传 car_id 时取当天最新一条）
    case 'drive_today':
        $user = require_user($db, (string) ($_GET['token'] ?? ''));
        $date  = (string) ($_GET['date'] ?? '');
        if (!preg_match('#^\\d{4}-\\d{2}-\\d{2}$#', $date)) {
            $date = date('Y-m-d');
        }
        $car_id = (int) ($_GET['car_id'] ?? 0);
        $sql = 'SELECT id, car_id, pre_value, pre_time, pre_method, pre_result,
                       post_value, post_time, post_method, post_result, notes
                FROM drive_logs
                WHERE user_id = ? AND drv_date = ?';
        $params = [(int) $user['id'], $date];
        if ($car_id > 0) {
            $sql .= ' AND car_id = ?';
            $params[] = $car_id;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $st = $db->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        if (!$row) {
            json_out(200, ['ok' => true, 'record' => null]);
        }
        // 统一整理成前端需要的结构（pre/post 为 null 表示尚未登记）
        $norm = function ($db, $row) {
            return [
                'id'       => (int) $row['id'],
                'car_id'   => (int) $row['car_id'],
                'pre'      => $row['pre_value'] === null ? null : [
                    'value'  => (float) $row['pre_value'],
                    'time'   => $row['pre_time'],
                    'method' => (int) $row['pre_method'],
                    'result' => (int) $row['pre_result'],
                ],
                'post'     => $row['post_value'] === null ? null : [
                    'value'  => (float) $row['post_value'],
                    'time'   => $row['post_time'],
                    'method' => (int) $row['post_method'],
                    'result' => (int) $row['post_result'],
                ],
                'notes'    => $row['notes'] ?? null,
            ];
        };
        json_out(200, ['ok' => true, 'record' => $norm($db, $row)]);

    // 最近 20 条運転記録（附带车牌）
    case 'drive_history':
        $user = require_user($db, (string) ($_GET['token'] ?? ''));
        $st = $db->prepare(
            'SELECT l.id, l.drv_date, l.car_id, c.plate,
                    l.pre_value, l.pre_time, l.pre_method, l.pre_result,
                    l.post_value, l.post_time, l.post_method, l.post_result,
                    l.notes,
                    DATE_FORMAT(l.noted_at, "%Y-%m-%d %H:%i") AS noted_at
             FROM drive_logs l
             JOIN cars c ON c.id = l.car_id
             WHERE l.user_id = ?
             ORDER BY l.drv_date DESC, l.id DESC LIMIT 20'
        );
        $st->execute([(int) $user['id']]);
        $logs = [];
        foreach ($st->fetchAll() as $row) {
            $logs[] = [
                'id'      => (int) $row['id'],
                'drv_date' => $row['drv_date'],
                'car'     => $row['plate'],
                'pre'     => $row['pre_value'] === null ? null : [
                    'value'  => (float) $row['pre_value'],
                    'time'   => $row['pre_time'],
                    'method' => (int) $row['pre_method'],
                    'result' => (int) $row['pre_result'],
                ],
                'post'    => $row['post_value'] === null ? null : [
                    'value'  => (float) $row['post_value'],
                    'time'   => $row['post_time'],
                    'method' => (int) $row['post_method'],
                    'result' => (int) $row['post_result'],
                ],
                'noted_at' => $row['noted_at'],
                'notes'    => $row['notes'] ?? null,
            ];
        }
        json_out(200, ['ok' => true, 'logs' => $logs]);

    // 未知 action
    default:
        json_out(400, ['ok' => false, 'error' => 'unknown_action']);
}
