<?php
/**
 * Weather API — uses Open-Meteo (free, no API key)
 *
 * GET /api/weather?date=YYYY-MM-DD&city=Beijing&lat=39.9&lon=116.4
 *   Returns cached or freshly fetched weather for a date.
 *   If date is today, auto-fetches. Past/future dates return cache or empty.
 *
 * POST /api/weather?action=fetch&date=YYYY-MM-DD&city=Beijing&lat=39.9&lon=116.4
 *   Force fetch weather for a specific date (past/future on-demand).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/helpers.php';

$method = get_method();
$db = get_db();

$date = $_GET['date'] ?? today();
$action = $_GET['action'] ?? '';

// Get server location (cached in file, auto-detected from server IP)
function get_server_location(): array {
    $cacheFile = __DIR__ . '/../data/server_location.json';
    // Return cached if fresh (< 30 days)
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && time() - ($cached['ts'] ?? 0) < 2592000) {
            return $cached;
        }
    }
    // Auto-detect from server public IP
    $loc = ['lat' => 39.9042, 'lon' => 116.4074, 'city' => 'Beijing', 'ts' => time()];
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $ip = @file_get_contents('https://api.ipify.org?format=text', false, $ctx);
    if ($ip && trim($ip)) {
        $geo = @file_get_contents("http://ip-api.com/json/" . trim($ip) . "?fields=city,lat,lon", false, $ctx);
        if ($geo) {
            $data = json_decode($geo, true);
            if ($data && !empty($data['lat'])) {
                $loc = ['lat' => (float)$data['lat'], 'lon' => (float)$data['lon'], 'city' => $data['city'] ?: 'Unknown', 'ts' => time()];
            }
        }
    }
    @mkdir(dirname($cacheFile), 0755, true);
    file_put_contents($cacheFile, json_encode($loc, JSON_UNESCAPED_UNICODE));
    return $loc;
}

$loc = get_server_location();
$lat = (float)(!empty($_GET['lat']) ? $_GET['lat'] : ($loc['lat'] ?? 39.9042));
$lon = (float)(!empty($_GET['lon']) ? $_GET['lon'] : ($loc['lon'] ?? 116.4074));
$city = !empty($_GET['city']) ? $_GET['city'] : ($loc['city'] ?? 'Beijing');

// Weather code → emoji + description
function weather_meta(int $code): array {
    $map = [
        0 => ['☀️', '晴', '#4facfe,#00f2fe'],
        1 => ['🌤️', '少云', '#87CEEB,#E0F7FA'],
        2 => ['⛅', '多云', '#B0BEC5,#ECEFF1'],
        3 => ['☁️', '阴', '#90A4AE,#CFD8DC'],
        45 => ['🌫️', '雾', '#B0BEC5,#E0E0E0'],
        48 => ['🌫️', '霜雾', '#B0BEC5,#E0E0E0'],
        51 => ['🌦️', '小雨', '#5C6BC0,#90CAF9'],
        53 => ['🌧️', '中雨', '#3949AB,#64B5F6'],
        55 => ['🌧️', '大雨', '#1A237E,#42A5F5'],
        61 => ['🌧️', '小雨', '#5C6BC0,#90CAF9'],
        63 => ['🌧️', '中雨', '#3949AB,#64B5F6'],
        65 => ['🌧️', '大雨', '#1A237E,#42A5F5'],
        71 => ['❄️', '小雪', '#E3F2FD,#FFFFFF'],
        73 => ['❄️', '中雪', '#BBDEFB,#FFFFFF'],
        75 => ['❄️', '大雪', '#90CAF9,#FFFFFF'],
        77 => ['🌨️', '雪粒', '#E3F2FD,#FFFFFF'],
        80 => ['🌦️', '阵雨', '#5C6BC0,#90CAF9'],
        81 => ['🌧️', '大雨', '#3949AB,#64B5F6'],
        82 => ['⛈️', '暴风雨', '#311B92,#7E57C2'],
        85 => ['🌨️', '小阵雪', '#E3F2FD,#FFFFFF'],
        86 => ['🌨️', '大阵雪', '#BBDEFB,#FFFFFF'],
        95 => ['⛈️', '雷暴', '#311B92,#7E57C2'],
        96 => ['⛈️', '雷暴+冰雹', '#1A1A40,#4A148C'],
        99 => ['⛈️', '强雷暴', '#1A1A40,#4A148C'],
    ];
    return $map[$code] ?? ['🌈', '未知', '#B0BEC5,#ECEFF1'];
}

function fetch_weather(float $lat, float $lon, string $date, string $city = ''): array {
    $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}"
         . "&daily=temperature_2m_max,temperature_2m_min,weathercode,precipitation_sum,windspeed_10m_max,relative_humidity_2m_max"
         . "&timezone=Asia/Shanghai&start_date={$date}&end_date={$date}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || !$resp) throw new Exception("Weather API failed (HTTP $http)");

    $data = json_decode($resp, true);
    $daily = $data['daily'] ?? [];

    $code = (int)($daily['weathercode'][0] ?? 0);
    $meta = weather_meta($code);

    return [
        'date' => $date,
        'city' => $city,
        'code' => $code,
        'emoji' => $meta[0],
        'desc' => $meta[1],
        'gradient' => $meta[2],
        'temp_max' => (float)($daily['temperature_2m_max'][0] ?? 0),
        'temp_min' => (float)($daily['temperature_2m_min'][0] ?? 0),
        'humidity' => (int)($daily['relative_humidity_2m_max'][0] ?? 0),
        'wind' => (float)($daily['windspeed_10m_max'][0] ?? 0),
        'rain' => (float)($daily['precipitation_sum'][0] ?? 0),
    ];
}

// GET: return weather for a date
if ($method === 'GET') {
    // Try cache first
    $stmt = $db->prepare('SELECT data_json, updated_at FROM weather_cache WHERE date = ? AND city = ?');
    $stmt->execute([$date, $city]);
    $row = $stmt->fetch();

    // Auto-fetch if today and no cache or cache is older than 1 hour
    $isToday = ($date === today());
    $cacheStale = !$row || (time() - strtotime($row['updated_at']) > 3600);

    if ($isToday && $cacheStale) {
        try {
            $weather = fetch_weather($lat, $lon, $date, $city);
            $db->prepare('INSERT INTO weather_cache (date, city, data_json) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)')
               ->execute([$date, $city, json_encode($weather, JSON_UNESCAPED_UNICODE)]);
            json_success($weather);
        } catch (Exception $e) {
            // Return stale cache if fetch fails
            if ($row) {
                json_success(json_decode($row['data_json'], true));
            }
            json_error($e->getMessage());
        }
    }

    if ($row) {
        json_success(json_decode($row['data_json'], true));
    } else {
        json_success(null); // No data for this date
    }
}

// POST: force fetch
if ($method === 'POST' && $action === 'fetch') {
    try {
        $weather = fetch_weather($lat, $lon, $date, $city);
        $db->prepare('INSERT INTO weather_cache (date, city, data_json) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE data_json = VALUES(data_json)')
           ->execute([$date, $city, json_encode($weather, JSON_UNESCAPED_UNICODE)]);
        json_success($weather, 'Weather fetched');
    } catch (Exception $e) {
        json_error($e->getMessage());
    }
}

json_error('Method not allowed', 405);
