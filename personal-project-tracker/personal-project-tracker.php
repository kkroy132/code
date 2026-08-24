<?php
/**
 * Plugin Name:       Personal Project Tracker
 * Plugin URI:         https://example.com/personal-project-tracker
 * Description:       A private, all-in-one project management and personal work tracking system for WordPress: projects, tasks, milestones, calendar, time tracking, finance, reports, analytics, an AI Prompt Studio, notifications and reminders.
 * Version:            1.0.0
 * Requires at least:  6.0
 * Requires PHP:       8.1
 * Author:             Personal Project Tracker
 * License:            GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        personal-project-tracker
 * Domain Path:        /languages
 *
 * @package Personal_Project_Tracker
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin constants.
 */
define( 'PTP_VERSION', '1.0.0' );
define( 'PTP_DB_VERSION', 8 );
define( 'PTP_TEXT_DOMAIN', 'personal-project-tracker' );
define( 'PTP_PLUGIN_FILE', __FILE__ );
define( 'PTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PTP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum requirements check.
 *
 * We keep this defensive check dependency-free (no class autoloading yet)
 * because it must run before we know PHP 8.1 language features are safe to use.
 */
function ptp_meets_requirements() {
	global $wp_version;

	$php_ok = version_compare( PHP_VERSION, '8.1', '>=' );
	$wp_ok  = version_compare( $wp_version, '6.0', '>=' );

	return $php_ok && $wp_ok;
}

/**
 * Show an admin notice when requirements are not met and stop loading the plugin.
 */
function ptp_requirements_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html__(
				'Personal Project Tracker requires PHP 8.1+ and WordPress 6.0+. The plugin has been disabled until these requirements are met.',
				'personal-project-tracker'
			);
			?>
		</p>
	</div>
	<?php
}

if ( ! ptp_meets_requirements() ) {
	add_action( 'admin_notices', 'ptp_requirements_notice' );
	return;
}

require_once PTP_PLUGIN_DIR . 'includes/helpers.php';
require_once PTP_PLUGIN_DIR . 'includes/class-database.php';
require_once PTP_PLUGIN_DIR . 'includes/class-permissions.php';
require_once PTP_PLUGIN_DIR . 'includes/class-security.php';
require_once PTP_PLUGIN_DIR . 'includes/class-settings.php';
require_once PTP_PLUGIN_DIR . 'includes/class-activator.php';
require_once PTP_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once PTP_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once PTP_PLUGIN_DIR . 'includes/class-activity-log.php';
require_once PTP_PLUGIN_DIR . 'includes/class-plugin.php';
require_once PTP_PLUGIN_DIR . 'admin/class-admin-menu.php';
require_once PTP_PLUGIN_DIR . 'admin/class-admin-pages.php';

// Feature modules.
require_once PTP_PLUGIN_DIR . 'modules/projects/class-projects-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/projects/class-projects-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/projects/class-projects-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/projects/class-projects-module.php';

require_once PTP_PLUGIN_DIR . 'modules/tasks/class-tasks-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/tasks/class-subtasks-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/tasks/class-tasks-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/tasks/class-tasks-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/tasks/class-subtasks-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/tasks/class-tasks-module.php';

require_once PTP_PLUGIN_DIR . 'modules/milestones/class-milestones-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/milestones/class-milestones-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/milestones/class-milestones-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/milestones/class-milestones-module.php';

require_once PTP_PLUGIN_DIR . 'modules/calendar/class-calendar-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/calendar/class-calendar-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/calendar/class-calendar-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/calendar/class-calendar-module.php';

require_once PTP_PLUGIN_DIR . 'modules/time/class-time-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/time/class-time-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/time/class-time-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/time/class-time-module.php';

require_once PTP_PLUGIN_DIR . 'modules/notes/class-notes-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/notes/class-notes-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/notes/class-notes-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/notes/class-notes-module.php';

require_once PTP_PLUGIN_DIR . 'modules/links/class-links-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/links/class-links-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/links/class-links-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/links/class-links-module.php';

require_once PTP_PLUGIN_DIR . 'modules/files/class-files-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/files/class-files-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/files/class-files-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/files/class-files-module.php';

require_once PTP_PLUGIN_DIR . 'modules/finance/class-expenses-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/finance/class-revenue-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/finance/class-finance-service.php';
require_once PTP_PLUGIN_DIR . 'modules/finance/class-finance-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/finance/class-finance-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/finance/class-finance-module.php';

require_once PTP_PLUGIN_DIR . 'modules/reports/class-reports-service.php';
require_once PTP_PLUGIN_DIR . 'modules/reports/class-analytics-service.php';
require_once PTP_PLUGIN_DIR . 'modules/reports/class-reports-export.php';
require_once PTP_PLUGIN_DIR . 'modules/reports/class-reports-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/reports/class-reports-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/reports/class-analytics-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/reports/class-reports-module.php';

require_once PTP_PLUGIN_DIR . 'modules/prompts/class-prompt-documents-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/prompts/class-prompt-templates-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/prompts/class-prompt-generator.php';
require_once PTP_PLUGIN_DIR . 'modules/prompts/class-prompts-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/prompts/class-prompts-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/prompts/class-prompts-module.php';

require_once PTP_PLUGIN_DIR . 'modules/notifications/class-notifications-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/notifications/class-reminders-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/notifications/class-notifications-service.php';
require_once PTP_PLUGIN_DIR . 'modules/notifications/class-smart-alerts-service.php';
require_once PTP_PLUGIN_DIR . 'modules/notifications/class-notifications-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/notifications/class-notifications-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/notifications/class-notifications-module.php';

require_once PTP_PLUGIN_DIR . 'modules/settings/class-settings-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/settings/class-settings-module.php';

require_once PTP_PLUGIN_DIR . 'modules/backup/class-backup-repository.php';
require_once PTP_PLUGIN_DIR . 'modules/backup/class-backup-service.php';
require_once PTP_PLUGIN_DIR . 'modules/backup/class-export-service.php';
require_once PTP_PLUGIN_DIR . 'modules/backup/class-import-service.php';
require_once PTP_PLUGIN_DIR . 'modules/backup/class-backup-controller.php';
require_once PTP_PLUGIN_DIR . 'modules/backup/class-backup-module.php';

require_once PTP_PLUGIN_DIR . 'modules/search/class-search-service.php';
require_once PTP_PLUGIN_DIR . 'modules/search/class-search-rest.php';
require_once PTP_PLUGIN_DIR . 'modules/search/class-search-module.php';

register_activation_hook( __FILE__, array( 'PTP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PTP_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin.
 */
function ptp_run_plugin() {
	$plugin = PTP_Plugin::get_instance();
	$plugin->run();
}
ptp_run_plugin();
