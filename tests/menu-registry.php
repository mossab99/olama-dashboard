<?php
// Standalone regression check: php tests/menu-registry.php
define('ABSPATH', __DIR__ . '/');
foreach (['OLAMA_EMIS_VERSION', 'OLAMA_SCHOOL_FILE', 'OLAMA_ORACLE_SYNC_FILE', 'OS_FILE', 'OLAMA_PERF_FILE'] as $constant) {
    define($constant, 'test');
}
$options = ['active_plugins' => ['olama-emis/olama-emis.php']];
$administrator = true;
function __($text, $domain = '') { return $text; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = false) { global $options; $options[$key] = $value; }
function delete_option($key) { global $options; unset($options[$key]); }
function current_user_can($capability) { global $administrator; return $administrator; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function get_plugin_page_hookname($page, $parent) { return ($parent ?: 'admin') . '_page_' . $page; }
function apply_filters($hook, $value) { return $value; }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
require dirname(__DIR__) . '/admin/class-olama-dashboard-admin.php';
$hub = new Olama_Dashboard_Admin(false);
$emis = array_values(array_filter($hub->build_registry(), fn($card) => $card['id'] === 'olama-emis'))[0];
check($emis['active'] && count($emis['submenus']) === 8, 'EMIS must include all eight registered pages.');
foreach ($emis['submenus'] as $action) {
    check($action['capability'] === 'olama_users_ministry_view', 'EMIS must retain its Ministry capability.');
}
$menu = [
    ['Oracle', 'olama_access_oracle_sync', 'olama-oracle-sync', '', '', '', 'dashicons-update-alt'],
    ['Gateway', 'manage_options', 'olama-student-gateway', '', '', '', 'dashicons-welcome-learn-more'],
    ['School', 'olama_view_dashboard', 'olama-school', '', '', '', 'dashicons-welcome-learn-more'],
];
$submenu = [
    'olama-oracle-sync' => [
        ['Overview', 'olama_access_oracle_sync', 'olama-oracle-sync'],
        ['Synchronization', 'olama_access_oracle_sync', 'olama-oracle-sync-jobs'],
        ['New Action', 'olama_sync_new', 'olama-oracle-sync-new'],
    ],
    'olama-student-gateway' => [['Settings', 'manage_options', 'olama-student-gateway']],
    'olama-school' => [['Academic', 'read', 'olama-school-academic']],
];
$_registered_pages = ['admin_page_olama-performance-monitor' => true];
$hub->capture_plugin_menus();
// Simulate AJAX: no menu globals, but the persisted complete menu is available.
$menu = $submenu = [];
$cards = array_column($hub->build_registry(), null, 'id');
check(isset($cards['olama-student-gateway']), 'Discover missing plugin cards.');
check($hub->get_action_by_id('oracle.manual')['url'] === admin_url('admin.php?page=olama-oracle-sync-jobs'), 'Keep pinned IDs while updating URLs.');
check($hub->get_action_by_id('olama-oracle-sync.menu.olama-oracle-sync-new')['capability'] === 'olama_sync_new', 'Discovered AJAX actions retain menu capabilities.');
check($hub->get_action_by_id('oracle.settings') === null, 'Remove actions for pages no longer registered.');
check($hub->get_action_by_id('school.academic.calendar')['capability'] === 'olama_manage_academic_calendar', 'Preserve stricter tab permissions.');
check($hub->get_action_by_id('school.academic')['capability'] === 'olama_access_academic_mgmt', 'Read containers must not broaden Hub access.');
check($hub->get_action_by_id('tools.monitor') !== null, 'Keep registered hidden pages.');
$administrator = false;
$hub->capture_plugin_menus();
check(!empty($options['olama_dashboard_menu_snapshot']['parents']), 'Staff menus must not replace the complete snapshot.');
$hub->mark_registry_refresh_pending();
check(!isset($options['olama_dashboard_menu_snapshot']), 'Invalidate discovered routes after plugin or role changes.');
echo "Menu registry regression checks passed.\n";
