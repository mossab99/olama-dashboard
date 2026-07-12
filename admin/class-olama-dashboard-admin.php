<?php
/**
 * Olama Dashboard — Admin Controller
 *
 * Handles menu registration, asset enqueuing, page rendering,
 * optional redirect, and the plugin card registry.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Dashboard_Admin {

    public function __construct() {
        // Priority 99 on admin_menu: all sibling plugin menus are registered at 10.
        add_action( 'admin_menu',             [ $this, 'register_menu'    ], 99  );
        // Priority 999: runs after all plugins register their menus, so we can remove selectively.
        add_action( 'admin_menu',             [ $this, 'simplify_sidebar' ], 999 );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets'   ] );
        add_action( 'admin_init',             [ $this, 'maybe_redirect'   ] );
        add_action( 'wp_before_admin_bar_render', [ $this, 'simplify_admin_bar' ] );
        add_action( 'wp_ajax_olama_dashboard_toggle_quick_action', [ $this, 'ajax_toggle_quick_action' ] );
    }

    // ── Menu ─────────────────────────────────────────────────────────────────

    public function register_menu() {
        add_menu_page(
            __( 'Olama Hub', 'olama-dashboard' ),
            __( 'Olama Hub', 'olama-dashboard' ),
            'read',                          // low barrier — capability enforced in render()
            'olama-dashboard',
            [ $this, 'render_hub' ],
            'dashicons-layout',
            2                                // position 2: just below WP Dashboard
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'olama-dashboard' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'olama-hub-style',
            OLAMA_DASH_URL . 'assets/css/hub.css',
            [],
            OLAMA_DASH_VERSION
        );

        wp_enqueue_script(
            'olama-hub-script',
            OLAMA_DASH_URL . 'assets/js/hub.js',
            [ 'jquery' ],
            OLAMA_DASH_VERSION,
            true
        );

        // Data is delivered via a JSON hydration block inside hub.php — NOT wp_localize_script.
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render_hub() {
        include OLAMA_DASH_PATH . 'admin/views/hub.php';
    }

    // ── Optional redirect ─────────────────────────────────────────────────────

    /**
     * Redirects non-admin Olama staff from /wp-admin/ to the hub.
     *
     * Only fires when:
     *  - Current user is NOT an administrator.
     *  - Option `olama_dashboard_redirect` is true (default false — opt-in).
     *  - Constant OLAMA_DASH_NO_REDIRECT is not defined/true.
     *  - User has the hub capability.
     *  - Current page is the bare WP dashboard (index.php).
     *
     * Administrators always land on the standard WordPress dashboard.
     */
    public function maybe_redirect() {
        // Never redirect administrators.
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        // Allow opt-out via constant.
        if ( defined( 'OLAMA_DASH_NO_REDIRECT' ) && OLAMA_DASH_NO_REDIRECT ) {
            return;
        }

        // Opt-out via DB option (default true).
        if ( ! get_option( 'olama_dashboard_redirect', true ) ) {
            return;
        }

        if ( ! olama_dashboard_can_access() ) {
            return;
        }

        // Prevent redirect loops: bail if we're already heading to the hub.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['page'] ) && 'olama-dashboard' === $_GET['page'] ) {
            return;
        }

        // Only redirect from bare WP dashboard (index.php), not from every admin page.
        global $pagenow;
        if ( 'index.php' === $pagenow ) {
            wp_safe_redirect( admin_url( 'admin.php?page=olama-dashboard' ) );
            exit;
        }
    }

    // ── Sidebar simplification ────────────────────────────────────────────────

    /**
     * Hides non-Olama WordPress sidebar entries for non-admin Olama staff.
     *
     * Only the sidebar entries are hidden — the underlying pages remain
     * accessible via direct URL.  Administrators always see the full sidebar.
     *
     * Controls:
     *  - Option  : `olama_dashboard_simplify_sidebar` (bool, default true).
     *  - Constant: define OLAMA_DASH_NO_SIMPLE_SIDEBAR to true to disable entirely.
     *  - Filter  : `olama_dashboard_sidebar_keep_slugs` to add slugs to the keep-list.
     */
    public function simplify_sidebar() {
        // Admins always see the full sidebar.
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        // Must be an Olama-capable user.
        if ( ! olama_dashboard_can_access() ) {
            return;
        }

        // Allow hard opt-out via constant.
        if ( defined( 'OLAMA_DASH_NO_SIMPLE_SIDEBAR' ) && OLAMA_DASH_NO_SIMPLE_SIDEBAR ) {
            return;
        }

        // Enabled by default for non-admin Olama users; disable via option.
        if ( ! get_option( 'olama_dashboard_simplify_sidebar', true ) ) {
            return;
        }

        global $menu;

        // Core slugs to always keep.
        $keep = [
            'olama-dashboard',  // The hub itself.
            'profile.php',      // User profile — always useful.
        ];

        // Keep the User Switching plugin link if it is active.
        if ( class_exists( 'user_switching' ) || function_exists( 'user_switching_init' ) ) {
            $keep[] = 'user-switching';
        }

        /**
         * Filter: olama_dashboard_sidebar_keep_slugs
         * Add additional menu slugs to preserve for Olama staff.
         *
         * @param string[] $keep Array of menu slugs to keep.
         */
        $keep = (array) apply_filters( 'olama_dashboard_sidebar_keep_slugs', $keep );

        if ( ! is_array( $menu ) ) {
            return;
        }

        foreach ( $menu as $item ) {
            if ( ! isset( $item[2] ) || '' === $item[2] ) {
                continue;
            }
            // Leave separators in place so layout does not look broken.
            if ( 0 === strpos( $item[2], 'separator' ) ) {
                continue;
            }
            if ( ! in_array( $item[2], $keep, true ) ) {
                remove_menu_page( $item[2] );
            }
        }
    }

    // ── Admin Bar simplification ──────────────────────────────────────────────

    /**
     * Hides standard WordPress admin bar links (e.g. Dashboard, Themes, Menus)
     * under the site name and other default hubs for non-admin Olama staff.
     */
    public function simplify_admin_bar() {
        // Admins always see the full admin bar.
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        // Must be an Olama-capable user.
        if ( ! olama_dashboard_can_access() ) {
            return;
        }

        // Allow opt-out via the sidebar option since it shares the same intent.
        if ( ! get_option( 'olama_dashboard_simplify_sidebar', true ) ) {
            return;
        }

        global $wp_admin_bar;
        if ( ! is_object( $wp_admin_bar ) ) {
            return;
        }

        // Remove standard dashboard node link.
        $wp_admin_bar->remove_node( 'dashboard' );

        // Remove customization, themes, widgets, menus links from the site title node.
        $wp_admin_bar->remove_node( 'themes' );
        $wp_admin_bar->remove_node( 'widgets' );
        $wp_admin_bar->remove_node( 'menus' );
        $wp_admin_bar->remove_node( 'customize' );

        // Remove comment bubble, WP logo menu, and +New link from the admin bar.
        $wp_admin_bar->remove_node( 'wp-logo' );
        $wp_admin_bar->remove_node( 'comments' );
        $wp_admin_bar->remove_node( 'new-content' );
    }

    // ── Plugin Registry ───────────────────────────────────────────────────────

    /**
     * Returns the full card registry, passed through a filter so future
     * Olama plugins can append their own card without editing this file.
     *
     * @return array[]
     */
    public function build_registry() {
        $cards = [

            // 1 — Olama School System
            [
                'id'          => 'olama-school',
                'label'       => __( 'Olama School', 'olama-dashboard' ),
                'description' => __( 'Weekly plans, attendance, grades, supervision & academic management.', 'olama-dashboard' ),
                'icon'        => 'dashicons-welcome-learn-more',
                'accent'      => '#1a56db',
                'accent_rgb'  => '26,86,219',
                'active'      => defined( 'OLAMA_SCHOOL_FILE' ),
                'capability'  => 'olama_view_dashboard',
                'primary_url' => admin_url( 'admin.php?page=olama-school' ),
                'submenus'    => [
                    [ 'id' => 'school.dashboard', 'label' => __( 'Dashboard',        'olama-dashboard' ), 'icon' => 'dashicons-dashboard',            'url' => admin_url( 'admin.php?page=olama-school' ), 'capability' => 'olama_view_dashboard', 'color' => '#1a56db' ],
                    [ 'id' => 'school.reports', 'label' => __( 'Reports',           'olama-dashboard' ), 'icon' => 'dashicons-chart-bar',            'url' => admin_url( 'admin.php?page=olama-school-reports' ), 'capability' => 'olama_access_reports', 'color' => '#1a56db' ],
                    [ 'id' => 'school.weekly_plans', 'label' => __( 'Weekly Plans',      'olama-dashboard' ), 'icon' => 'dashicons-calendar-alt',         'url' => admin_url( 'admin.php?page=olama-school-plans' ), 'capability' => 'olama_access_plans_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.follow_up', 'label' => __( 'Follow Up',         'olama-dashboard' ), 'icon' => 'dashicons-visibility',           'url' => admin_url( 'admin.php?page=olama-school-follow-up' ), 'capability' => 'olama_access_followup', 'color' => '#1a56db' ],
                    [ 'id' => 'school.academic', 'label' => __( 'Academic',          'olama-dashboard' ), 'icon' => 'dashicons-book',                 'url' => admin_url( 'admin.php?page=olama-school-academic' ), 'capability' => 'olama_access_academic_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.curriculum', 'label' => __( 'Curriculum',        'olama-dashboard' ), 'icon' => 'dashicons-list-view',            'url' => admin_url( 'admin.php?page=olama-school-curriculum' ), 'capability' => 'olama_access_curriculum_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.exams', 'label' => __( 'Exams',             'olama-dashboard' ), 'icon' => 'dashicons-edit',                 'url' => admin_url( 'admin.php?page=olama-school-exams' ), 'capability' => 'olama_access_exams_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.evaluation', 'label' => __( 'Evaluation',        'olama-dashboard' ), 'icon' => 'dashicons-star-filled',          'url' => admin_url( 'admin.php?page=olama-school-evaluation' ), 'capability' => 'olama_access_evaluation', 'color' => '#1a56db' ],
                    [ 'id' => 'school.supervision', 'label' => __( 'Supervision',       'olama-dashboard' ), 'icon' => 'dashicons-admin-users',          'url' => admin_url( 'admin.php?page=olama-school-supervision' ), 'capability' => 'olama_access_supervision', 'color' => '#1a56db' ],
                    [ 'id' => 'school.users', 'label' => __( 'Users',             'olama-dashboard' ), 'icon' => 'dashicons-groups',               'url' => admin_url( 'admin.php?page=olama-school-users' ), 'capability' => 'olama_access_users_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.kg', 'label' => __( 'KG',                'olama-dashboard' ), 'icon' => 'dashicons-smiley',               'url' => admin_url( 'admin.php?page=olama-school-kg' ), 'capability' => 'olama_access_evaluation', 'color' => '#1a56db' ],
                    [ 'id' => 'school.transport', 'label' => __( 'Transport',         'olama-dashboard' ), 'icon' => 'dashicons-car',                  'url' => admin_url( 'admin.php?page=olama-school-transport' ), 'capability' => 'olama_access_transport_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.settings', 'label' => __( 'Settings',          'olama-dashboard' ), 'icon' => 'dashicons-admin-settings',       'url' => admin_url( 'admin.php?page=olama-school-settings' ), 'capability' => 'olama_access_settings_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.exam_halls', 'label' => __( 'Exam Hall',         'olama-dashboard' ), 'icon' => 'dashicons-building',             'url' => admin_url( 'admin.php?page=olama-school-exam-halls' ), 'capability' => 'olama_access_exam_halls', 'color' => '#1a56db' ],
                ],
            ],

            // 2 — Olama Billing (المالية)
            [
                'id'          => 'olama-registration',
                'label'       => __( 'Olama Billing', 'olama-dashboard' ),
                'description' => __( 'Invoices, payments, agreements, fee templates & financial reports.', 'olama-dashboard' ),
                'icon'        => 'dashicons-money-alt',
                'accent'      => '#00a32a',
                'accent_rgb'  => '0,163,42',
                'active'      => class_exists( 'Olama_Reg_Admin' ),
                'capability'  => 'olama_access_registration',
                'primary_url' => admin_url( 'admin.php?page=olama-registration' ),
                'submenus'    => [
                    [ 'id' => 'billing.hub', 'label' => __( 'Customer Hub',       'olama-dashboard' ), 'icon' => 'dashicons-store',               'url' => admin_url( 'admin.php?page=olama-registration' ), 'capability' => 'olama_manage_registration_families', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.contacts', 'label' => __( 'Contacts',           'olama-dashboard' ), 'icon' => 'dashicons-id',                  'url' => admin_url( 'admin.php?page=olama-registration-contacts' ), 'capability' => 'olama_manage_registration_families', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.fees', 'label' => __( 'Fee Templates',      'olama-dashboard' ), 'icon' => 'dashicons-tag',                 'url' => admin_url( 'admin.php?page=olama-registration-fees' ), 'capability' => 'olama_manage_registration_fees', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.agreements', 'label' => __( 'Agreements',         'olama-dashboard' ), 'icon' => 'dashicons-media-document',      'url' => admin_url( 'admin.php?page=olama-registration-agreements' ), 'capability' => 'manage_options', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.invoices', 'label' => __( 'Invoices',           'olama-dashboard' ), 'icon' => 'dashicons-clipboard',           'url' => admin_url( 'admin.php?page=olama-registration-invoices' ), 'capability' => 'olama_manage_registration_invoices', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.payments', 'label' => __( 'Payments',           'olama-dashboard' ), 'icon' => 'dashicons-bank',                'url' => admin_url( 'admin.php?page=olama-registration-payments' ), 'capability' => 'olama_manage_registration_payments', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.financial_accounts', 'label' => __( 'Financial Accounts', 'olama-dashboard' ), 'icon' => 'dashicons-chart-pie',           'url' => admin_url( 'admin.php?page=olama-registration-financial-accounts' ), 'capability' => 'olama_manage_financial_accounts', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.custom_payments', 'label' => __( 'Custom Payments',    'olama-dashboard' ), 'icon' => 'dashicons-insert',              'url' => admin_url( 'admin.php?page=olama-registration-custom-payments' ), 'capability' => 'olama_manage_registration_payments', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.reports', 'label' => __( 'Billing Reports',    'olama-dashboard' ), 'icon' => 'dashicons-analytics',           'url' => admin_url( 'admin.php?page=olama-registration-reports' ), 'capability' => 'olama_manage_registration_reports', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.cash_sessions', 'label' => __( 'Cash Sessions',      'olama-dashboard' ), 'icon' => 'dashicons-vault',               'url' => admin_url( 'admin.php?page=olama-registration-cash-sessions' ), 'capability' => 'olama_manage_registration_payments', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.payment_review', 'label' => __( 'Payment Review',     'olama-dashboard' ), 'icon' => 'dashicons-search',              'url' => admin_url( 'admin.php?page=olama-registration-payment-review' ), 'capability' => 'olama_manage_registration_payments', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.settlements', 'label' => __( 'Settlements',        'olama-dashboard' ), 'icon' => 'dashicons-yes-alt',             'url' => admin_url( 'admin.php?page=olama-registration-settlements' ), 'capability' => 'olama_manage_registration_payments', 'color' => '#00a32a' ],
                    [ 'id' => 'billing.settings', 'label' => __( 'Settings',           'olama-dashboard' ), 'icon' => 'dashicons-admin-settings',      'url' => admin_url( 'admin.php?page=olama-registration-settings' ), 'capability' => 'manage_options', 'color' => '#00a32a' ],
                ],
            ],

            // 3 — Olama Exam Engine
            [
                'id'          => 'olama-exam',
                'label'       => __( 'Exam Engine', 'olama-dashboard' ),
                'description' => __( 'Question bank, school exams, job application tests & grade-level assessments.', 'olama-dashboard' ),
                'icon'        => 'dashicons-welcome-learn-more',
                'accent'      => '#8b5cf6',
                'accent_rgb'  => '139,92,246',
                'active'      => defined( 'OLAMA_EXAM_PATH' ),
                'capability'  => 'olama_manage_question_bank',
                'primary_url' => admin_url( 'admin.php?page=olama-exam' ),
                'submenus'    => [
                    [ 'id' => 'exam.question_bank', 'label' => __( 'Question Bank',  'olama-dashboard' ), 'icon' => 'dashicons-database',         'url' => admin_url( 'admin.php?page=olama-exam' ), 'capability' => 'olama_manage_question_bank', 'color' => '#8b5cf6' ],
                    [ 'id' => 'exam.school_exams', 'label' => __( 'School Exams',   'olama-dashboard' ), 'icon' => 'dashicons-edit-page',        'url' => admin_url( 'admin.php?page=olama-exam-create' ), 'capability' => 'olama_create_exams', 'color' => '#8b5cf6' ],
                    [ 'id' => 'exam.job_apps', 'label' => __( 'Job Apps',       'olama-dashboard' ), 'icon' => 'dashicons-businessman',      'url' => admin_url( 'admin.php?page=oee-professions' ), 'capability' => 'olama_manage_question_bank', 'color' => '#8b5cf6' ],
                    [ 'id' => 'exam.grade_levels', 'label' => __( 'Grade Levels',   'olama-dashboard' ), 'icon' => 'dashicons-editor-ol',        'url' => admin_url( 'admin.php?page=oee-grade-levels' ), 'capability' => 'olama_manage_question_bank', 'color' => '#8b5cf6' ],
                ],
            ],

            // 4 — Olama Messages
            [
                'id'          => 'olama-messages',
                'label'       => __( 'Olama Messages', 'olama-dashboard' ),
                'description' => __( 'SMS campaigns, templates, dispatch queue, sending agents & direct messages.', 'olama-dashboard' ),
                'icon'        => 'dashicons-email-alt',
                'accent'      => '#f59e0b',
                'accent_rgb'  => '245,158,11',
                'active'      => defined( 'OLAMA_MSG_FILE' ),
                'capability'  => 'manage_options',
                'primary_url' => admin_url( 'admin.php?page=olama-messages' ),
                'submenus'    => [
                    [ 'id' => 'messages.dashboard', 'label' => __( 'Dashboard',      'olama-dashboard' ), 'icon' => 'dashicons-dashboard',        'url' => admin_url( 'admin.php?page=olama-messages' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.campaigns', 'label' => __( 'Campaigns',      'olama-dashboard' ), 'icon' => 'dashicons-megaphone',        'url' => admin_url( 'admin.php?page=olama-messages-campaigns' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.templates', 'label' => __( 'Templates',      'olama-dashboard' ), 'icon' => 'dashicons-media-text',       'url' => admin_url( 'admin.php?page=olama-messages-templates' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.queue', 'label' => __( 'SMS Queue',      'olama-dashboard' ), 'icon' => 'dashicons-clock',            'url' => admin_url( 'admin.php?page=olama-messages-queue' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.recipients', 'label' => __( 'Recipients',     'olama-dashboard' ), 'icon' => 'dashicons-groups',           'url' => admin_url( 'admin.php?page=olama-messages-recipients' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.tokens', 'label' => __( 'Report Links',   'olama-dashboard' ), 'icon' => 'dashicons-admin-links',      'url' => admin_url( 'admin.php?page=olama-messages-tokens' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.agents', 'label' => __( 'Sending Agents', 'olama-dashboard' ), 'icon' => 'dashicons-controls-forward', 'url' => admin_url( 'admin.php?page=olama-messages-agents' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.direct', 'label' => __( 'Direct Message', 'olama-dashboard' ), 'icon' => 'dashicons-format-chat',      'url' => admin_url( 'admin.php?page=olama-messages-direct' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.settings', 'label' => __( 'Settings',       'olama-dashboard' ), 'icon' => 'dashicons-admin-settings',   'url' => admin_url( 'admin.php?page=olama-messages-settings' ), 'capability' => 'manage_options', 'color' => '#f59e0b' ],
                ],
            ],

            // 5 — Olama Stores
            [
                'id'          => 'olama-stores',
                'label'       => __( 'Olama Stores', 'olama-dashboard' ),
                'description' => __( 'Inventory, item registry, stock tracking, employee custody & order estimation.', 'olama-dashboard' ),
                'icon'        => 'dashicons-archive',
                'accent'      => '#ef4444',
                'accent_rgb'  => '239,68,68',
                'active'      => defined( 'OS_FILE' ),
                'capability'  => 'os_view_items',
                'primary_url' => admin_url( 'admin.php?page=olama-stores' ),
                'submenus'    => [
                    [ 'id' => 'stores.dashboard', 'label' => __( 'Dashboard',          'olama-dashboard' ), 'icon' => 'dashicons-dashboard',       'url' => admin_url( 'admin.php?page=olama-stores' ), 'capability' => 'os_view_items', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.items', 'label' => __( 'Item Registry',      'olama-dashboard' ), 'icon' => 'dashicons-list-view',       'url' => admin_url( 'admin.php?page=olama-stores-items' ), 'capability' => 'os_view_items', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.add_items', 'label' => __( 'Add Items',          'olama-dashboard' ), 'icon' => 'dashicons-plus-alt',        'url' => admin_url( 'admin.php?page=olama-stores-add-items' ), 'capability' => 'os_manage_items', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.stock', 'label' => __( 'Stock',              'olama-dashboard' ), 'icon' => 'dashicons-store',           'url' => admin_url( 'admin.php?page=olama-stores-stock' ), 'capability' => 'os_view_stock', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.custody', 'label' => __( 'Employee Custody',   'olama-dashboard' ), 'icon' => 'dashicons-businessman',     'url' => admin_url( 'admin.php?page=olama-stores-assignments' ), 'capability' => 'os_view_assignments', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.student_withdrawals', 'label' => __( 'Student Withdrawals','olama-dashboard' ), 'icon' => 'dashicons-migrate',         'url' => admin_url( 'admin.php?page=olama-stores-withdrawals' ), 'capability' => 'os_view_assignments', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.books_withdrawal', 'label' => __( 'Books Withdrawal',   'olama-dashboard' ), 'icon' => 'dashicons-book-alt',        'url' => admin_url( 'admin.php?page=olama-stores-books-withdrawal' ), 'capability' => 'os_view_assignments', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.reports', 'label' => __( 'Reports',            'olama-dashboard' ), 'icon' => 'dashicons-chart-bar',       'url' => admin_url( 'admin.php?page=olama-stores-reports' ), 'capability' => 'os_view_reports', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.order_estimation', 'label' => __( 'Order Estimation',   'olama-dashboard' ), 'icon' => 'dashicons-calculator',      'url' => admin_url( 'admin.php?page=olama-stores-order-estimation' ), 'capability' => 'os_manage_order_estimation', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.inventory_count', 'label' => __( 'Inventory Count',    'olama-dashboard' ), 'icon' => 'dashicons-clipboard',       'url' => admin_url( 'admin.php?page=olama-stores-inventory-count' ), 'capability' => 'os_run_inventory_count', 'color' => '#ef4444' ],
                    [ 'id' => 'stores.settings', 'label' => __( 'Settings',           'olama-dashboard' ), 'icon' => 'dashicons-admin-settings',  'url' => admin_url( 'admin.php?page=olama-stores-settings' ), 'capability' => 'os_manage_settings', 'color' => '#ef4444' ],
                ],
            ],

            // 6 — Oracle Sync
            [
                'id'          => 'olama-oracle-sync',
                'label'       => __( 'Oracle Sync', 'olama-dashboard' ),
                'description' => __( 'Oracle ERP data sync — settings, manual runs, sync history & validation.', 'olama-dashboard' ),
                'icon'        => 'dashicons-update-alt',
                'accent'      => '#0ea5e9',
                'accent_rgb'  => '14,165,233',
                'active'      => defined( 'OLAMA_ORACLE_SYNC_FILE' ),
                'capability'  => 'manage_options',
                'primary_url' => admin_url( 'admin.php?page=olama-oracle-sync' ),
                'submenus'    => [
                    [ 'id' => 'oracle.dashboard', 'label' => __( 'Dashboard',   'olama-dashboard' ), 'icon' => 'dashicons-dashboard',       'url' => admin_url( 'admin.php?page=olama-oracle-sync' ), 'capability' => 'manage_options', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.settings', 'label' => __( 'Settings',    'olama-dashboard' ), 'icon' => 'dashicons-admin-settings',  'url' => admin_url( 'admin.php?page=olama-oracle-sync-settings' ), 'capability' => 'manage_options', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.manual', 'label' => __( 'Manual Sync', 'olama-dashboard' ), 'icon' => 'dashicons-controls-play',   'url' => admin_url( 'admin.php?page=olama-oracle-sync-manual' ), 'capability' => 'manage_options', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.runs', 'label' => __( 'Sync Runs',   'olama-dashboard' ), 'icon' => 'dashicons-clock',           'url' => admin_url( 'admin.php?page=olama-oracle-sync-runs' ), 'capability' => 'manage_options', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.validation', 'label' => __( 'Validation',  'olama-dashboard' ), 'icon' => 'dashicons-yes-alt',         'url' => admin_url( 'admin.php?page=olama-oracle-sync-validation' ), 'capability' => 'manage_options', 'color' => '#0ea5e9' ],
                ],
            ],

            // 7 — Performance Monitor
            [
                'id'          => 'olama-tools',
                'label'       => __( 'Olama Tools', 'olama-dashboard' ),
                'description' => __( 'Performance monitoring, system health & diagnostic tools.', 'olama-dashboard' ),
                'icon'        => 'dashicons-performance',
                'accent'      => '#10b981',
                'accent_rgb'  => '16,185,129',
                'active'      => defined( 'OLAMA_PERF_FILE' ),
                'capability'  => 'manage_options',
                'primary_url' => admin_url( 'admin.php?page=olama-tools' ),
                'submenus'    => [
                    [ 'id' => 'tools.monitor', 'label' => __( 'Performance Monitor', 'olama-dashboard' ), 'icon' => 'dashicons-chart-line', 'url' => admin_url( 'admin.php?page=olama-performance-monitor' ), 'capability' => 'manage_options', 'color' => '#10b981' ],
                ],
            ],

            // 8 — Media Library
            [
                'id'          => 'academy-media-library',
                'label'       => __( 'Media Library', 'olama-dashboard' ),
                'description' => __( 'Curriculum media files, Google Drive uploads & lesson attachments.', 'olama-dashboard' ),
                'icon'        => 'dashicons-format-video',
                'accent'      => '#64748b',
                'accent_rgb'  => '100,116,139',
                'active'      => defined( 'OLAMA_MEDIA_LIBRARY_FILE' ),
                'capability'  => 'read',
                'primary_url' => admin_url( 'admin.php?page=academy-media-library' ),
                'submenus'    => [
                    [ 'id' => 'media.library', 'label' => __( 'Media Library', 'olama-dashboard' ), 'icon' => 'dashicons-format-video', 'url' => admin_url( 'admin.php?page=academy-media-library' ), 'capability' => 'read', 'color' => '#64748b' ],
                ],
            ],
        ];

        /**
         * Filter: olama_dashboard_cards
         *
         * Allows other Olama plugins to append or modify cards in the hub.
         *
         * @param array[] $cards The full card registry.
         */
        return apply_filters( 'olama_dashboard_cards', $cards );
    }

    /**
     * Resolves a unique action ID from the registry (plus virtual actions).
     */
    public function get_action_by_id( $action_id ) {
        if ( 'profile.my_profile' === $action_id ) {
            return [
                'id'         => 'profile.my_profile',
                'label'      => __( 'My Profile', 'olama-dashboard' ),
                'icon'       => 'dashicons-admin-users',
                'url'        => admin_url( 'profile.php' ),
                'capability' => 'read',
                'color'      => '#f59e0b',
            ];
        }

        $cards = $this->build_registry();
        foreach ( $cards as $card ) {
            if ( empty( $card['active'] ) ) {
                continue;
            }
            foreach ( $card['submenus'] as $sub ) {
                if ( isset( $sub['id'] ) && $sub['id'] === $action_id ) {
                    if ( empty( $sub['capability'] ) ) {
                        $sub['capability'] = $card['capability'];
                    }
                    return $sub;
                }
            }
        }
        return null;
    }

    /**
     * Resolves role-based default quick actions.
     */
    public function get_default_quick_actions( $user ) {
        $roles = (array) $user->roles;
        $role  = ! empty( $roles ) ? reset( $roles ) : '';

        $presets = [];
        if ( 'administrator' === $role ) {
            $presets = [ 'school.dashboard', 'billing.hub', 'stores.dashboard', 'oracle.dashboard', 'school.settings' ];
        } elseif ( 'teacher' === $role || 'staff' === $role ) {
            $presets = [ 'school.weekly_plans', 'school.follow_up', 'exam.school_exams', 'media.library', 'profile.my_profile' ];
        } elseif ( 'accountant' === $role ) {
            $presets = [ 'billing.contacts', 'billing.invoices', 'billing.payments', 'billing.cash_sessions', 'billing.reports' ];
        } elseif ( 'supervisor' === $role ) {
            $presets = [ 'school.reports', 'school.weekly_plans', 'school.supervision', 'school.curriculum', 'school.evaluation' ];
        } else {
            $presets = [ 'school.weekly_plans', 'media.library', 'profile.my_profile' ];
        }

        $defaults = [];
        foreach ( $presets as $action_id ) {
            $action_data = $this->get_action_by_id( $action_id );
            if ( $action_data && current_user_can( $action_data['capability'] ) ) {
                $defaults[] = $action_id;
            }
        }
        return $defaults;
    }

    /**
     * Helper to render the Quick Actions tray content.
     */
    public function render_quick_actions_tray_inner( $pinned = null ) {
        if ( $pinned === null ) {
            $pinned = get_user_meta( get_current_user_id(), 'olama_dashboard_quick_actions', true );
            if ( ! is_array( $pinned ) ) {
                $pinned = $this->get_default_quick_actions( wp_get_current_user() );
            }
        }

        $visible_actions = [];
        foreach ( $pinned as $action_id ) {
            $action_data = $this->get_action_by_id( $action_id );
            if ( ! $action_data ) {
                continue;
            }
            if ( ! current_user_can( $action_data['capability'] ) ) {
                continue;
            }
            $visible_actions[] = $action_data;
        }

        if ( empty( $visible_actions ) ) {
            echo '<div class="os-hub-quick-actions-empty">';
            echo esc_html__( 'Pin actions from any module to add them here.', 'olama-dashboard' );
            echo '</div>';
            return;
        }

        foreach ( $visible_actions as $action ) {
            $color = esc_attr( $action['color'] ?? '#1a56db' );
            ?>
            <div class="os-hub-quick-action-wrapper">
                <a href="<?php echo esc_url( $action['url'] ); ?>" class="os-hub-quick-action-pill" style="--action-color: <?php echo $color; ?>;">
                    <span class="dashicons <?php echo esc_attr( $action['icon'] ); ?>" aria-hidden="true"></span>
                    <span class="os-hub-quick-action-label"><?php echo esc_html( $action['label'] ); ?></span>
                </a>
                <button class="os-hub-quick-action-unpin" data-action-id="<?php echo esc_attr( $action['id'] ); ?>" title="<?php esc_attr_e( 'Unpin action', 'olama-dashboard' ); ?>" aria-label="<?php echo sprintf( esc_attr__( 'Unpin %s', 'olama-dashboard' ), esc_attr( $action['label'] ) ); ?>">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <?php
        }
    }

    /**
     * AJAX handler to toggle pinning/unpinning a quick action.
     */
    public function ajax_toggle_quick_action() {
        check_ajax_referer( 'olama_hub_nonce', 'nonce' );

        if ( ! current_user_can( 'read' ) || ! olama_dashboard_can_access() ) {
            wp_send_json_error( [ 'message' => __( 'Forbidden', 'olama-dashboard' ) ], 403 );
        }

        $action_id = isset( $_POST['action_id'] ) ? sanitize_text_field( $_POST['action_id'] ) : '';
        if ( empty( $action_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Missing Action ID', 'olama-dashboard' ) ], 400 );
        }

        $action_data = $this->get_action_by_id( $action_id );
        if ( ! $action_data ) {
            wp_send_json_error( [ 'message' => __( 'Invalid Action ID', 'olama-dashboard' ) ], 400 );
        }

        if ( ! current_user_can( $action_data['capability'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized action', 'olama-dashboard' ) ], 403 );
        }

        $user_id = get_current_user_id();
        $pinned  = get_user_meta( $user_id, 'olama_dashboard_quick_actions', true );
        if ( ! is_array( $pinned ) ) {
            $pinned = $this->get_default_quick_actions( wp_get_current_user() );
        }

        $index = array_search( $action_id, $pinned, true );
        if ( $index !== false ) {
            unset( $pinned[ $index ] );
            $pinned = array_values( $pinned );
            $pinned_status = false;
        } else {
            $pinned[] = $action_id;
            $pinned_status = true;
        }

        update_user_meta( $user_id, 'olama_dashboard_quick_actions', $pinned );

        ob_start();
        $this->render_quick_actions_tray_inner( $pinned );
        $html = ob_get_clean();

        wp_send_json_success( [
            'pinned' => $pinned,
            'status' => $pinned_status,
            'html'   => $html,
        ] );
    }
}
