<?php
/**
 * Plugin Entry Point (Router & Diagnostic View)
 * Path: /htdocs/content/plugins/abcd_updater_hotfix/index.php
 */

declare(strict_types=1);

$config_path = realpath(__DIR__ . '/../../../central/config.php');
if ($config_path !== false && file_exists($config_path)) {
    require_once $config_path;
}

if (!class_exists('PluginBridge') && !class_exists('\ABCD\Common\PluginBridge')) {
    header("HTTP/1.1 403 Forbidden");
    die("Direct access forbidden.");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $msgstr, $lang;

require_once __DIR__ . '/plugin-bootstrap.php';
abcd_hotfix_load_translations();

if (!isset($_SESSION['permiso'])) {
    header("Location: /central/common/error_page.php");
    exit;
}

$patch_result = abcd_hotfix_apply_patch();

include(ABCD_CENTRAL_PATH . "common/get_post.php");
include(ABCD_CENTRAL_PATH . "lang/dbadmin.php");

$is_err = ($patch_result['status'] === 'error');
$box_bg = $is_err ? '#dc3545' : '#28a745';
?>

<div class="middle form">
    <div class="formContent" style="max-width: 720px; margin: 30px auto; padding: 25px;">
        <h2><?php echo $msgstr['hotfix_page_title']; ?></h2>
        <div style="background: <?php echo $box_bg; ?>; color: #fff; padding: 16px; border-radius: 5px; margin: 20px 0;">
            <strong><?php echo $patch_result['message']; ?></strong>
        </div>

        <?php if (!$is_err): ?>
            <p><?php echo $msgstr['hotfix_ready_desc']; ?></p>
            <div style="margin-top: 20px;">
                <a href="/update_manager.php" class="bt bt-green">
                    <i class="fas fa-sync-alt"></i> &nbsp; <?php echo $msgstr['hotfix_btn_go']; ?>
                </a>
            </div>
        <?php else: ?>
            <div style="margin-top: 20px;">
                <a href="?action=retry&nocache=<?php echo time(); ?>" class="bt bt-blue">
                    <i class="fas fa-redo"></i> &nbsp; <?php echo $msgstr['hotfix_btn_retry']; ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
