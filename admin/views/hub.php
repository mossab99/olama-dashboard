<?php
/**
 * Olama Dashboard — Hub View
 *
 * PHP logic at top; HTML body below.
 * Data for JS delivered via JSON hydration block — no wp_localize_script.
 *
 * UX: Module cards are always compact and equal-height.
 * Selecting a card opens a full-width action tray below that card's grid row
 * (desktop/tablet). Mobile falls back to a simple stacked accordion per card.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Capability gate ───────────────────────────────────────────────────────────
if ( ! olama_dashboard_can_access() ) {
    wp_die( esc_html__( 'You do not have permission to view this page.', 'olama-dashboard' ) );
}

// ── Build card registry ───────────────────────────────────────────────────────
$is_admin = current_user_can( 'manage_options' );
$cards    = $this->build_registry();

$visible_cards = [];
foreach ( $cards as $card ) {
    $is_active = ! empty( $card['active'] );

    // Admin users can see all cards (even inactive ones).
    if ( $is_admin ) {
        $card['show_as_inactive'] = ! $is_active;
        $visible_cards[]          = $card;
        continue;
    }

    // Non-admin staff: must be active.
    if ( ! $is_active ) {
        continue;
    }

    // Filter submenu items by capability for non-admins.
    $filtered_submenus = [];
    foreach ( $card['submenus'] as $sub ) {
        $sub_cap = ! empty( $sub['capability'] ) ? $sub['capability'] : $card['capability'];
        if ( current_user_can( $sub_cap ) ) {
            $filtered_submenus[] = $sub;
        }
    }

    // Top-level capability check.
    $has_primary_cap = ! empty( $card['capability'] ) ? current_user_can( $card['capability'] ) : true;

    // A non-admin user must either have the primary capability OR have access to at least one submenu.
    if ( ! $has_primary_cap && empty( $filtered_submenus ) ) {
        continue;
    }

    // If they have access to some submenus but not the primary card page,
    // rewrite the primary URL to the first permitted submenu.
    if ( ! $has_primary_cap && ! empty( $filtered_submenus ) ) {
        $card['primary_url'] = $filtered_submenus[0]['url'];
    }

    $card['submenus']         = $filtered_submenus;
    $card['show_as_inactive'] = false;
    $visible_cards[]          = $card;
}

// ── Global stats ─────────────────────────────────────────────────────────────
$active_year_name = '';
$student_count    = '—';

if ( defined( 'OLAMA_SCHOOL_FILE' ) && class_exists( 'Olama_School_Academic' ) ) {
    $active_year = Olama_School_Academic::get_active_year();
    if ( $active_year ) {
        $active_year_name = $active_year->year_name ?? '';
    }
}

if ( defined( 'OLAMA_SCHOOL_FILE' ) ) {
    global $wpdb;
    $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}olama_students WHERE is_active = 1" );
    if ( $count !== null ) {
        $student_count = number_format( (int) $count );
    }
}

$active_plugin_count = count( array_filter( $visible_cards, fn( $c ) => ! empty( $c['active'] ) ) );
$current_user        = wp_get_current_user();
$greeting_name       = $current_user->display_name ?: $current_user->user_login;

// ── Build card data array for JS (tray is built/moved entirely in JS) ─────────
$cards_for_js = [];
foreach ( $visible_cards as $card ) {
    if ( ! empty( $card['show_as_inactive'] ) ) {
        continue; // inactive cards never get a tray
    }
    $submenus = [];
    foreach ( $card['submenus'] as $link ) {
        $submenus[] = [
            'id'    => $link['id'],
            'label' => $link['label'],
            'icon'  => $link['icon'],
            'url'   => $link['url'],
        ];
    }
    $cards_for_js[ $card['id'] ] = [
        'label'      => $card['label'],
        'accent'     => $card['accent'],
        'accentRgb'  => $card['accent_rgb'],
        'icon'       => $card['icon'],
        'primaryUrl' => $card['primary_url'],
        'submenus'   => $submenus,
    ];
}
// ── Role display name & Quick Actions ─────────────────────────────────────────

$role_display_name = __( 'Staff', 'olama-dashboard' );
if ( $is_admin ) {
    $role_display_name = __( 'Admin', 'olama-dashboard' );
} elseif ( ! empty( $current_user->roles ) ) {
    $role_key = reset( $current_user->roles );
    $wp_roles = wp_roles();
    if ( isset( $wp_roles->role_names[ $role_key ] ) ) {
        $role_display_name = translate_user_role( $wp_roles->role_names[ $role_key ] );
    }
}

// Filter presets or load user meta to pass to JS
$initial_pinned = get_user_meta( get_current_user_id(), 'olama_dashboard_quick_actions', true );
if ( ! is_array( $initial_pinned ) ) {
    $initial_pinned = $this->get_default_quick_actions( wp_get_current_user() );
}

// ── JSON hydration block ──────────────────────────────────────────────────────
$hub_nonce = wp_create_nonce( 'olama_hub_nonce' );
?>
<script id="os-hub-data" type="application/json">
<?php echo wp_json_encode( [
    'nonce'   => $hub_nonce,
    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    'isRtl'   => (bool) is_rtl(),
    'cards'   => $cards_for_js,
    'pinned'  => $initial_pinned,
    'i18n'    => [
        'search'   => __( 'Search modules...', 'olama-dashboard' ),
        'inactive' => __( 'Plugin not installed', 'olama-dashboard' ),
        'actions'  => __( 'Actions', 'olama-dashboard' ),
        'openMod'  => __( 'Open Module', 'olama-dashboard' ),
        'close'    => __( 'Close', 'olama-dashboard' ),
    ],
] ); ?>
</script>

<?php // ── Page wrapper ──────────────────────────────────────────────────────── ?>
<div class="wrap" id="os-hub-page">

    <?php // ── Header ─────────────────────────────────────────────────────── ?>
    <div class="os-hub-header">
        <div class="os-hub-header-brand">
            <div class="os-hub-header-logo">
                <span class="dashicons dashicons-layout" aria-hidden="true"></span>
            </div>
            <div>
                <div class="os-hub-header-title">
                    <?php esc_html_e( 'Olama ERP Hub', 'olama-dashboard' ); ?>
                </div>
                <div class="os-hub-header-subtitle">
                    <?php esc_html_e( 'School Management System', 'olama-dashboard' ); ?>
                </div>
            </div>
        </div>

        <div class="os-hub-header-meta">
            <div class="os-hub-greeting">
                <?php printf( esc_html__( 'Welcome, %s', 'olama-dashboard' ), esc_html( $greeting_name ) ); ?>
            </div>
            <div class="os-hub-datetime" id="os-hub-clock" aria-live="off">
                <?php echo esc_html( wp_date( 'H:i:s' ) ); ?>
            </div>
        </div>
    </div><!-- .os-hub-header -->

    <?php // ── Stats bar ──────────────────────────────────────────────────── ?>
    <div class="os-hub-stats-bar" role="complementary" aria-label="<?php esc_attr_e( 'System overview', 'olama-dashboard' ); ?>">

        <div class="os-hub-stat">
            <div class="os-hub-stat-icon" style="background:#1a56db;" aria-hidden="true">
                <span class="dashicons dashicons-calendar-alt"></span>
            </div>
            <div class="os-hub-stat-body">
                <span class="os-hub-stat-value">
                    <?php echo $active_year_name ? esc_html( $active_year_name ) : esc_html__( 'N/A', 'olama-dashboard' ); ?>
                </span>
                <span class="os-hub-stat-label"><?php esc_html_e( 'Academic Year', 'olama-dashboard' ); ?></span>
            </div>
        </div>

        <div class="os-hub-stat">
            <div class="os-hub-stat-icon" style="background:#00a32a;" aria-hidden="true">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <div class="os-hub-stat-body">
                <span class="os-hub-stat-value"><?php echo esc_html( $student_count ); ?></span>
                <span class="os-hub-stat-label"><?php esc_html_e( 'Active Students', 'olama-dashboard' ); ?></span>
            </div>
        </div>

        <div class="os-hub-stat">
            <div class="os-hub-stat-icon" style="background:#8b5cf6;" aria-hidden="true">
                <span class="dashicons dashicons-layout"></span>
            </div>
            <div class="os-hub-stat-body">
                <span class="os-hub-stat-value"><?php echo esc_html( $active_plugin_count ); ?></span>
                <span class="os-hub-stat-label"><?php esc_html_e( 'Active Modules', 'olama-dashboard' ); ?></span>
            </div>
        </div>

        <div class="os-hub-stat">
            <div class="os-hub-stat-icon" style="background:#f59e0b;" aria-hidden="true">
                <span class="dashicons dashicons-admin-users"></span>
            </div>
            <div class="os-hub-stat-body">
                <span class="os-hub-stat-value"><?php echo esc_html( $role_display_name ); ?></span>
                <span class="os-hub-stat-label"><?php esc_html_e( 'Access Level', 'olama-dashboard' ); ?></span>
            </div>
        </div>

    </div><!-- .os-hub-stats-bar -->

    <?php // ── Body ───────────────────────────────────────────────────────── ?>
    <div class="os-hub-body">

        <?php // ── Quick Actions ───────────────────────────────────────────── ?>
        <div class="os-hub-quick-actions-section" role="region" aria-label="<?php esc_attr_e( 'Quick Actions', 'olama-dashboard' ); ?>">
            <h3 class="os-hub-quick-actions-title"><?php esc_html_e( 'Quick Actions', 'olama-dashboard' ); ?></h3>
            <div class="os-hub-quick-actions-list" id="os-hub-quick-actions-list">
                <?php $this->render_quick_actions_tray_inner(); ?>
            </div>
        </div>

        <?php // ── Section header + search ────────────────────────────────── ?>
        <div class="os-hub-section-header">
            <h2 class="os-hub-section-title">
                <?php esc_html_e( 'Modules', 'olama-dashboard' ); ?>
                <?php if ( count( $visible_cards ) <= 3 ) : ?>
                    <span class="os-hub-section-title-note">
                        <?php esc_html_e( '(Your available modules are based on your assigned permissions)', 'olama-dashboard' ); ?>
                    </span>
                <?php endif; ?>
            </h2>
            <div class="os-hub-search-wrap">
                <span class="os-hub-search-icon" aria-hidden="true">
                    <span class="dashicons dashicons-search"></span>
                </span>
                <label for="os-hub-search" class="screen-reader-text">
                    <?php esc_html_e( 'Search modules', 'olama-dashboard' ); ?>
                </label>
                <input
                    type="search"
                    id="os-hub-search"
                    class="os-hub-search"
                    placeholder="<?php esc_attr_e( 'Search modules...', 'olama-dashboard' ); ?>"
                    autocomplete="off"
                >
            </div>
        </div>

        <?php // ── Card grid ──────────────────────────────────────────────── ?>
        <?php if ( empty( $visible_cards ) ) : ?>
            <div class="os-hub-empty">
                <span class="dashicons dashicons-info" aria-hidden="true"></span>
                <p><?php esc_html_e( 'No modules are available for your account.', 'olama-dashboard' ); ?></p>
            </div>
        <?php else : ?>

        <div class="os-hub-grid" id="os-hub-grid" role="list">

            <?php foreach ( $visible_cards as $index => $card ) :
                $card_id      = esc_attr( $card['id'] );
                $is_inactive  = ! empty( $card['show_as_inactive'] );
                $btn_id       = 'card-btn-' . $card_id;
                $accent       = esc_attr( $card['accent'] );
                $accent_rgb   = esc_attr( $card['accent_rgb'] );
                $card_classes = 'os-hub-card' . ( $is_inactive ? ' os-hub-card--inactive' : '' );
            ?>

            <div
                class="<?php echo esc_attr( $card_classes ); ?>"
                role="listitem"
                data-card-id="<?php echo esc_attr( $card['id'] ); ?>"
                style="--hub-accent:<?php echo $accent; ?>;--hub-accent-light:rgba(<?php echo $accent_rgb; ?>,.10);--card-index:<?php echo (int) $index; ?>;"
                data-search-text="<?php echo esc_attr( mb_strtolower( $card['label'] . ' ' . $card['description'] . ' ' . implode( ' ', array_column( $card['submenus'], 'label' ) ), 'UTF-8' ) ); ?>"
            >
                <?php // ── Compact card button ─────────────────────────────── ?>
                <button
                    class="os-hub-card-header"
                    id="<?php echo esc_attr( $btn_id ); ?>"
                    aria-expanded="false"
                    aria-controls="os-hub-tray"
                    <?php if ( $is_inactive ) : ?>
                        disabled
                        aria-disabled="true"
                        title="<?php esc_attr_e( 'Plugin not installed', 'olama-dashboard' ); ?>"
                    <?php endif; ?>
                >
                    <span class="os-hub-card-icon" aria-hidden="true">
                        <span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>"></span>
                    </span>

                    <span class="os-hub-card-info">
                        <span class="os-hub-card-title"><?php echo esc_html( $card['label'] ); ?></span>
                        <span class="os-hub-card-desc"><?php echo esc_html( $card['description'] ); ?></span>
                    </span>

                    <span class="os-hub-badge <?php echo $is_inactive ? 'os-hub-badge--inactive' : 'os-hub-badge--active'; ?>" aria-hidden="true">
                        <?php echo $is_inactive ? esc_html__( 'Inactive', 'olama-dashboard' ) : esc_html__( 'Active', 'olama-dashboard' ); ?>
                    </span>

                    <?php if ( ! $is_inactive ) : ?>
                    <span class="os-hub-card-chevron" aria-hidden="true">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </span>
                    <?php endif; ?>
                </button>

                <?php if ( $is_inactive ) : ?>
                <div class="os-hub-not-installed-note" aria-hidden="true">
                    <span class="dashicons dashicons-lock"></span>
                    <?php esc_html_e( 'Plugin not installed', 'olama-dashboard' ); ?>
                </div>
                <?php endif; ?>

                <?php // ── Mobile-only inline accordion (hidden on desktop via CSS) ?>
                <?php if ( ! $is_inactive ) : ?>
                <div class="os-hub-mobile-actions" id="mob-<?php echo esc_attr( $card_id ); ?>" hidden>
                    <nav class="os-hub-mobile-links" aria-label="<?php echo esc_attr( $card['label'] ) . ' ' . esc_attr__( 'navigation', 'olama-dashboard' ); ?>">
                        <?php foreach ( $card['submenus'] as $link ) :
                            $link_pinned = in_array( $link['id'], $initial_pinned, true );
                            $pin_class   = 'os-hub-pin-toggle' . ( $link_pinned ? ' os-hub-pin-toggle--pinned' : '' );
                            $star_icon   = $link_pinned ? 'dashicons-star-filled' : 'dashicons-star-empty';
                        ?>
                        <div class="os-hub-action-link-wrapper">
                            <a class="os-hub-action-link" href="<?php echo esc_url( $link['url'] ); ?>">
                                <span class="dashicons <?php echo esc_attr( $link['icon'] ); ?>" aria-hidden="true"></span>
                                <span class="os-hub-action-link-text"><?php echo esc_html( $link['label'] ); ?></span>
                                <span class="dashicons dashicons-arrow-right-alt os-hub-action-link-arrow" aria-hidden="true"></span>
                            </a>
                            <button class="<?php echo esc_attr( $pin_class ); ?>" data-action-id="<?php echo esc_attr( $link['id'] ); ?>" type="button" aria-label="<?php echo $link_pinned ? esc_attr__( 'Unpin action', 'olama-dashboard' ) : esc_attr__( 'Pin action', 'olama-dashboard' ); ?>" title="<?php echo $link_pinned ? esc_attr__( 'Unpin action', 'olama-dashboard' ) : esc_attr__( 'Pin action', 'olama-dashboard' ); ?>">
                                <span class="dashicons <?php echo esc_attr( $star_icon ); ?>" aria-hidden="true"></span>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </nav>
                    <div class="os-hub-mobile-footer">
                        <a class="os-hub-card-primary-btn" href="<?php echo esc_url( $card['primary_url'] ); ?>">
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                            <?php esc_html_e( 'Open Module', 'olama-dashboard' ); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- .os-hub-card -->

            <?php endforeach; ?>

            <?php // ── Action tray — JS moves this element after the selected row ── ?>
            <div class="os-hub-tray" id="os-hub-tray" role="region" aria-label="<?php esc_attr_e( 'Module actions', 'olama-dashboard' ); ?>" hidden>
                <div class="os-hub-tray-inner">
                    <div class="os-hub-tray-header">
                        <div class="os-hub-tray-title">
                            <span class="os-hub-tray-icon dashicons" aria-hidden="true"></span>
                            <span class="os-hub-tray-label"></span>
                            <span class="os-hub-tray-subtitle"><?php esc_html_e( 'Actions', 'olama-dashboard' ); ?></span>
                        </div>
                        <button class="os-hub-tray-close" id="os-hub-tray-close" aria-label="<?php esc_attr_e( 'Close actions', 'olama-dashboard' ); ?>">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div class="os-hub-tray-links" id="os-hub-tray-links"></div>
                    <div class="os-hub-tray-footer">
                        <a class="os-hub-card-primary-btn" id="os-hub-tray-open-btn" href="#">
                            <span class="dashicons dashicons-external" aria-hidden="true"></span>
                            <?php esc_html_e( 'Open Module', 'olama-dashboard' ); ?>
                        </a>
                    </div>
                </div>
            </div><!-- #os-hub-tray -->

        </div><!-- #os-hub-grid -->

        <?php endif; ?>

    </div><!-- .os-hub-body -->

</div><!-- #os-hub-page -->
