<?php
/**
 * Plugin Name: WP Options Bloat Auditor
 * Plugin URI: https://github.com/TABARC-Code/wp-options-bloat-auditor
 * Description: Audits wp_options for autoload bloat, oversized values and transient junk so I can see what is chewing memory before I start deleting things.
 * Version: 1.0.0
 * Author: TABARC-Code
 * Author URI: https://github.com/TABARC-Code
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Why this exists:
 * The options table is where plugins leave their mess when they leave. Autoloaded blobs, forgotten transients,
 * huge JSON configs that have not been used in years. WordPress loads a lot of this on every request and never
 * tells you what is actually in there.
 *
 * This plugin does not pretend to fix everything.
 * It gives me:
 * - A summary of wp_options size and autoload impact.
 * - The biggest autoloaded options by size.
 * - A list of oversized options that should probably not exist.
 * - A list of large transient like options that smell like abandoned caches.
 *
 * It does not delete anything. If I want to drop options, I will do it consciously with a backup ready,
 * not because a plugin got overexcited.
 *
 * TODO: add an export of the audit as JSON for ticket attachments.
 * TODO: add a "group by prefix" view so I can see which plugin families are the worst offenders.
 * FIXME: this is intentionally conservative and blind to some custom storage patterns. That is fine for v1.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_Options_Bloat_Auditor' ) ) {

    class WP_Options_Bloat_Auditor {

        private $screen_slug = 'wp-options-bloat-auditor';

        public function __construct() {
            add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
            add_action( 'admin_head-plugins.php', array( $this, 'inject_plugin_list_icon_css' ) );
        }

        /**
         * Shared branding icon that I reuse across my plugins.
         */
        private function get_brand_icon_url() {
            return plugin_dir_url( __FILE__ ) . '.branding/tabarc-icon.svg';
        }

        public function add_tools_page() {
            add_management_page(
                __( 'Options Bloat Auditor', 'wp-options-bloat-auditor' ),
                __( 'Options Bloat', 'wp-options-bloat-auditor' ),
                'manage_options',
                $this->screen_slug,
                array( $this, 'render_screen' )
            );
        }

        public function render_screen() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-options-bloat-auditor' ) );
            }

            $audit = $this->run_audit();

            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Options Bloat Auditor', 'wp-options-bloat-auditor' ); ?></h1>
                <p>
                    wp_options is where plugins go to forget their responsibilities. This screen is my reality check
                    before I start yanking options out with SQL.
                </p>

                <h2><?php esc_html_e( 'Summary', 'wp-options-bloat-auditor' ); ?></h2>
                <?php $this->render_summary( $audit ); ?>

                <h2><?php esc_html_e( 'Top autoloaded options by size', 'wp-options-bloat-auditor' ); ?></h2>
                <p>
                    These are loaded into memory on most page loads. If this list is full of junk, performance will suffer.
                </p>
                <?php $this->render_top_autoload( $audit ); ?>

                <h2><?php esc_html_e( 'Oversized options', 'wp-options-bloat-auditor' ); ?></h2>
                <p>
                    Options whose value size crosses a threshold. Large cached blobs, logs, or abandoned settings dumps.
                </p>
                <?php $this->render_large_options( $audit ); ?>

                <h2><?php esc_html_e( 'Large transient like options', 'wp-options-bloat-auditor' ); ?></h2>
                <p>
                    Transients are meant to expire. These ones are big enough to be noticed. Some may be fine, others are
                    the ghost of caching decisions past.
                </p>
                <?php $this->render_large_transients( $audit ); ?>

                <p style="font-size:12px;opacity:0.8;margin-top:2em;">
                    <?php esc_html_e( 'This tool is read only. It will not delete or change any options. If you break the site after acting on this, that part is on you.', 'wp-options-bloat-auditor' ); ?>
                </p>
            </div>
            <?php
        }

        /**
         * Run a set of targeted queries rather than hauling the entire table into PHP.
         */
        private function run_audit() {
            global $wpdb;

            $options_table = $wpdb->options;

            // Summary stats.
            $total_options = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$options_table}" );

            $autoload_options = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$options_table} WHERE autoload = 'yes'"
            );

            $autoload_bytes = (int) $wpdb->get_var(
                "SELECT SUM(LENGTH(option_value)) FROM {$options_table} WHERE autoload = 'yes'"
            );

            $autoload_bytes = max( 0, $autoload_bytes );

            // Top autoload options by size.
            $limit_top = (int) apply_filters( 'wpoa_top_autoload_limit', 50 );
            if ( $limit_top <= 0 ) {
                $limit_top = 50;
            }

            $top_autoload = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, autoload, LENGTH(option_value) AS size_bytes
                     FROM {$options_table}
                     WHERE autoload = 'yes'
                     ORDER BY size_bytes DESC
                     LIMIT %d",
                    $limit_top
                )
            );

            // Oversized options, autoloaded or not.
            $size_threshold = (int) apply_filters( 'wpoa_large_option_threshold', 50000 ); // about 50 KB.
            if ( $size_threshold <= 0 ) {
                $size_threshold = 50000;
            }

            $large_options = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, autoload, LENGTH(option_value) AS size_bytes
                     FROM {$options_table}
                     WHERE LENGTH(option_value) >= %d
                     ORDER BY size_bytes DESC
                     LIMIT 200",
                    $size_threshold
                )
            );

            // Large transient like options.
            $transient_threshold = (int) apply_filters( 'wpoa_large_transient_threshold', 20000 );
            if ( $transient_threshold <= 0 ) {
                $transient_threshold = 20000;
            }

            $large_transients = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, autoload, LENGTH(option_value) AS size_bytes
                     FROM {$options_table}
                     WHERE (option_name LIKE %s OR option_name LIKE %s)
                       AND LENGTH(option_value) >= %d
                     ORDER BY size_bytes DESC
                     LIMIT 200",
                    $wpdb->esc_like( '_transient_' ) . '%',
                    $wpdb->esc_like( '_site_transient_' ) . '%',
                    $transient_threshold
                )
            );

            return array(
                'total_options'   => $total_options,
                'autoload_count'  => $autoload_options,
                'autoload_bytes'  => $autoload_bytes,
                'top_autoload'    => $top_autoload,
                'large_options'   => $large_options,
                'size_threshold'  => $size_threshold,
                'large_transients'=> $large_transients,
                'transient_threshold' => $transient_threshold,
            );
        }

        private function human_bytes( $bytes ) {
            $bytes = (float) $bytes;
            if ( $bytes < 1024 ) {
                return $bytes . ' B';
            }
            $kb = $bytes / 1024;
            if ( $kb < 1024 ) {
                return number_format_i18n( $kb, 1 ) . ' KB';
            }
            $mb = $kb / 1024;
            if ( $mb < 1024 ) {
                return number_format_i18n( $mb, 2 ) . ' MB';
            }
            $gb = $mb / 1024;
            return number_format_i18n( $gb, 2 ) . ' GB';
        }

        private function render_summary( $audit ) {
            $total    = (int) $audit['total_options'];
            $autoload = (int) $audit['autoload_count'];
            $bytes    = (int) $audit['autoload_bytes'];

            $autoload_pct = $total > 0 ? round( ( $autoload / $total ) * 100 ) : 0;
            $readable_size = $this->human_bytes( $bytes );

            ?>
            <table class="widefat striped" style="max-width:800px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Total options', 'wp-options-bloat-auditor' ); ?></th>
                        <td><?php echo esc_html( $total ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Autoloaded options', 'wp-options-bloat-auditor' ); ?></th>
                        <td>
                            <?php
                            echo esc_html( $autoload ) . ' ';
                            printf(
                                esc_html__( '(%s percent of total)', 'wp-options-bloat-auditor' ),
                                esc_html( $autoload_pct )
                            );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Approximate autoloaded data size', 'wp-options-bloat-auditor' ); ?></th>
                        <td><?php echo esc_html( $readable_size ); ?></td>
                    </tr>
                </tbody>
            </table>
            <p style="font-size:12px;opacity:0.8;">
                <?php esc_html_e( 'This size is loaded on most page loads. If this gets silly, you feel it as slow admin screens and front end requests.', 'wp-options-bloat-auditor' ); ?>
            </p>
            <?php
        }

        private function render_top_autoload( $audit ) {
            $rows = $audit['top_autoload'];

            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'No autoloaded options detected. That would be unusual. Either this is a very stripped down install or something is hiding reality.', 'wp-options-bloat-auditor' ) . '</p>';
                return;
            }

            echo '<table class="widefat striped"><thead>';
            echo '<tr><th>' . esc_html__( 'Option name', 'wp-options-bloat-auditor' ) . '</th><th>' . esc_html__( 'Size', 'wp-options-bloat-auditor' ) . '</th></tr>';
            echo '</thead><tbody>';

            foreach ( $rows as $row ) {
                $name = $row->option_name;
                $size = (int) $row->size_bytes;

                echo '<tr>';
                echo '<td><code>' . esc_html( $name ) . '</code></td>';
                echo '<td>' . esc_html( $this->human_bytes( $size ) ) . ' <span style="opacity:0.7;font-size:11px;">(' . esc_html( $size ) . ' bytes)</span></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<p style="font-size:12px;opacity:0.8;">';
            esc_html_e( 'If you see single options here measured in megabytes, you probably know which plugin caused it. Or you will after a quick search.', 'wp-options-bloat-auditor' );
            echo '</p>';
        }

        private function render_large_options( $audit ) {
            $rows           = $audit['large_options'];
            $size_threshold = (int) $audit['size_threshold'];

            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'No options exceeded the large option threshold in this scan.', 'wp-options-bloat-auditor' ) . '</p>';
                return;
            }

            echo '<p style="font-size:12px;opacity:0.8;">';
            printf(
                esc_html__( 'Threshold is currently %s bytes. You can change this with the wpoa_large_option_threshold filter.', 'wp-options-bloat-auditor' ),
                esc_html( $size_threshold )
            );
            echo '</p>';

            echo '<table class="widefat striped"><thead>';
            echo '<tr>';
            echo '<th>' . esc_html__( 'Option name', 'wp-options-bloat-auditor' ) . '</th>';
            echo '<th>' . esc_html__( 'Autoload', 'wp-options-bloat-auditor' ) . '</th>';
            echo '<th>' . esc_html__( 'Size', 'wp-options-bloat-auditor' ) . '</th>';
            echo '</tr>';
            echo '</thead><tbody>';

            foreach ( $rows as $row ) {
                $name   = $row->option_name;
                $size   = (int) $row->size_bytes;
                $autoload = ( $row->autoload === 'yes' );

                echo '<tr>';
                echo '<td><code>' . esc_html( $name ) . '</code></td>';
                echo '<td>' . ( $autoload ? '<span style="color:#46b450;">yes</span>' : '<span style="opacity:0.7;">no</span>' ) . '</td>';
                echo '<td>' . esc_html( $this->human_bytes( $size ) ) . ' <span style="opacity:0.7;font-size:11px;">(' . esc_html( $size ) . ' bytes)</span></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<p style="font-size:12px;opacity:0.8;">';
            esc_html_e( 'Some of these will be legitimate caches or configuration blobs. Some will be dead weight from plugins you removed years ago.', 'wp-options-bloat-auditor' );
            echo '</p>';
        }

        private function render_large_transients( $audit ) {
            $rows = $audit['large_transients'];
            $threshold = (int) $audit['transient_threshold'];

            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'No large transient like options detected at or above the threshold.', 'wp-options-bloat-auditor' ) . '</p>';
                return;
            }

            echo '<p style="font-size:12px;opacity:0.8;">';
            printf(
                esc_html__( 'Threshold is currently %s bytes. You can change this with the wpoa_large_transient_threshold filter.', 'wp-options-bloat-auditor' ),
                esc_html( $threshold )
            );
            echo '</p>';

            echo '<table class="widefat striped"><thead>';
            echo '<tr>';
            echo '<th>' . esc_html__( 'Option name', 'wp-options-bloat-auditor' ) . '</th>';
            echo '<th>' . esc_html__( 'Autoload', 'wp-options-bloat-auditor' ) . '</th>';
            echo '<th>' . esc_html__( 'Size', 'wp-options-bloat-auditor' ) . '</th>';
            echo '</tr>';
            echo '</thead><tbody>';

            foreach ( $rows as $row ) {
                $name   = $row->option_name;
                $size   = (int) $row->size_bytes;
                $autoload = ( $row->autoload === 'yes' );

                echo '<tr>';
                echo '<td><code>' . esc_html( $name ) . '</code></td>';
                echo '<td>' . ( $autoload ? '<span style="color:#46b450;">yes</span>' : '<span style="opacity:0.7;">no</span>' ) . '</td>';
                echo '<td>' . esc_html( $this->human_bytes( $size ) ) . ' <span style="opacity:0.7;font-size:11px;">(' . esc_html( $size ) . ' bytes)</span></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<p style="font-size:12px;opacity:0.8;">';
            esc_html_e( 'Transients should be disposable. If you see very large ones that never seem to change, consider whether the code that created them is still installed.', 'wp-options-bloat-auditor' );
            echo '</p>';
        }

        public function inject_plugin_list_icon_css() {
            $icon_url = esc_url( $this->get_brand_icon_url() );
            ?>
            <style>
                .wp-list-table.plugins tr[data-slug="wp-options-bloat-auditor"] .plugin-title strong::before {
                    content: '';
                    display: inline-block;
                    vertical-align: middle;
                    width: 18px;
                    height: 18px;
                    margin-right: 6px;
                    background-image: url('<?php echo $icon_url; ?>');
                    background-repeat: no-repeat;
                    background-size: contain;
                }
            </style>
            <?php
        }
    }

    new WP_Options_Bloat_Auditor();
}
