<?php
/**
 * 纯 PHP 的 IPP 打印客户端（Print-Job），用于 AirPrint / IPP Everywhere 直打。
 * 不依赖 curl 扩展，用 stream_socket_client 走原始 HTTP。
 * 打印机侧自己渲染 PDF，因此不需要在服务器上装任何驱动或转换程序。
 */

function ipp_attr_text($tag, $name, $value)
{
    $name = (string)$name;
    $value = (string)$value;
    return chr($tag)
        . pack('n', strlen($name)) . $name
        . pack('n', strlen($value)) . $value;
}

function ipp_attr_int($name, $value)
{
    $name = (string)$name;
    return chr(0x21) . pack('n', strlen($name)) . $name . pack('n', 4) . pack('N', (int)$value);
}

function ipp_build_print_job($printerUri, $jobName, $user, $copies, $duplex, $paper, $mime = 'application/pdf', $color = null)
{
    $ver = "\x01\x01";                       // IPP 1.1（爱普生只认 1.1 的操作属性组）
    $op = pack('n', 0x0002);                 // Print-Job
    $rid = pack('N', 1);

    $b = $ver . $op . $rid;
    $b .= "\x01";                            // operation attributes group
    $b .= ipp_attr_text(0x47, 'attributes-charset', 'utf-8');
    $b .= ipp_attr_text(0x48, 'attributes-natural-language', 'en');
    $b .= ipp_attr_text(0x45, 'printer-uri', $printerUri);
    $b .= ipp_attr_text(0x42, 'requesting-user-name', $user);
    $b .= ipp_attr_text(0x42, 'job-name', $jobName);
    $b .= ipp_attr_text(0x49, 'document-format', $mime);   // 必须是 mimeMediaType(0x49)

    if ((int)$copies > 1) $b .= ipp_attr_int('copies', (int)$copies);
    $b .= "\x02";                            // job attributes group
    if ((int)$duplex === 1) {
        $b .= ipp_attr_text(0x44, 'sides', 'two-sided-long-edge');
    } else {
        $b .= ipp_attr_text(0x44, 'sides', 'one-sided');
    }
    if ($paper === 'A4' || $paper === '') {
        $b .= ipp_attr_text(0x44, 'media', 'iso_a4_210x297mm');
    } elseif ($paper === 'A3') {
        $b .= ipp_attr_text(0x44, 'media', 'iso_a3_297x420mm');
    }
    if ($color !== null) {
        // 黑白单只让打印机用黑墨，避免彩墨混出灰
        $b .= ipp_attr_text(0x44, 'print-color-mode', ((int)$color === 1) ? 'color' : 'monochrome');
    }
    $b .= "\x03";                            // end-of-attributes
    return $b;
}

/** 直接把一张 JPEG 作为打印作业交给打印机（爱普生支持 image/jpeg） */
function ipp_print_jpeg($httpUrl, $jpgPath, $jobName, $duplex = 0, $paper = 'A4', $color = null)
{
    if (!is_file($jpgPath)) return [false, '找不到页面图片'];
    $img = file_get_contents($jpgPath);
    if ($img === false || strlen($img) < 1024) return [false, '页面图片为空'];

    $u = @parse_url($httpUrl);
    if (!$u || empty($u['host'])) return [false, '未配置打印机 IPP 地址'];
    $port = isset($u['port']) ? (int)$u['port'] : 631;
    $path = isset($u['path']) && $u['path'] !== '' ? $u['path'] : '/ipp/print';
    $scheme = (isset($u['scheme']) && strtolower($u['scheme']) === 'https') ? 'ipps' : 'ipp';
    $printerUri = $scheme . '://' . $u['host'] . ':' . $port . $path;

    $body = ipp_build_print_job($printerUri, $jobName, 'printbox', 1, $duplex, $paper, 'image/jpeg', $color);
    $body .= $img;
    return ipp_http_post($httpUrl, $body, 60);
}

