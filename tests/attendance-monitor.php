<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/admin/class-attendance-monitor.php';
function expect_counts($actual, $expected, $label) {
    if ($actual !== $expected) { throw new RuntimeException($label . ': ' . json_encode($actual)); }
}
$students = [
    (object) ['student_uid' => 'a', 'section_id' => 1],
    (object) ['student_uid' => 'b', 'section_id' => 1],
    (object) ['student_uid' => 'c', 'section_id' => 2],
];
$records = ['a' => (object) ['status' => 'present'], 'b' => (object) ['status' => 'absent']];
expect_counts(Olama_Attendance_Monitor::summarize($students, $records, []), ['present' => 0, 'absent' => 0, 'awaiting' => 3], 'Unsaved sheets cannot imply presence');
expect_counts(Olama_Attendance_Monitor::summarize($students, $records, [1 => true]), ['present' => 1, 'absent' => 1, 'awaiting' => 1], 'Saved sections only');
expect_counts(Olama_Attendance_Monitor::summarize($students, $records, [1 => true, 2 => true]), ['present' => 1, 'absent' => 1, 'awaiting' => 1], 'Missing student record remains awaiting');
$records['c'] = (object) ['status' => 'unexpected'];
expect_counts(Olama_Attendance_Monitor::summarize($students, $records, [1 => true, 2 => true]), ['present' => 1, 'absent' => 1, 'awaiting' => 1], 'Invalid status remains awaiting');
require dirname(__DIR__) . '/admin/class-olama-dashboard-admin.php';
$caps = []; $roles = [];
function current_user_can($cap) { global $caps; return in_array($cap, $caps, true); }
function wp_get_current_user() { global $roles; return (object) ['roles' => $roles]; }
function wp_roles() { return (object) ['role_names' => ['custom_assistant' => 'المساعد']]; }
$hub = new Olama_Dashboard_Admin(false);
if ($hub->can_use_monitor()) { throw new RuntimeException('Unprivileged user can access Monitor'); }
$roles = ['teacher'];
if ($hub->can_use_monitor()) { throw new RuntimeException('Teacher can access Monitor without permission'); }
$roles = ['assistant'];
if (!$hub->can_use_monitor()) { throw new RuntimeException('Assistant must have Monitor access'); }
$roles = ['custom_assistant'];
if (!$hub->can_use_monitor()) { throw new RuntimeException('Custom assistant role must have Monitor access'); }
$roles = []; $caps = ['manage_options'];
if (!$hub->can_use_monitor()) { throw new RuntimeException('Administrator must have Monitor access'); }
$caps = ['olama_monitor_attendance'];
if (!$hub->can_use_monitor()) { throw new RuntimeException('Dedicated Monitor permission must work'); }
echo "Attendance monitor regression checks passed.\n";
