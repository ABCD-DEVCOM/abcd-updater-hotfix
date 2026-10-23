<?php
/**
 * Plugin Name: ABCD Update Manager Hotfix
 * File: plugin-bootstrap.php
 * Description: Hooks into ABCD Central to atomically patch update_manager.php on v4.0.0/v4.0.1.
 * Author: Roger C. Guilherme
 * Requires PHP: 8.1+
 */

declare(strict_types=1);

if (!function_exists('abcd_add_hook')) {
    header("HTTP/1.1 403 Forbidden");
    die("Direct access forbidden.");
}

if (!function_exists('abcd_hotfix_load_translations')) {
    /**
     * Loads translations using LanguageManager (if available) with a safe fallback parser
     * following the cascade: content/lang/{lang} -> plugin/lang/{lang} -> plugin/lang/en.
     */
    function abcd_hotfix_load_translations(): void
    {
        global $msgstr, $langManager, $lang;

        if (!is_array($msgstr)) {
            $msgstr = [];
        }

        $current_lang = $_SESSION['lang'] ?? $lang ?? 'en';
        $plugin_dir   = __DIR__;
        $plugin_slug  = basename(__DIR__);

        if (isset($langManager) && method_exists($langManager, 'loadPluginTranslations')) {
            $plugin_msgs = $langManager->loadPluginTranslations($plugin_dir, $plugin_slug, 'hotfix.tab', (string)$current_lang);
            $msgstr = array_merge($plugin_msgs, $msgstr);
            return;
        }

        $parse_tab = static function (string $file): array {
            $entries = [];
            if (!is_readable($file)) {
                return $entries;
            }
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                return $entries;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $entries[trim($k)] = trim($v);
            }
            return $entries;
        };

        $en_msgs   = $parse_tab($plugin_dir . '/lang/en/hotfix.tab');
        $lang_msgs = ($current_lang !== 'en') ? $parse_tab($plugin_dir . "/lang/{$current_lang}/hotfix.tab") : [];

        $vault_file = defined('ABCD_CONTENT_PATH')
            ? rtrim((string)ABCD_CONTENT_PATH, '/\\') . "/lang/{$current_lang}/hotfix.tab"
            : '';
        $vault_msgs = ($vault_file !== '' && is_readable($vault_file)) ? $parse_tab($vault_file) : [];

        $msgstr = array_merge($en_msgs, $lang_msgs, $vault_msgs, $msgstr);
    }
}