function ipp_http_post($url, $body, $timeout = 30)
{
    $u = @parse_url($url);
    if (!$u || empty($u['host'])) return [false, '打印机地址格式不正确'];
    $host = $u['host'];
    $port = isset($u['port']) ? (int)$u['port'] : 631;
    $path = isset($u['path']) ? $u['path'] : '/ipp/print';
    if ($path === '' || $path === '/') $path = '/ipp/print';
    if (isset($u['query'])) $path .= '?' . $u['query'];

    // 支持 http / https（爱普生只认加密 IPP，必须走 https）
    $scheme = (isset($u['scheme']) && strtolower($u['scheme']) === 'https') ? 'https' : 'http';
    if ($scheme === 'https') {
        if (!in_array('ssl', stream_get_transports(), true)) {
            return [false, 'PHP 没有开启 openssl 扩展，无法用 https 连接打印机（请在宝塔 PHP 设置里安装/启用 openssl）'];
        }
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'ciphers' => 'DEFAULT@SECLEVEL=0',
        ]]);
        $fp = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    } else {
        $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 8);
    }
    if (!$fp) return [false, "无法连接打印机 $host:$port（$errstr）"];

    stream_set_timeout($fp, $timeout);
    $head = "POST $path HTTP/1.1\r\n"
        . "Host: $host:$port\r\n"
        . "Content-Type: application/ipp\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "User-Agent: PrintBox/1.0\r\n"
        . "Connection: close\r\n\r\n";
    @fwrite($fp, $head . $body);

    $raw = '';
    while (!feof($fp)) {
        $chunk = @fread($fp, 8192);
        if ($chunk === false) break;
        if ($chunk === '') {
            $info = stream_get_meta_data($fp);
            if ($info['timed_out']) break;
        }
        $raw .= $chunk;
    }
    @fclose($fp);

    if ($raw === '') return [false, '打印机没有返回数据'];

    $pos = strpos($raw, "\r\n\r\n");
    if ($pos === false) return [false, '打印机返回格式异常'];
    $headers = substr($raw, 0, $pos);
    $data = substr($raw, $pos + 4);

    if (preg_match('/HTTP\/1\.[01]\s+(\d{3})/', $headers, $m)) {
        $code = (int)$m[1];
        if ($code === 426) {
            if ($scheme === 'https') {
                return [false, '打印机拒绝了加密 IPP（HTTP 426）'];
            }
            return [false, '打印机只接受 HTTPS 的 IPP（HTTP 426）：请把后台打印机地址改成 https://192.168.1.105:631/ipp/print，或去 Web Config 允许非加密通信'];
        }
        if ($code >= 400) return [false, '打印机返回 HTTP ' . $code];
    }
    if (stripos($headers, 'Transfer-Encoding: chunked') !== false) {
        $data = ipp_dechunk($data);
    } elseif (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
        $data = substr($data, 0, (int)$m[1]);
    }
    if (strlen($data) < 8) return [false, 'IPP 响应过短'];

    $status = unpack('n', substr($data, 2, 2));
    $status = $status[1];
    if ($status <= 0x00ff) return [true, 'ok'];

    $msg = ipp_status_text($status);
    return [false, 'IPP 状态 ' . sprintf('0x%04X', $status) . '（' . $msg . '）'];
}

function ipp_dechunk($s)
{
    $out = '';
    $i = 0;
    $len = strlen($s);
    while ($i < $len) {
        $eol = strpos($s, "\r\n", $i);
        if ($eol === false) break;
        $size = hexdec(trim(substr($s, $i, $eol - $i)));
        $i = $eol + 2;
        if ($size === 0) break;
        $out .= substr($s, $i, $size);
        $i += $size + 2;
    }
    return $out;
}

function ipp_status_text($code)
{
    $map = [
        0x0400 => 'bad-request（请求格式不被接受）',
        0x0401 => 'forbidden（禁止访问）',
        0x0405 => 'not-authenticated',
        0x0406 => 'not-authorized',
        0x040A => 'document-format-not-supported（打印机不支持该文档格式）',
        0x040B => 'attributes-or-values-not-supported',
        0x0500 => 'internal-error（打印机内部错误）',
        0x0501 => 'operation-not-supported',
        0x0502 => 'service-unavailable',
        0x0507 => 'printer-busy（打印机正忙：缺纸/卡纸/报错，请到打印机面板看提示）',
    ];
    return isset($map[$code]) ? $map[$code] : 'unknown';
}

/** 提交一份 PDF 打印作业 */
function ipp_print_pdf($httpUrl, $pdfPath, $jobName, $copies = 1, $duplex = 0, $paper = 'A4')
{
    if (!is_file($pdfPath)) return [false, '找不到要打印的 PDF'];
    $size = filesize($pdfPath);
    if ($size <= 0) return [false, 'PDF 文件为空'];

    $u = @parse_url($httpUrl);
    if (!$u || empty($u['host'])) return [false, '未配置打印机 IPP 地址'];
    $port = isset($u['port']) ? (int)$u['port'] : 631;
    $path = isset($u['path']) && $u['path'] !== '' ? $u['path'] : '/ipp/print';
    $scheme = (isset($u['scheme']) && strtolower($u['scheme']) === 'https') ? 'ipps' : 'ipp';
    $printerUri = $scheme . '://' . $u['host'] . ':' . $port . $path;

    $body = ipp_build_print_job($printerUri, $jobName, 'printbox', $copies, $duplex, $paper);
    $body .= file_get_contents($pdfPath);

    return ipp_http_post($httpUrl, $body, 60);
}
