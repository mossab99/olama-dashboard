<?php
/** Read-only, date-specific attendance overview. Access is gated by the Hub controller. */
if (!defined('ABSPATH')) { exit; }

class Olama_Attendance_Monitor {
    public static function summarize($students, $records, $completed) {
        $result = ['present' => 0, 'absent' => 0, 'awaiting' => 0];
        foreach ($students as $student) {
            $status = $records[$student->student_uid]->status ?? '';
            if (!isset($completed[$student->section_id]) || !in_array($status, ['present', 'absent'], true)) {
                $result['awaiting']++;
            } else { $result[$status]++; }
        }
        return $result;
    }

    private function label($english, $arabic) { return is_rtl() ? $arabic : $english; }

    public function render() {
        if (!class_exists('Olama_School_Academic') || !class_exists('Olama_Student_Evaluation_DB')) {
            echo '<div class="wrap"><p>' . esc_html($this->label('Activate Olama School and Student Evaluation to use Monitor.', 'يرجى تفعيل المدرسة وتقييم الطلاب لاستخدام المتابعة.')) . '</p></div>';
            return;
        }
        global $wpdb;
        $date = isset($_GET['monitor_date']) ? Olama_School_Helpers::sanitize_date(wp_unslash($_GET['monitor_date'])) : '';
        $date = $date ?: current_time('Y-m-d');
        $grade = absint($_GET['monitor_grade'] ?? 0);
        $year = null; $semester = null;
        foreach (Olama_School_Academic::get_years() as $candidate) {
            if ($date >= $candidate->start_date && $date <= $candidate->end_date) { $year = $candidate; break; }
        }
        if ($year) {
            foreach (Olama_School_Academic::get_semesters($year->id) as $candidate) {
                if ($date >= $candidate->start_date && $date <= $candidate->end_date) { $semester = $candidate; break; }
            }
        }
        $sections = $year ? Olama_School_Section::get_sections_by_year($year->id) : [];
        $grades = [];
        foreach ($sections as $section) { $grades[$section->grade_id] = $section->grade_name; }
        $sections = array_filter($sections, function($section) use ($grade) { return !$grade || (int) $section->grade_id === $grade; });
        $section_map = [];
        foreach ($sections as $section) { $section_map[$section->id] = $section; }
        $students = $year ? Olama_School_Student::get_students(['academic_year_id' => $year->id, 'attendance_only' => true]) : [];
        $by_section = [];
        foreach ($students as $student) {
            if (!isset($section_map[$student->section_id]) || !$student->is_active || in_array(strtolower((string) $student->enrollment_status), ['0', 'inactive', 'disabled', 'withdrawn'], true)) { continue; }
            $by_section[$student->section_id][] = $student;
        }
        $records = []; $completed = []; $first = [];
        $day = (new DateTimeImmutable($date, wp_timezone()))->format('l');
        $school_day = $semester && in_array($day, Olama_School_Helpers::get_school_days(), true);
        $school_day = $school_day && !in_array($date, (array) get_option('olama_monitor_excluded_dates', []), true);
        // Calendar integrations can exclude holidays without changing the monitor.
        $school_day = apply_filters('olama_monitor_is_school_day', (bool) $school_day, $date, $year, $semester);
        if ($year) {
            foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}olama_attendance WHERE academic_year_id = %d AND attendance_date = %s", $year->id, $date)) as $record) { $records[$record->student_uid] = $record; }
            foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}olama_attendance_sheets WHERE academic_year_id = %d AND attendance_date = %s AND status = 'completed'", $year->id, $date)) as $sheet) { $completed[$sheet->section_id] = $sheet; }
        }
        if ($semester) {
            $type = Olama_School_Schedule::is_ramadan($date) ? 'ramadan' : 'normal';
            $slots = $wpdb->get_results($wpdb->prepare(
                "SELECT sch.section_id, sub.subject_name, ta.teacher_id, u.display_name
                 FROM {$wpdb->prefix}olama_schedule sch
                 LEFT JOIN {$wpdb->prefix}olama_subjects sub ON sub.id = sch.subject_id
                 LEFT JOIN {$wpdb->prefix}olama_teacher_assignments ta ON ta.section_id = sch.section_id AND ta.subject_id = sch.subject_id AND ta.academic_year_id = %d
                 LEFT JOIN {$wpdb->users} u ON u.ID = ta.teacher_id
                 WHERE sch.semester_id = %d AND sch.day_name = %s AND sch.schedule_type = %s AND sch.period_number = 1",
                $year->id, $semester->id, $day, $type
            ));
            foreach ($slots as $slot) { $first[$slot->section_id][] = $slot; }
        }
        $totals = ['present' => 0, 'absent' => 0, 'awaiting' => 0];
        $pending = []; $heat = []; $taken = 0; $expected = 0;
        foreach ($by_section as $id => $roster) {
            $counts = self::summarize($roster, $records, $completed);
            foreach ($totals as $key => $value) { $totals[$key] += $counts[$key]; }
            $done = isset($completed[$id]) && !$counts['awaiting'];
            if ($school_day) {
                $expected++;
                if ($done) { $taken++; } else { $pending[$id] = $counts; }
            }
            if ($counts['absent']) { $heat[$id] = ['counts' => $counts, 'rate' => $counts['absent'] / count($roster) * 100]; }
        }
        uasort($heat, function($a, $b) { return $b['rate'] <=> $a['rate']; });
        $deadline = get_option('olama_monitor_attendance_deadline', '09:00');
        $deadline = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $deadline) ? $deadline : '09:00';
        $overdue = $date < current_time('Y-m-d') || ($date === current_time('Y-m-d') && current_time('H:i') >= $deadline);
        wp_enqueue_style('olama-monitor', OLAMA_DASH_URL . 'assets/css/monitor.css', [], (string) filemtime(OLAMA_DASH_PATH . 'assets/css/monitor.css'));
        wp_print_styles('olama-monitor');
        include OLAMA_DASH_PATH . 'admin/views/monitor.php';
    }
}
