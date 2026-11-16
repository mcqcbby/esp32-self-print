<?php
/**
 * 纯 PHP 版自助打印系统 —— 全局配置
 * 运行环境要求：PHP 7.4+ / 8.x + MySQL，无需任何第三方 exe
 * 需要的 PHP 扩展：pdo_mysql、zlib（PHP 自带，用于解码浏览器上传的 PNG）
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
// 页面图目录，浏览器渲染后上传的每页 JPEG，按订单号分文件夹
define('PAGES_DIR', UPLOAD_DIR . '/pages');   // 每一页的图片（浏览器渲染后上传）
// 打印机指令目录，PCL 或 ESC/P-R 通道生成的中间文件
define('PCL_DIR', UPLOAD_DIR . '/pcl');       // 生成的打印机指令
// 运行日志目录，可以整目录清空，不影响业务
define('LOG_DIR', APP_ROOT . '/logs');

/* -------------------- 上传与页数限制 -------------------- */
// 单个文件大小上限（MB）。改这里之外，还要放开 nginx 的 client_max_body_size 与站点 .user.ini
define('MAX_UPLOAD_MB', 10);        // 单个文件
// 一次下单最多允许的页数，防止超大文档拖垮浏览器渲染
define('MAX_PAGES', 200);           // 单次最多页数
// 单页图片大小上限（MB），超过按上传失败处理
define('MAX_PAGE_MB', 12);          // 单页图片上限

/* -------------------- 允许上传的格式 -------------------- */
// 逗号分隔、不要带点。新增格式时前端 render.js 也要能渲染，否则会卡在解析阶段
define('ALLOW_EXT', 'doc,docx,ppt,pptx,xls,xlsx,pdf,png,jpg,jpeg');

/** 打印分辨率：200dpi，A4 可打印区正好 1654x2339 点阵（与浏览器渲染尺寸 1:1，无需缩放） */
// 打印点阵精度。改大更清晰但指令体积翻倍，改小会发虚，保持 200 即可
define('PCL_DPI', 200);
// 打印画布宽（点阵），对应 A4 200dpi 的宽度，不要随便改
define('PCL_W', 1654);
// 打印画布高（点阵），对应 A4 200dpi 的高度，与 PCL_W 必须成比例
define('PCL_H', 2339);

/** 浏览器渲染分辨率（A4 200dpi 比例，再交给服务端放大到 300dpi） */
// 浏览器出图宽度，必须与 PCL_W 一致，否则打印时会缩放变形
define('RENDER_W', 1654);
// 浏览器出图高度，必须与 PCL_H 一致
define('RENDER_H', 2339);

// 启动时自动创建缺失目录，首次部署不用手工建文件夹
foreach ([RAW_DIR, PAGES_DIR, PCL_DIR, LOG_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0777, true);
}
