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

    public function __construct( $register_hooks = true ) {
        if ( ! $register_hooks ) {
            return;
        }
        // Priority 99 on admin_menu: all sibling plugin menus are registered at 10.
        add_action( 'admin_menu',             [ $this, 'register_menu'    ], 99  );
        add_action( 'admin_menu',             [ $this, 'capture_plugin_menus' ], 998 );
        // Priority 999: runs after all plugins register their menus, so we can remove selectively.
        add_action( 'admin_menu',             [ $this, 'simplify_sidebar' ], 999 );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets'   ] );
        add_action( 'admin_init',             [ $this, 'maybe_redirect'   ] );
        add_action( 'admin_init',             [ $this, 'maybe_refresh_pending_registry' ], 100 );
        add_action( 'admin_post_olama_dashboard_refresh_registry', [ $this, 'handle_registry_refresh' ] );
        add_action( 'olama_users_capabilities_updated', [ $this, 'refresh_after_capability_update' ], 10, 3 );
        add_action( 'updated_option', [ $this, 'watch_role_capability_updates' ], 10, 3 );
        add_action( 'upgrader_process_complete', [ $this, 'mark_registry_refresh_pending' ], 10, 0 );
        add_action( 'activated_plugin', [ $this, 'mark_registry_refresh_pending' ], 10, 0 );
        add_action( 'deactivated_plugin', [ $this, 'mark_registry_refresh_pending' ], 10, 0 );
        add_action( 'wp_before_admin_bar_render', [ $this, 'simplify_admin_bar' ] );
        add_action( 'wp_ajax_olama_dashboard_toggle_quick_action', [ $this, 'ajax_toggle_quick_action' ] );
    }

    // ── Menu ─────────────────────────────────────────────────────────────────

    public function register_menu() {
        add_menu_page(
            __( 'School Management System', 'olama-dashboard' ),
            __( 'School Management System', 'olama-dashboard' ),
            'olama_hub_access',
            'olama-dashboard',
            [ $this, 'render_hub' ],
            'dashicons-layout',
            2                                // position 2: just below WP Dashboard
        );

        add_submenu_page(
            'olama-dashboard',
            __( 'Refresh Olama Hub', 'olama-dashboard' ),
            __( 'Refresh', 'olama-dashboard' ),
            'manage_options',
            'olama-dashboard-refresh',
            [ $this, 'render_registry_refresh_page' ]
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'olama-dashboard' ) === false ) {
            return;
        }

        if ( $this->can_use_teacher_attendance() && defined( 'OLAMA_SCHOOL_URL' ) ) {
            wp_enqueue_style( 'olama-shortcodes', OLAMA_SCHOOL_URL . 'assets/css/shortcodes.css', [], OLAMA_SCHOOL_VERSION );
        }

        $style_path     = OLAMA_DASH_PATH . 'assets/css/hub.css';
        $script_path    = OLAMA_DASH_PATH . 'assets/js/hub.js';
        $style_version  = file_exists( $style_path ) ? (string) filemtime( $style_path ) : OLAMA_DASH_VERSION;
        $script_version = file_exists( $script_path ) ? (string) filemtime( $script_path ) : OLAMA_DASH_VERSION;

        wp_enqueue_style(
            'olama-hub-style',
            OLAMA_DASH_URL . 'assets/css/hub.css',
            [],
            $style_version
        );

        wp_enqueue_script(
            'olama-hub-script',
            OLAMA_DASH_URL . 'assets/js/hub.js',
            [ 'jquery' ],
            $script_version,
            true
        );

        // Data is delivered via a JSON hydration block inside hub.php — NOT wp_localize_script.
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render_hub() {
        $tasks = $this->get_daily_tasks();
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
        if ( isset( $tasks[$view] ) ) {
            if ( ! olama_dashboard_can_access() || ! $this->can_use_teacher_attendance() ) {
                wp_die( esc_html__( 'You do not have permission to view this page.', 'olama-dashboard' ), '', [ 'response' => 403 ] );
            }
            echo '<div class="wrap"><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=olama-dashboard' ) ) . '">' . esc_html__( 'School Management System', 'olama-dashboard' ) . '</a></p>';
            if ( shortcode_exists( $tasks[$view]['shortcode'] ) ) {
                echo do_shortcode( '[' . $tasks[$view]['shortcode'] . ']' );
            } else {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'Activate the updated Olama School plugin to enable attendance.', 'olama-dashboard' ) . '</p></div>';
            }
            echo '</div>';
            return;
        }
        include OLAMA_DASH_PATH . 'admin/views/hub.php';
    }

    /** The fixed attendance shortcut belongs only to the teacher Hub view. */
    public function can_use_teacher_attendance() {
        $user = wp_get_current_user();
        $teacher_roles = [ 'teacher', 'teachers', 'olama_teacher' ];
        $teacher_names = [ 'teacher', 'teachers', 'معلم', 'المعلم', 'المعلمون', 'المعلمين', 'معلمون', 'معلمين' ];
        $role_names = wp_roles()->role_names;
        $is_teacher = false;
        foreach ( (array) $user->roles as $role ) {
            $name = strtolower( trim( $role_names[ $role ] ?? '' ) );
            if ( in_array( $role, $teacher_roles, true ) || in_array( $name, $teacher_names, true ) ) {
                $is_teacher = true;
                break;
            }
        }
        return $is_teacher
            && ! current_user_can( 'manage_options' );
    }

    public function get_daily_tasks() {
        return [
            'attendance' => ['label' => __( 'Daily Attendance', 'olama-dashboard' ), 'icon' => 'yes-alt', 'shortcode' => 'olama_attendance'],
            'schedule' => ['label' => __( 'Teacher Classes Schedule', 'olama-dashboard' ), 'icon' => 'calendar-alt', 'shortcode' => 'olama_logged_teacher_schedule'],
            'shifts' => ['label' => __( 'Teacher Shifts', 'olama-dashboard' ), 'icon' => 'clock', 'shortcode' => 'olama_logged_user_shifts'],
            'exam-attendance' => ['label' => __( 'Exam Halls Attendance', 'olama-dashboard' ), 'icon' => 'building', 'shortcode' => 'olama_exam_halls_attendance'],
            'students' => ['label' => __( 'Grade / Section Students (Family ID & Mother Mobile)', 'olama-dashboard' ), 'icon' => 'groups', 'shortcode' => 'olama_section_students'],
        ];
    }

    /**
     * Render the administrator-only registry refresh screen.
     */
    public function render_registry_refresh_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to refresh Olama Hub.', 'olama-dashboard' ), '', [ 'response' => 403 ] );
        }

        $notice_key = 'olama_dashboard_refresh_notice_' . get_current_user_id();
        $notice = get_transient( $notice_key );
        if ( $notice ) {
            delete_transient( $notice_key );
        }
        $last_refresh = get_option( 'olama_dashboard_last_registry_refresh', [] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Refresh Olama Hub', 'olama-dashboard' ); ?></h1>
            <?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ?? 'success' ); ?> is-dismissible">
                    <p><?php echo esc_html( $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>
            <p><?php esc_html_e( 'Rebuild the modules, actions, and capabilities shown by Olama Hub from all currently active plugins.', 'olama-dashboard' ); ?></p>
            <?php if ( ! empty( $last_refresh['time'] ) ) : ?>
                <p>
                    <strong><?php esc_html_e( 'Last refresh:', 'olama-dashboard' ); ?></strong>
                    <?php echo esc_html( $last_refresh['time'] ); ?>
                    <?php if ( ! empty( $last_refresh['source'] ) ) : ?>
                        (<?php echo esc_html( $last_refresh['source'] ); ?>)
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="olama_dashboard_refresh_registry">
                <?php wp_nonce_field( 'olama_dashboard_refresh_registry' ); ?>
                <?php submit_button( __( 'Refresh Modules and Actions', 'olama-dashboard' ), 'primary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Rebuild Hub and OLAMA Users registries and persist a refresh summary.
     */
    public function refresh_modules_and_actions( $source = 'manual' ) {
        if ( ! class_exists( 'Olama_Users_Registry' ) ) {
            return new WP_Error( 'registry_unavailable', __( 'OLAMA Users registry is unavailable.', 'olama-dashboard' ) );
        }

        if ( method_exists( 'Olama_Users_Registry', 'refresh' ) ) {
            $users_summary = Olama_Users_Registry::refresh();
        } else {
            // Backward compatibility for OLAMA Users versions that predate
            // the explicit refresh API. An admin-post request is a fresh PHP
            // request, so loading and rediscovering menus rebuilds the live
            // registry from all active plugin declarations.
            Olama_Users_Registry::load();
            if ( method_exists( 'Olama_Users_Registry', 'discover_admin_menus' ) ) {
                Olama_Users_Registry::discover_admin_menus();
            }
            $legacy_modules = Olama_Users_Registry::all();
            $legacy_capabilities = method_exists( 'Olama_Users_Registry', 'capabilities' )
                ? Olama_Users_Registry::capabilities()
                : [];
            $users_summary = [
                'modules' => count( $legacy_modules ),
                'capabilities' => count( $legacy_capabilities ),
            ];
        }
        $cards = $this->build_registry();
        $action_count = 0;
        foreach ( $cards as $card ) {
            $action_count += count( (array) ( $card['submenus'] ?? [] ) );
        }

        $summary = [
            'time'         => current_time( 'mysql' ),
            'source'       => sanitize_key( $source ),
            'modules'      => (int) ( $users_summary['modules'] ?? 0 ),
            'capabilities' => (int) ( $users_summary['capabilities'] ?? 0 ),
            'hub_cards'    => count( $cards ),
            'hub_actions'  => $action_count,
        ];
        update_option( 'olama_dashboard_last_registry_refresh', $summary, false );
        do_action( 'olama_dashboard_registry_refreshed', $summary );

        return $summary;
    }

    public function handle_registry_refresh() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to refresh Olama Hub.', 'olama-dashboard' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'olama_dashboard_refresh_registry' );

        $result = $this->refresh_modules_and_actions( 'manual' );
        $notice = is_wp_error( $result )
            ? [ 'type' => 'error', 'message' => $result->get_error_message() ]
            : [
                'type' => 'success',
                'message' => sprintf(
                    __( 'Olama Hub refreshed: %1$d modules, %2$d capabilities, %3$d cards, and %4$d actions.', 'olama-dashboard' ),
                    $result['modules'],
                    $result['capabilities'],
                    $result['hub_cards'],
                    $result['hub_actions']
                ),
            ];
        set_transient( 'olama_dashboard_refresh_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=olama-dashboard-refresh' ) );
        exit;
    }

    public function refresh_after_capability_update( $role_key, $plugin_id, $result ) {
        $this->refresh_modules_and_actions( 'capabilities' );
    }

    /**
     * Queue a refresh when WordPress persists role capability changes.
     * This also supports OLAMA Users releases without the dedicated hook.
     */
    public function watch_role_capability_updates( $option, $old_value, $value ) {
        global $wpdb;
        if ( $option !== $wpdb->prefix . 'user_roles' || $old_value === $value ) {
            return;
        }
        $this->mark_registry_refresh_pending();
    }

    public function mark_registry_refresh_pending() {
        delete_option( 'olama_dashboard_menu_snapshot' );
        update_option( 'olama_dashboard_registry_refresh_pending', 1, false );
    }

    public function maybe_refresh_pending_registry() {
        if ( ! get_option( 'olama_dashboard_registry_refresh_pending', false ) ) {
            return;
        }
        delete_option( 'olama_dashboard_registry_refresh_pending' );
        $this->refresh_modules_and_actions( 'plugin_update' );
    }

    // ── Optional redirect ─────────────────────────────────────────────────────

    /**
     * Redirects non-admin Olama staff from /wp-admin/ to the hub.
     *
     * Only fires when:
     *  - Current user is NOT an administrator.
     *  - Option `olama_dashboard_redirect` is true (default false — opt-in).
     *  - Constant OLAMA_DASH_NO_REDIRECT is not defined/true.
     *  - User can access the authenticated Hub shell.
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

        // Only authenticated users who can enter the Hub should be simplified.
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

        foreach ( $menu as $index => $item ) {
            if ( ! isset( $item[2] ) || '' === $item[2] ) {
                continue;
            }
            // Leave separators in place so layout does not look broken.
            if ( 0 === strpos( $item[2], 'separator' ) ) {
                continue;
            }
            if ( ! in_array( $item[2], $keep, true ) ) {
                unset( $menu[ $index ] );
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

        // Only authenticated users who can enter the Hub should be simplified.
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
    public function register_access_module() {
        if ( ! class_exists( 'Olama_Users_Registry' ) ) {
            return;
        }
        Olama_Users_Registry::register( [
            'id'            => 'olama_hub',
            'plugin'        => 'olama-dashboard',
            'label'         => __( 'School Management System', 'olama-dashboard' ),
            'capability'    => 'olama_hub_access',
            'default_grant' => true,
            'items'         => [],
        ] );
    }

    /**
     * Imports every OLAMA/Stores capability exposed by Hub cards into the
     * central matrix. Plugins with a richer declaration keep their own module.
     */
    public function register_card_access_modules() {
        if ( ! class_exists( 'Olama_Users_Registry' ) ) {
            return;
        }
        $declared = Olama_Users_Registry::capabilities();
        foreach ( $this->build_registry() as $card ) {
            $capability = isset( $card['capability'] ) ? sanitize_key( $card['capability'] ) : '';
            if (
                empty( $card['id'] ) ||
                ! $capability ||
                (
                    0 !== strpos( $capability, 'olama_' ) &&
                    0 !== strpos( $capability, 'os_' ) &&
                    0 !== strpos( $capability, 'manage_olama_' )
                ) ||
                isset( $declared[ $capability ] )
            ) {
                continue;
            }
            $items = [];
            foreach ( (array) ( $card['submenus'] ?? [] ) as $submenu ) {
                $item_capability = isset( $submenu['capability'] ) ? sanitize_key( $submenu['capability'] ) : $capability;
                if (
                    ! $item_capability ||
                    (
                        0 !== strpos( $item_capability, 'olama_' ) &&
                        0 !== strpos( $item_capability, 'os_' ) &&
                        0 !== strpos( $item_capability, 'manage_olama_' )
                    )
                ) {
                    continue;
                }
                $items[] = [
                    'id'         => sanitize_key( $submenu['id'] ?? $item_capability ),
                    'type'       => 'submenu',
                    'label'      => (string) ( $submenu['label'] ?? $item_capability ),
                    'capability' => $item_capability,
                ];
            }
            Olama_Users_Registry::register( [
                'id'         => 'hub_' . sanitize_key( $card['id'] ),
                'plugin'     => sanitize_key( $card['id'] ),
                'label'      => (string) ( $card['label'] ?? $card['id'] ),
                'capability' => $capability,
                'items'      => $items,
            ] );
            $declared[ $capability ] = true;
        }
    }

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
                    [ 'id' => 'school.follow_up', 'label' => __( 'Follow Up',         'olama-dashboard' ), 'icon' => 'dashicons-visibility',           'url' => admin_url( 'admin.php?page=olama-employees' ), 'capability' => 'olama_access_followup', 'color' => '#1a56db' ],
                    [ 'id' => 'school.academic', 'label' => __( 'Academic',          'olama-dashboard' ), 'icon' => 'dashicons-book',                 'url' => admin_url( 'admin.php?page=olama-school-academic' ), 'capability' => 'olama_access_academic_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.academic.calendar', 'label' => $this->school_label( 'Academic Calendar' ), 'icon' => 'dashicons-calendar-alt', 'url' => admin_url( 'admin.php?page=olama-school-academic&tab=calendar' ), 'capability' => 'olama_manage_academic_calendar', 'color' => '#1a56db' ],
                    [ 'id' => 'school.academic.grades', 'label' => $this->school_label( 'Grades & Sections' ), 'icon' => 'dashicons-networking', 'url' => admin_url( 'admin.php?page=olama-school-academic&tab=grades' ), 'capability' => 'olama_manage_academic_grades', 'color' => '#1a56db' ],
                    [ 'id' => 'school.academic.subjects', 'label' => $this->school_label( 'Subjects' ), 'icon' => 'dashicons-book-alt', 'url' => admin_url( 'admin.php?page=olama-school-academic&tab=subjects' ), 'capability' => 'olama_manage_academic_subjects', 'color' => '#1a56db' ],
                    [ 'id' => 'school.academic.assign_teachers', 'label' => $this->school_label( 'Assign Teachers' ), 'icon' => 'dashicons-groups', 'url' => admin_url( 'admin.php?page=olama-school-academic&tab=assign_teachers' ), 'capability' => 'olama_manage_academic_assignment', 'color' => '#1a56db' ],
                    [ 'id' => 'school.academic.stationary', 'label' => $this->school_label( 'Stationary' ), 'icon' => 'dashicons-edit-page', 'url' => admin_url( 'admin.php?page=olama-school-academic&tab=stationary' ), 'capability' => 'olama_manage_academic_stationary', 'color' => '#1a56db' ],
                    [ 'id' => 'school.academic.office_hours', 'label' => $this->school_label( 'Office Hours' ), 'icon' => 'dashicons-clock', 'url' => admin_url( 'admin.php?page=olama-school-academic&tab=office_hours' ), 'capability' => 'olama_manage_academic_office_hours', 'color' => '#1a56db' ],
                    [ 'id' => 'school.curriculum', 'label' => __( 'Curriculum',        'olama-dashboard' ), 'icon' => 'dashicons-list-view',            'url' => admin_url( 'admin.php?page=olama-school-curriculum' ), 'capability' => 'olama_access_curriculum_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.exams', 'label' => __( 'Exams',             'olama-dashboard' ), 'icon' => 'dashicons-edit',                 'url' => admin_url( 'admin.php?page=olama-exam-management' ), 'capability' => 'olama_access_exams_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.evaluation', 'label' => __( 'Evaluation',        'olama-dashboard' ), 'icon' => 'dashicons-star-filled',          'url' => admin_url( 'admin.php?page=olama-student-evaluation' ), 'capability' => 'olama_access_evaluation', 'color' => '#1a56db' ],
                    [ 'id' => 'school.supervision', 'label' => __( 'Supervision',       'olama-dashboard' ), 'icon' => 'dashicons-admin-users',          'url' => admin_url( 'admin.php?page=olama-supervision' ), 'capability' => 'olama_access_supervision', 'color' => '#1a56db' ],
                    [ 'id' => 'school.users', 'label' => __( 'Users',             'olama-dashboard' ), 'icon' => 'dashicons-groups',               'url' => admin_url( 'admin.php?page=olama-core-users' ), 'capability' => 'olama_access_users_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.kg', 'label' => __( 'KG',                'olama-dashboard' ), 'icon' => 'dashicons-smiley',               'url' => admin_url( 'admin.php?page=olama-kg' ), 'capability' => 'olama_access_kg', 'color' => '#1a56db' ],
                    [ 'id' => 'school.transport', 'label' => __( 'Transport',         'olama-dashboard' ), 'icon' => 'dashicons-car',                  'url' => admin_url( 'admin.php?page=olama-transportation' ), 'capability' => 'olama_access_transport_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.settings', 'label' => __( 'Settings',          'olama-dashboard' ), 'icon' => 'dashicons-admin-settings',       'url' => admin_url( 'admin.php?page=olama-school-settings' ), 'capability' => 'olama_access_settings_mgmt', 'color' => '#1a56db' ],
                    [ 'id' => 'school.exam_halls', 'label' => __( 'Exam Hall',         'olama-dashboard' ), 'icon' => 'dashicons-building',             'url' => admin_url( 'admin.php?page=olama-exam-halls' ), 'capability' => 'olama_access_exam_halls', 'color' => '#1a56db' ],
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
                    [ 'id' => 'billing.agreements', 'label' => __( 'Agreements',         'olama-dashboard' ), 'icon' => 'dashicons-media-document',      'url' => admin_url( 'admin.php?page=olama-registration-agreements' ), 'capability' => 'olama_manage_registration_agreements', 'color' => '#00a32a' ],
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
                'capability'  => 'olama_access_messages',
                'primary_url' => admin_url( 'admin.php?page=olama-messages' ),
                'submenus'    => [
                    [ 'id' => 'messages.dashboard', 'label' => __( 'Dashboard',      'olama-dashboard' ), 'icon' => 'dashicons-dashboard',        'url' => admin_url( 'admin.php?page=olama-messages' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.campaigns', 'label' => __( 'Campaigns',      'olama-dashboard' ), 'icon' => 'dashicons-megaphone',        'url' => admin_url( 'admin.php?page=olama-messages-campaigns' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.templates', 'label' => __( 'Templates',      'olama-dashboard' ), 'icon' => 'dashicons-media-text',       'url' => admin_url( 'admin.php?page=olama-messages-templates' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.queue', 'label' => __( 'SMS Queue',      'olama-dashboard' ), 'icon' => 'dashicons-clock',            'url' => admin_url( 'admin.php?page=olama-messages-queue' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.recipients', 'label' => __( 'Recipients',     'olama-dashboard' ), 'icon' => 'dashicons-groups',           'url' => admin_url( 'admin.php?page=olama-messages-recipients' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.tokens', 'label' => __( 'Report Links',   'olama-dashboard' ), 'icon' => 'dashicons-admin-links',      'url' => admin_url( 'admin.php?page=olama-messages-tokens' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.agents', 'label' => __( 'Sending Agents', 'olama-dashboard' ), 'icon' => 'dashicons-controls-forward', 'url' => admin_url( 'admin.php?page=olama-messages-agents' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.direct', 'label' => __( 'Direct Message', 'olama-dashboard' ), 'icon' => 'dashicons-format-chat',      'url' => admin_url( 'admin.php?page=olama-messages-direct' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
                    [ 'id' => 'messages.settings', 'label' => __( 'Settings',       'olama-dashboard' ), 'icon' => 'dashicons-admin-settings',   'url' => admin_url( 'admin.php?page=olama-messages-settings' ), 'capability' => 'olama_access_messages', 'color' => '#f59e0b' ],
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
                    [ 'id' => 'stores.add_items', 'label' => __( 'Add Items',          'olama-dashboard' ), 'icon' => 'dashicons-plus-alt',        'url' => admin_url( 'admin.php?page=olama-stores-add-items' ), 'capability' => 'os_receive_stock', 'color' => '#ef4444' ],
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
                'capability'  => 'olama_access_oracle_sync',
                'primary_url' => admin_url( 'admin.php?page=olama-oracle-sync' ),
                'submenus'    => [
                    [ 'id' => 'oracle.dashboard', 'label' => __( 'Dashboard',   'olama-dashboard' ), 'icon' => 'dashicons-dashboard',       'url' => admin_url( 'admin.php?page=olama-oracle-sync' ), 'capability' => 'olama_access_oracle_sync', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.settings', 'label' => __( 'Settings',    'olama-dashboard' ), 'icon' => 'dashicons-admin-settings',  'url' => admin_url( 'admin.php?page=olama-oracle-sync-settings' ), 'capability' => 'olama_access_oracle_sync', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.manual', 'label' => __( 'Synchronization', 'olama-dashboard' ), 'icon' => 'dashicons-controls-play',   'url' => admin_url( 'admin.php?page=olama-oracle-sync-jobs' ), 'capability' => 'olama_access_oracle_sync', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.runs', 'label' => __( 'Sync Runs',   'olama-dashboard' ), 'icon' => 'dashicons-clock',           'url' => admin_url( 'admin.php?page=olama-oracle-sync-runs' ), 'capability' => 'olama_access_oracle_sync', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.validation', 'label' => __( 'Validation',  'olama-dashboard' ), 'icon' => 'dashicons-yes-alt',         'url' => admin_url( 'admin.php?page=olama-oracle-sync-validation' ), 'capability' => 'olama_access_oracle_sync', 'color' => '#0ea5e9' ],
                    [ 'id' => 'oracle.diagnostics', 'label' => __( 'Bridge Diagnostics', 'olama-dashboard' ), 'icon' => 'dashicons-admin-tools', 'url' => admin_url( 'admin.php?page=olama-oracle-sync-api-map' ), 'capability' => 'olama_access_oracle_sync', 'color' => '#0ea5e9' ],
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
                'capability'  => 'olama_access_tools',
                'primary_url' => admin_url( 'admin.php?page=olama-tools' ),
                'submenus'    => [
                    [ 'id' => 'tools.monitor', 'label' => __( 'Performance Monitor', 'olama-dashboard' ), 'icon' => 'dashicons-chart-line', 'url' => admin_url( 'admin.php?page=olama-performance-monitor' ), 'capability' => 'olama_access_tools', 'color' => '#10b981' ],
                    [ 'id' => 'tools.security', 'label' => __( 'Security Monitor', 'olama-dashboard' ), 'icon' => 'dashicons-shield', 'url' => admin_url( 'admin.php?page=olama-security-monitor' ), 'capability' => 'manage_options', 'color' => '#10b981' ],
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
                'capability'  => 'olama_access_media_library',
                'primary_url' => admin_url( 'admin.php?page=academy-media-library' ),
                'submenus'    => [
                    [ 'id' => 'media.library', 'label' => __( 'Media Library', 'olama-dashboard' ), 'icon' => 'dashicons-format-video', 'url' => admin_url( 'admin.php?page=academy-media-library' ), 'capability' => 'olama_access_media_library', 'color' => '#64748b' ],
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
        $cards[] = [
            'id' => 'olama-emis',
            'label' => __( 'Olama EMIS Interface', 'olama-dashboard' ),
            'description' => __( 'Ministry records for schools, students, buildings, classrooms, facilities and staff.', 'olama-dashboard' ),
            'icon' => 'dashicons-chart-area',
            'accent' => '#0891b2',
            'accent_rgb' => '8,145,178',
            'active' => defined( 'OLAMA_EMIS_VERSION' ),
            'capability' => 'olama_users_ministry_view',
            'primary_url' => admin_url( 'admin.php?page=olama-emis' ),
            'submenus' => array_map( static function ( $slug, $label ) {
                return [
                    'id' => 'emis.' . $slug, 'label' => $label,
                    'icon' => 'dashicons-chart-area',
                    'url' => admin_url( 'admin.php?page=olama-emis' . ( $slug === 'dashboard' ? '' : '-' . $slug ) ),
                    'capability' => 'olama_users_ministry_view', 'color' => '#0891b2',
                ];
            }, [ 'dashboard', 'students', 'schools', 'buildings', 'classrooms', 'facilities', 'staff', 'qualifications' ], [
                __( 'Overview', 'olama-dashboard' ), __( 'Students', 'olama-dashboard' ),
                __( 'Schools', 'olama-dashboard' ), __( 'Buildings', 'olama-dashboard' ),
                __( 'Classrooms', 'olama-dashboard' ), __( 'Facilities', 'olama-dashboard' ),
                __( 'Staff', 'olama-dashboard' ), __( 'Qualifications and Teaching Loads', 'olama-dashboard' ),
            ] ),
        ];

        return $this->sync_plugin_menus( apply_filters( 'olama_dashboard_cards', $cards ) );
    }

    /** Save the complete administrator menu before the staff sidebar is simplified.
     * The snapshot also supplies discovered actions in AJAX/admin-post requests,
     * where WordPress does not execute admin_menu.
     */
    public function capture_plugin_menus() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $menu, $submenu, $_registered_pages;
        $snapshot = [ 'parents' => [], 'pages' => [], 'plugins' => get_option( 'active_plugins', [] ) ];
        foreach ( (array) $menu as $item ) {
            $slug = $item[2] ?? '';
            if ( $slug === 'olama-dashboard' || ! preg_match( '/^(olama-|academy-media-library$)/', $slug ) ) {
                continue;
            }
            $snapshot['parents'][ $slug ] = [
                'label' => wp_strip_all_tags( $item[0] ), 'capability' => $item[1],
                'icon' => isset( $item[6] ) && strpos( $item[6], 'dashicons-' ) === 0 ? $item[6] : 'dashicons-layout',
                'submenus' => [],
            ];
            foreach ( (array) ( $submenu[ $slug ] ?? [] ) as $sub ) {
                $snapshot['parents'][ $slug ]['submenus'][ $sub[2] ] = [
                    'label' => wp_strip_all_tags( $sub[0] ), 'capability' => $sub[1],
                ];
            }
        }
        $snapshot['pages'] = array_keys( (array) $_registered_pages );
        update_option( 'olama_dashboard_menu_snapshot', $snapshot, false );
    }

    private function sync_plugin_menus( $cards ) {
        $snapshot = get_option( 'olama_dashboard_menu_snapshot', [] );
        if ( empty( $snapshot['parents'] ) || ( $snapshot['plugins'] ?? [] ) !== get_option( 'active_plugins', [] ) ) {
            return $cards;
        }
        $parents = $snapshot['parents'];
        $all_items = [];
        foreach ( $parents as $slug => $item ) {
            $all_items[ $slug ] = $item;
            $all_items = array_replace( $all_items, $item['submenus'] );
        }
        $represented = [];
        foreach ( $cards as &$card ) {
            if ( empty( $card['active'] ) ) {
                continue;
            }
            parse_str( (string) wp_parse_url( $card['primary_url'], PHP_URL_QUERY ), $query );
            $parent = $query['page'] ?? $card['id'];
            $represented[ $parent ] = true;
            $items = $parents[ $parent ]['submenus'] ?? [];
            $actions = [];
            $linked = [];
            foreach ( $card['submenus'] as $action ) {
                parse_str( (string) wp_parse_url( $action['url'], PHP_URL_QUERY ), $args );
                $page = $args['page'] ?? '';
                if ( $page && ! isset( $all_items[ $page ] ) && ! in_array( get_plugin_page_hookname( $page, $parent ), $snapshot['pages'], true ) && ! in_array( get_plugin_page_hookname( $page, null ), $snapshot['pages'], true ) ) {
                    continue;
                }
                // Tabs can have stricter capabilities than their menu container.
                if ( isset( $all_items[ $page ] ) && count( $args ) === 1 && $all_items[ $page ]['capability'] !== 'read' ) {
                    $action['capability'] = $all_items[ $page ]['capability'];
                }
                $actions[] = $action;
                if ( count( $args ) === 1 ) {
                    $linked[ $page ] = true;
                }
            }
            foreach ( $items as $slug => $item ) {
                if ( isset( $linked[ $slug ] ) ) {
                    continue;
                }
                $actions[] = [
                    'id' => $card['id'] . '.menu.' . sanitize_key( $slug ),
                    'label' => $item['label'], 'capability' => $item['capability'],
                    'url' => admin_url( 'admin.php?page=' . $slug ),
                    'icon' => $card['icon'], 'color' => $card['accent'],
                ];
            }
            $card['submenus'] = $actions;
            if ( ! isset( $parents[ $parent ] ) && ! empty( $actions ) ) {
                $card['primary_url'] = $actions[0]['url'];
                $card['capability'] = $actions[0]['capability'];
            }
        }
        unset( $card );
        foreach ( $parents as $slug => $item ) {
            if ( isset( $represented[ $slug ] ) ) {
                continue;
            }
            $actions = [];
            foreach ( [ $slug => $item ] + $item['submenus'] as $page => $sub ) {
                $actions[] = [
                    'id' => $slug . '.menu.' . sanitize_key( $page ),
                    'label' => $sub['label'], 'capability' => $sub['capability'],
                    'url' => admin_url( 'admin.php?page=' . $page ),
                    'icon' => $item['icon'], 'color' => '#1a56db',
                ];
            }
            $cards[] = [
                'id' => $slug, 'label' => $item['label'], 'description' => '',
                'icon' => $item['icon'], 'accent' => '#1a56db', 'accent_rgb' => '26,86,219',
                'active' => true, 'capability' => $item['capability'],
                'primary_url' => admin_url( 'admin.php?page=' . $slug ), 'submenus' => $actions,
            ];
        }
        return $cards;
    }

    private function school_label( $text ) {
        if ( class_exists( 'Olama_School_Helpers' ) ) {
            return Olama_School_Helpers::translate( $text );
        }
        return __( $text, 'olama-dashboard' );
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
                    <span class="os-hub-quick-action-label" dir="auto"><?php echo esc_html( $action['label'] ); ?></span>
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
