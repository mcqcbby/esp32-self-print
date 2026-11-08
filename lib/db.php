<?php
require_once dirname(__DIR__) . '/config.php';

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function q($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one($sql, $params = [])
{
    return q($sql, $params)->fetch();
}

function all($sql, $params = [])
{
    return q($sql, $params)->fetchAll();
}

function cfg($key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT k,v FROM settings') as $r) $cache[$r['k']] = $r['v'];
    }
    return isset($cache[$key]) ? $cache[$key] : $default;
}

function cfg_set($key, $value)
{
    q('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$key, (string)$value]);
}
