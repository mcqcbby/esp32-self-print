<?php
require_once __DIR__ . '/func.php';

function epay_sign($params, $key)
{
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type') continue;
        if ($v === '' || $v === null) continue;
        $pairs[] = $k . '=' . $v;
    }
    return md5(implode('&', $pairs) . $key);
}

function epay_cfg()
{
    $url = rtrim(trim((string)cfg('epay_url')), '/');
    // 后台可以只填域名，也可以直接填到 submit.php 的完整网关地址
    $submit = $url;
    if ($url !== '' && stripos($url, 'submit.php') === false) {
        $submit = $url . '/submit.php';
    }
    return [
        'url' => $url,
        'submit' => $submit,
        'pid' => (string)cfg('epay_pid'),
        'key' => (string)cfg('epay_key'),
    ];
}

function epay_ready()
{
    $c = epay_cfg();
    return $c['url'] !== '' && $c['pid'] !== '' && $c['key'] !== '';
}

function epay_type_name($t)
{
    $map = ['alipay' => '支付宝', 'wxpay' => '微信支付', 'qqpay' => 'QQ钱包', 'bank' => '网银'];
    return isset($map[$t]) ? $map[$t] : $t;
}

function epay_form($order, $type)
{
    $c = epay_cfg();
    $params = [
        'pid' => $c['pid'],
        'type' => $type,
        'out_trade_no' => $order['order_no'],
        'notify_url' => site_url() . '/notify.php',
        'return_url' => site_url() . '/return.php',
        'name' => $order['file_name'] . ' 打印' . $order['pages'] . '页',
        'money' => number_format((float)$order['amount'], 2, '.', ''),
        'sitename' => (string)cfg('site_name', '自助打印'),
        'clientip' => client_ip(),
        'device' => 'pc',
        'sign_type' => 'MD5',
    ];
    $params['sign'] = epay_sign($params, $c['key']);
    $html = '<form id="epayform" method="post" action="' . h($c['submit']) . '">';
    foreach ($params as $k => $v) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    $html .= '</form><script>document.getElementById("epayform").submit();</script>';
    return $html;
}

function epay_verify($params)
{
    $c = epay_cfg();
    if (empty($params['sign'])) return [false, 'no sign'];
    if (!empty($params['pid']) && $params['pid'] !== $c['pid']) return [false, 'pid mismatch'];
    $calc = epay_sign($params, $c['key']);
    if (!hash_equals(strtolower($calc), strtolower($params['sign']))) return [false, 'sign mismatch'];
    return [true, ''];
}
