<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap om-monitor" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
    <header class="om-hero"><div>
    <span class="om-eyebrow"><?php echo esc_html($this->label('DAILY OPERATIONS', 'المتابعة اليومية')); ?></span>
    <h1><?php echo esc_html($this->label('Attendance Monitor', 'متابعة الحضور والغياب')); ?></h1>
    <p><?php echo esc_html(($year->year_name ?? '') . ' · ' . ($semester->semester_name ?? '')); ?></p>
    </div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=olama-dashboard')); ?>"><?php echo esc_html($this->label('School Management System', 'نظام إدارة المدرسة')); ?></a></header>
    <form method="get" class="om-filters">
        <input type="hidden" name="page" value="olama-dashboard"><input type="hidden" name="view" value="monitor">
        <label><?php echo esc_html($this->label('Date', 'التاريخ')); ?> <input type="date" name="monitor_date" value="<?php echo esc_attr($date); ?>" required></label>
        <label><?php echo esc_html($this->label('Grade', 'الصف')); ?> <select name="monitor_grade"><option value="0"><?php echo esc_html($this->label('All grades', 'جميع الصفوف')); ?></option><?php foreach ($grades as $id => $name): ?><option value="<?php echo esc_attr($id); ?>" <?php selected($grade, $id); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
        <button class="button button-primary"><?php echo esc_html($this->label('Update', 'تحديث')); ?></button>
        <span><?php echo esc_html($this->label('Updated: ', 'آخر تحديث: ') . wp_date('H:i:s')); ?></span>
    </form>
    <?php if (current_user_can('manage_options')): ?>
    <details class="om-panel"><summary><?php echo esc_html($this->label('Attendance deadline settings', 'إعدادات موعد تسجيل الحضور')); ?></summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="om-filters">
            <input type="hidden" name="action" value="olama_monitor_settings"><?php wp_nonce_field('olama_monitor_settings'); ?>
            <label><?php echo esc_html($this->label('Submission deadline (school time)', 'موعد التسجيل (بتوقيت المدرسة)')); ?> <input type="time" name="deadline" value="<?php echo esc_attr($deadline); ?>" required></label>
            <label><?php echo esc_html($this->label('Holidays / excluded dates (YYYY-MM-DD, one per line)', 'العطل / التواريخ المستثناة (YYYY-MM-DD، تاريخ لكل سطر)')); ?> <textarea name="excluded_dates" rows="3"><?php echo esc_textarea(implode("\n", (array) get_option('olama_monitor_excluded_dates', []))); ?></textarea></label>
            <button class="button"><?php echo esc_html($this->label('Save', 'حفظ')); ?></button>
        </form>
    </details>
    <?php endif; ?>
    <?php if (!$school_day): ?><p class="om-notice"><?php echo esc_html($this->label('No school session for this date. Missing attendance is not flagged.', 'لا يوجد دوام دراسي لهذا التاريخ. لا يتم احتساب الشعب التي لم تسجل الحضور.')); ?></p><?php endif; ?>
    <div class="om-stats">
        <?php foreach ([['Present', 'حاضر', $totals['present'], 'present'], ['Absent', 'غائب', $totals['absent'], 'absent'], ['Attendance Taken', 'تم تسجيل الحضور', "$taken / $expected", 'taken'], ['Awaiting Attendance', 'بانتظار تسجيل الحضور', $school_day ? $totals['awaiting'] : '—', 'pending']] as $card): ?>
        <div class="om-stat om-<?php echo esc_attr($card[3]); ?>"><div class="om-stat-top"><span><?php echo esc_html($this->label($card[0], $card[1])); ?></span><span class="dashicons dashicons-<?php echo esc_attr(['present'=>'yes-alt','absent'=>'dismiss','taken'=>'clipboard','pending'=>'clock'][$card[3]]); ?>" aria-hidden="true"></span></div><strong><?php echo esc_html($card[2]); ?></strong></div>
        <?php endforeach; ?>
    </div>
    <p><?php echo esc_html($this->label('Present and absent totals include saved attendance only. Awaiting attendance counts students, not sections.', 'إجمالي الحضور والغياب يشمل السجلات المحفوظة فقط. عدد المنتظرين يمثل الطلاب وليس الشعب.')); ?></p>
    <div class="om-progress"><div><strong><?php echo esc_html($this->label('Attendance completion', 'اكتمال تسجيل الحضور')); ?></strong><span><?php echo $expected ? (int) round($taken / $expected * 100) : 0; ?>%</span></div><progress value="<?php echo (int) $taken; ?>" max="<?php echo max(1, $expected); ?>"></progress></div>
    <section class="om-panel">
        <h2><?php echo esc_html($this->label('Attendance Not Taken', 'شعب لم تسجل الحضور')); ?> <span class="om-badge"><?php echo count($pending); ?></span></h2>
        <p><?php echo esc_html($this->label('The first-period teacher is responsible. Submission deadline: ', 'معلم الحصة الأولى مسؤول عن التسجيل. موعد التسجيل: ') . $deadline); ?></p>
        <div class="om-table-wrap"><table class="widefat striped"><thead><tr><?php foreach ([['Section','الشعبة'], ['Responsible Teacher','المعلم المسؤول'], ['First Class','الحصة الأولى'], ['Status','الحالة']] as $heading): ?><th><?php echo esc_html($this->label(...$heading)); ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($pending as $id => $counts):
            $slots = $first[$id] ?? []; $teachers = [];
            foreach ($slots as $slot) { if ($slot->teacher_id && $slot->display_name) { $teachers[$slot->teacher_id] = $slot->display_name; } }
            $review = count($teachers) !== 1;
        ?>
        <tr><td><?php echo esc_html($section_map[$id]->grade_name . ' / ' . $section_map[$id]->section_name); ?>
        <?php if (class_exists('Olama_School_Permissions') && Olama_School_Permissions::is_assistant()): ?>
        <a class="om-cover-link" href="<?php echo esc_url(add_query_arg(['page'=>'olama-dashboard', 'view'=>'attendance', 'section_id'=>$id, 'grade_id'=>$section_map[$id]->grade_id, 'attendance_date'=>$date], admin_url('admin.php'))); ?>"><?php echo esc_html($this->label('Take attendance', 'تسجيل الحضور')); ?></a>
        <?php endif; ?></td><td><?php echo esc_html(implode('، ', $teachers)); ?><?php if ($review): ?> <span class="om-warning"><?php echo esc_html($this->label('Assignment needs review', 'يحتاج الإسناد إلى مراجعة')); ?></span><?php endif; ?></td><td><?php echo esc_html($slots[0]->subject_name ?? $this->label('No first-period schedule', 'لا يوجد جدول للحصة الأولى')); ?></td><td><span class="om-badge"><?php echo esc_html($this->label($overdue ? 'Overdue' : 'Pending', $overdue ? 'متأخر' : 'قيد الانتظار')); ?></span><?php if (isset($completed[$id])): ?> <?php echo esc_html($this->label('Incomplete student records', 'سجلات الطلاب غير مكتملة')); ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$pending): ?><tr><td colspan="4"><?php echo esc_html($this->label($school_day ? 'All sections have submitted attendance.' : 'No attendance expected.', $school_day ? 'جميع الشعب سجلت الحضور.' : 'لا يوجد تسجيل حضور مطلوب.')); ?></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="om-panel">
        <h2><?php echo esc_html($this->label('Absence Heat Map', 'خريطة الغياب')); ?></h2>
        <div class="om-legend"><span class="om-dot om-low"></span> &lt;10% <span class="om-dot om-medium"></span> 10–19% <span class="om-dot om-high"></span> ≥20%</div>
        <p><?php echo esc_html($this->label('Only sections with saved absences are shown. Darker colors indicate higher absence rates. Select a section to see students.', 'تظهر الشعب ذات الغياب المسجل فقط. اللون الأغمق يدل على نسبة غياب أعلى. اختر الشعبة لعرض الطلاب.')); ?></p>
        <div class="om-heat-grid">
        <?php foreach ($heat as $id => $item): $rate = $item['rate']; ?>
            <details class="om-heat om-level-<?php echo $rate >= 20 ? 'high' : ($rate >= 10 ? 'medium' : 'low'); ?>">
                <summary><strong><?php echo esc_html($section_map[$id]->grade_name . ' / ' . $section_map[$id]->section_name); ?></strong><span><?php echo esc_html($item['counts']['absent'] . ' ' . $this->label('absent', 'غائب') . ' · ' . round($rate, 1) . '%'); ?></span></summary>
                <ul><?php foreach ($by_section[$id] as $student): $record = $records[$student->student_uid] ?? null; if (($record->status ?? '') !== 'absent') { continue; } ?><li><?php echo esc_html($student->student_name); ?><?php if ($record->reason): ?> — <?php echo esc_html($record->reason); ?><?php endif; ?></li><?php endforeach; ?></ul>
                <?php $recorder = get_userdata($completed[$id]->recorded_by ?? 0); ?>
                <p><?php echo esc_html($this->label('First submitted: ', 'أول تسجيل: ') . ($completed[$id]->created_at ?? '') . ' · ' . $this->label('Recorded by: ', 'سجله: ') . ($recorder->display_name ?? '—')); ?></p>
            </details>
        <?php endforeach; ?>
        </div>
        <?php if (!$heat): ?><p><?php echo esc_html($this->label('No saved absences for this selection.', 'لا يوجد غياب مسجل لهذا الاختيار.')); ?></p><?php endif; ?>
    </section>
</div>
<script>
(() => {
    const monitor = document.querySelector('.om-monitor');
    let edited = false;
    monitor.querySelectorAll('form').forEach(form => form.addEventListener('change', () => { edited = true; }));
    setInterval(() => {
        if (!edited && !document.hidden && !monitor.querySelector('details[open]')) window.location.reload();
    }, 60000);
})();
</script>
