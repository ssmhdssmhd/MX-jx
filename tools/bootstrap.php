<?php
/**
 * tools/ 目录统一入口
 * 使用方式（在 admin.php 或其他 PHP 文件中）：
 *
 *   require_once __DIR__ . '/tools/bootstrap.php';
 *   $manager = ToolManager::getInstance();
 *   $list    = $manager->listTools();
 *   $result  = $manager->runTool('ad_cleaner_m3u8', ['input' => '...M3U8内容...']);
 *
 * 也可以通过 HTTP 接口调用：
 *   GET  /tools/handler.php?action=list
 *   POST /tools/handler.php  body: action=run&tool_id=xxx&param1=yyy
 */

require_once __DIR__ . '/core/AbstractTool.php';
require_once __DIR__ . '/core/ToolManager.php';
