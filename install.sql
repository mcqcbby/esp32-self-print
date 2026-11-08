SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_no` varchar(32) NOT NULL DEFAULT '',
  `trade_no` varchar(64) NOT NULL DEFAULT '',
  `file_name` varchar(255) NOT NULL DEFAULT '',
  `file_path` varchar(255) NOT NULL DEFAULT '',
  `pdf_path` varchar(255) NOT NULL DEFAULT '',
  `pages_dir` varchar(255) NOT NULL DEFAULT '',
  `ext` varchar(10) NOT NULL DEFAULT '',
  `pages` int(11) NOT NULL DEFAULT 0,
  `copies` int(11) NOT NULL DEFAULT 1,
  `color` tinyint(4) NOT NULL DEFAULT 0,
  `duplex` tinyint(4) NOT NULL DEFAULT 0,
  `paper` varchar(10) NOT NULL DEFAULT 'A4',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` tinyint(4) NOT NULL DEFAULT 0,
  `pay_type` varchar(20) NOT NULL DEFAULT '',
  `buyer` varchar(64) NOT NULL DEFAULT '',
  `phone` varchar(20) NOT NULL DEFAULT '',
  `dorm` varchar(20) NOT NULL DEFAULT '',
  `pick_code` varchar(8) NOT NULL DEFAULT '',
  `target` varchar(10) NOT NULL DEFAULT 'esp32',
  `printer` varchar(64) NOT NULL DEFAULT '',
  `print_msg` varchar(255) NOT NULL DEFAULT '',
  `client_ip` varchar(64) NOT NULL DEFAULT '',
  `create_time` int(11) NOT NULL DEFAULT 0,
  `pay_time` int(11) NOT NULL DEFAULT 0,
  `print_time` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_status` (`status`),
  KEY `idx_create` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` varchar(64) NOT NULL,
  `v` text,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `printers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `type` varchar(12) NOT NULL DEFAULT 'windows',
  `device` varchar(160) NOT NULL DEFAULT '',
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `online` tinyint(4) NOT NULL DEFAULT 0,
  `last_seen` int(11) NOT NULL DEFAULT 0,
  `jobs` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`k`,`v`) VALUES
('site_name','自助打印'),
('epay_url',''),
('epay_pid',''),
('epay_key',''),
('pay_types','alipay,qqpay'),
('price_bw','0.40'),
('price_color','0.50'),
('price_bw_duplex','0.60'),
('price_color_duplex','0.80'),
('min_amount','0.10'),
('print_target','esp32'),
('pcl_color','0'),
('print_mode','esp32'),
('printer_lang','escpr'),
('ipp_url',''),
('site_domain',''),
('agent_token','change-me-token'),
('agent_poll','2'),
('admin_pass',''),
('pick_prefix','P');
