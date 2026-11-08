<?php
/**
 * 纯 PHP 版自助打印系统 —— 全局配置示例
 * 复制本文件为 config.php，再按实际环境填写
 * 运行环境：PHP 7.4+ / 8.x + MySQL，无需任何第三方 exe
 * 需要扩展：pdo_mysql、zlib
 */

/* -------------------- 数据库连接 -------------------- */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'print');       // 数据库名
define('DB_USER', 'print');       // 数据库账号
define('DB_PASS', '');            // 数据库密码

/* -------------------- 目录与路径 -------------------- */
// 站点根目录，自动取当前文件所在目录，不需要手改
define('APP_ROOT', __DIR__);
define('UPLOAD_DIR', APP_ROOT . '/uploads');
// 原始文件目录，docx、pdf 等用户上传的原件
define('RAW_DIR', UPLOAD_DIR . '/raw');
// 页面图目录，浏览器渲染后上传的每页图片，按订单号分文件夹
define('PAGES_DIR', UPLOAD_DIR . '/pages');
// 打印机指令目录，PCL 或 ESC/P-R 通道生成的中间文件
define('PCL_DIR', UPLOAD_DIR . '/pcl');
// 运行日志目录，可以整目录清空，不影响业务
define('LOG_DIR', APP_ROOT . '/logs');

/* -------------------- 上传与页数限制 -------------------- */
// 单个文件大小上限（MB）。改这里之外，还要放开 nginx 的 client_max_body_size 与站点 .user.ini
define('MAX_UPLOAD_MB', 10);
// 一次下单最多允许的页数，防止超大文档拖垮浏览器渲染
define('MAX_PAGES', 200);
// 单页图片大小上限（MB）
define('MAX_PAGE_MB', 12);

/* -------------------- 允许上传的格式 -------------------- */
// 逗号分隔、不要带点。新增格式时前端 render.js 也要能渲染
define('ALLOW_EXT', 'doc,docx,ppt,pptx,xls,xlsx,pdf,png,jpg,jpeg');

/** 打印分辨率：200dpi，A4 可打印区正好 1654x2339 点阵 */
define('PCL_DPI', 200);
define('PCL_W', 1654);
define('PCL_H', 2339);

/** 浏览器渲染分辨率，必须与 PCL_W/PCL_H 一致，否则打印会缩放变形 */
define('RENDER_W', 1654);
define('RENDER_H', 2339);

// 启动时自动创建缺失目录，首次部署不用手工建文件夹
foreach ([RAW_DIR, PAGES_DIR, PCL_DIR, LOG_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}
