<?php
header('Cache-Control: no-cache, must-revalidate');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once dirname(__DIR__) . '/lib/print.php';
require_once dirname(__DIR__) . '/lib/epay.php';

function admin_head($title, $active = '')
{
    $site = cfg('site_name', '自助打印');
    $nav = [
        'index' => ['index.php', '概览'],
        'orders' => ['orders.php', '订单'],
        'printers' => ['printers.php', '打印机'],
        'settings' => ['settings.php', '设置'],
    ];
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' - ' . h($site) . ' 后台</title>';
    echo '<link rel="stylesheet" href="../assets/style.css"></head><body><div class="wrap">';
    echo '<div class="topbar"><div class="brand"><span class="logo"></span>' . h($site) . ' 后台</div>';
    echo '<div class="links"><a href="../index.php" target="_blank">前台</a><a href="logout.php">退出</a></div></div>';
    echo '<div class="nav">';
    foreach ($nav as $k => $v) {
        echo '<a class="' . ($k === $active ? 'on' : '') . '" href="' . $v[0] . '">' . $v[1] . '</a>';
    }
    echo '</div>';
    if (!is_admin()) {
        echo '<div class="card"><p class="err">登录已失效，请重新登录。</p><a class="btn" href="login.php">去登录</a></div></div></body></html>';
        exit;
    }
}

function admin_foot()
{
    echo '</div></body></html>';
}