if (!function_exists('abcd_hotfix_apply_patch')) {
    /**
     * Atomically patches /htdocs/update_manager.php after syntax verification.
     *
     * @return array{status: string, message: string}
     */
    function abcd_hotfix_apply_patch(): array
    {
        global $msgstr, $ABCD_scripts_path;

        abcd_hotfix_load_translations();

        // Resolve htdocs root reliably across all ABCD contexts
        $htdocs_dir = !empty($ABCD_scripts_path)
            ? rtrim((string)$ABCD_scripts_path, '/\\')
            : realpath(__DIR__ . '/../../..');

        if ($htdocs_dir === false || !is_dir($htdocs_dir)) {
            return [
                'status'  => 'error',
                'message' => sprintf($msgstr['hotfix_err_perm'], 'htdocs')
            ];
        }

        $target_manager = $htdocs_dir . DIRECTORY_SEPARATOR . 'update_manager.php';
        $backup_manager = $htdocs_dir . DIRECTORY_SEPARATOR . 'update_manager.php.bak';
        $temp_manager   = $htdocs_dir . DIRECTORY_SEPARATOR . 'update_manager.php.tmp';
        $source_payload = __DIR__ . DIRECTORY_SEPARATOR . 'payload_update_manager.php';

        // 1. Check if update_manager.php is already patched (contains 'step_done')
        if (is_readable($target_manager)) {
            $current_code = file_get_contents($target_manager);
            if ($current_code !== false && str_contains($current_code, 'step_done')) {
                return [
                    'status'  => 'already_patched',
                    'message' => $msgstr['hotfix_already_patched']
                ];
            }
        }

        // 2. Verify payload file readability
        if (!is_readable($source_payload)) {
            return [
                'status'  => 'error',
                'message' => sprintf($msgstr['hotfix_err_payload'], $source_payload)
            ];
        }

        $payload_code = file_get_contents($source_payload);
        if ($payload_code === false || $payload_code === '') {
            return [
                'status'  => 'error',
                'message' => sprintf($msgstr['hotfix_err_payload'], $source_payload)
            ];
        }

        // 3. Validate PHP syntax of payload using token_get_all
        try {
            token_get_all($payload_code, TOKEN_PARSE);
        } catch (\Throwable $e) {
            return [
                'status'  => 'error',
                'message' => sprintf($msgstr['hotfix_err_syntax'], $e->getMessage())
            ];
        }

        // 4. Verify write permissions on htdocs and target file
        if (!is_writable($htdocs_dir) || (file_exists($target_manager) && !is_writable($target_manager))) {
            return [
                'status'  => 'error',
                'message' => sprintf($msgstr['hotfix_err_perm'], $htdocs_dir)
            ];
        }

        // 5. Write to temporary file and verify SHA-256 + syntax
        if (@file_put_contents($temp_manager, $payload_code, LOCK_EX) === false) {
            return [
                'status'  => 'error',
                'message' => sprintf($msgstr['hotfix_err_write_tmp'], $temp_manager)
            ];
        }

        $written_code = @file_get_contents($temp_manager);
        if ($written_code === false || hash('sha256', $written_code) !== hash('sha256', $payload_code)) {
            @unlink($temp_manager);
            return [
                'status'  => 'error',
                'message' => sprintf($msgstr['hotfix_err_write_tmp'], $temp_manager)
            ];
        }

        try {
            token_get_all($written_code, TOKEN_PARSE);
        } catch (\Throwable $e) {
            @unlink($temp_manager);
            return [
                'status'  => 'error',
                'message' => sprintf($msgstr['hotfix_err_syntax'], $e->getMessage())
            ];
        }

        // 6. Keep a backup of the original update_manager.php
        if (file_exists($target_manager)) {
            if (!@copy($target_manager, $backup_manager)) {
                @unlink($temp_manager);
                return [
                    'status'  => 'error',
                    'message' => sprintf($msgstr['hotfix_err_backup'], $backup_manager)
                ];
            }
        }

        // 7. Atomic rename over target file (with Windows copy fallback)
        if (!@rename($temp_manager, $target_manager)) {
            if (!@copy($temp_manager, $target_manager)) {
                @unlink($temp_manager);
                if (file_exists($backup_manager)) {
                    @copy($backup_manager, $target_manager);
                }
                return [
                    'status'  => 'error',
                    'message' => sprintf($msgstr['hotfix_err_rename'], $target_manager)
                ];
            }
            @unlink($temp_manager);
        }

        // 8. Invalidate OPcache and file stat cache immediately
        clearstatcache(true, $target_manager);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target_manager, true);
        }

        return [
            'status'  => 'patched',
            'message' => $msgstr['hotfix_applied_ok']
        ];
    }
}

// Hook 1: Run inside 'abcd_footer_end' (where $_SESSION is already active) to apply the patch
// and display a top notification banner to the administrator.
abcd_add_hook('abcd_footer_end', static function (mixed $output = ''): string {
    global $msgstr;

    $output_str = is_string($output) ? $output : '';

    if (session_status() === PHP_SESSION_NONE) {
        return $output_str;
    }

    // Only execute for logged-in administrators
    $is_adm = (isset($_SESSION['profile']) && $_SESSION['profile'] === 'adm')
        || isset($_SESSION['permiso']['CENTRAL_ALL']);
    if (!$is_adm) {
        return $output_str;
    }

    $result = abcd_hotfix_apply_patch();

    // If it was already patched in a previous request, stay silent unless on the plugin page
    if ($result['status'] === 'already_patched') {
        return $output_str;
    }

    $is_error = ($result['status'] === 'error');
    $bg_color = $is_error ? '#dc3545' : '#28a745';
    $msg_js   = json_encode($result['message'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $btn_js   = json_encode($msgstr['hotfix_btn_go'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $banner_script = "<script>
    (function() {
        var bar = document.createElement('div');
        bar.style.cssText = 'background:{$bg_color};color:#fff;padding:12px 20px;text-align:center;font-family:sans-serif;font-size:14px;z-index:99999;position:relative;box-shadow:0 2px 6px rgba(0,0,0,0.2);';
        var strong = document.createElement('strong');
        strong.textContent = {$msg_js};
        bar.appendChild(strong);
        " . (!$is_error ? "
        var link = document.createElement('a');
        link.href = '/update_manager.php';
        link.textContent = {$btn_js};
        link.style.cssText = 'color:#fff;text-decoration:underline;font-weight:bold;margin-left:15px;';
        bar.appendChild(link);
        " : "") . "
        document.body.insertBefore(bar, document.body.firstChild);
    })();
    </script>";

    echo $banner_script;
    return $output_str . PHP_EOL . $banner_script;
});
