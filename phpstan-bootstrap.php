<?php

/**
 * PHPStan bootstrap：宣告本套件執行期依賴、但獨立分析時看不到的
 * CodeIgniter 3 全域函式與常數。
 *
 * ⚠️ 刻意用「逐一列舉」而非 `ignoreErrors: [identifier: function.notFound]`。
 * 後者會讓**任何**拼錯的全域函式靜默通過——把 log_message 打成 log_mesage
 * 要到正式環境才 fatal。這裡只放行確實存在於 CI 的那幾個，其餘未知函式
 * 仍會被 PHPStan 攔下來。
 *
 * 新增用到的 CI 全域函式／常數時，請一併加進這裡；
 * 若 PHPStan 報出這裡沒有的名字，先確認那是不是打錯字。
 *
 * 用 bootstrapFiles 而非 stubFiles：bootstrap 會被 PHPStan 實際執行，
 * define() 出來的常數與 function 宣告因此真的存在；stubFiles 不處理常數。
 */

// ── 常數 ───────────────────────────────────────────────────────────────
defined('BASEPATH') or define('BASEPATH', '/');
defined('ENVIRONMENT') or define('ENVIRONMENT', 'production');

// ── system/core/Common.php ────────────────────────────────────────────
if (!function_exists('log_message')) {
    /** @param string $level ERROR|DEBUG|INFO|ALL（CI 的 Log 不認 WARNING） */
    function log_message($level, $message)
    {
    }
}

if (!function_exists('get_instance')) {
    /** @return object CI_Controller 實例 */
    function &get_instance()
    {
        static $ci;
        $ci = new stdClass();

        return $ci;
    }
}

if (!function_exists('config_item')) {
    /** @return mixed */
    function config_item($item)
    {
    }
}

// ── application helpers ───────────────────────────────────────────────
if (!function_exists('id_encode')) {
    /** @return string */
    function id_encode($id)
    {
    }
}

// ecrowdmedia/common 的 application/helpers/print_helper.php
if (!function_exists('vnsprintf')) {
    /** @return string */
    function vnsprintf($format, array $data)
    {
    }
}

// ── system/helpers，但 Galao 以 MY_file_helper.php:32 覆寫成兩個參數 ──
// （CI3 原生版本只收 $filename；這裡比照呼叫端實際會載入的那一版）
if (!function_exists('get_mime_by_extension')) {
    /** @return string|false */
    function get_mime_by_extension($filename, $default = false)
    {
    }
}

if (!function_exists('valid_email')) {
    /** @return bool */
    function valid_email($email)
    {
    }
}
