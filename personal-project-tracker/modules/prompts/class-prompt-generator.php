<?php
/**
 * Centralized AI prompt assembly service.
 *
 * @package Personal_Project_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTP_Prompt_Generator
 *
 * Builds structured prompt text entirely from this plugin's own data, via
 * the existing repositories/services other modules already own — it never
 * re-derives a calculation (time totals, financial figures, report counts)
 * and never calls out to an external AI API. The output is plain text the
 * user copies into whichever AI tool they choose (Gemini, Claude, ChatGPT,
 * or any other).
 *
 * Every free-text field pulled from another module (descriptions, note
 * content, link descriptions/URLs, user-entered requirements/constraints)
 * is passed through redact() before it reaches the assembled prompt, so a
 * pasted secret does not silently end up in text meant to be copied
 * elsewhere. This is a best-effort, regex-based heuristic — not a
 * guarantee — scoped to common key=value/key: value secret shapes.
 *
 * Like PTP_Finance_Service/PTP_Analytics_Service, this class stays free of
 * capability-check side effects: callers determine whether the current
 * user may view Finance and pass that as $args['finance_allowed'] rather
 * than this class calling current_user_can() itself.
 */
class PTP_Prompt_Generator {

	/**
	 * Allowed AI role values and their display labels.
	 *
	 * @var array<string, string>
	 */
	const ROLES = array(
		'software_engineer'    => 'Software Engineer',
		'project_manager'      => 'Project Manager',
		'product_manager'      => 'Product Manager',
		'ui_ux_designer'       => 'UI/UX Designer',
		'business_analyst'     => 'Business Analyst',
		'marketing_strategist' => 'Marketing Strategist',
		'content_writer'       => 'Content Writer',
		'data_analyst'         => 'Data Analyst',
		'financial_analyst'    => 'Financial Analyst',
		'qa_engineer'          => 'QA Engineer',
		'custom'               => 'Custom',
	);

	/**
	 * Allowed output format values, their display labels, and the
	 * instruction sentence used in the EXPECTED OUTPUT section.
	 *
	 * @var array<string, string>
	 */
	const OUTPUT_FORMATS = array(
		'plain_text'   => 'Plain Text',
		'markdown'     => 'Markdown',
		'bullet_list'  => 'Bullet List',
		'step_by_step' => 'Step-by-Step',
		'table'        => 'Table',
		'json'         => 'JSON',
		'checklist'    => 'Checklist',
		'report'       => 'Report',
		'custom'       => 'Custom',
	);

	/**
	 * Regex patterns used to scrub likely secrets out of free text before it
	 * is included in a generated prompt. Deliberately narrow (key=value /
	 * key: value shapes, bearer tokens, AWS-style access key IDs, JWTs)
	 * rather than a broad "looks sensitive" prose match, to avoid false
	 * positives on ordinary English while still catching the most common
	 * accidental-secret-paste shapes.
	 *
	 * @var string[]
	 */
	private static $secret_patterns = array(
		'bearer'    => '/\bBearer\s+[A-Za-z0-9\-_\.]{10,}/i',
		'aws_key'   => '/\bAKIA[0-9A-Z]{16}\b/',
		'jwt'       => '/\bey[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
		'key_value' => '/\b((?:api[_-]?key|apikey|secret(?:[_-]?key)?|token|password|passwd|pwd|access[_-]?key(?:[_-]?id)?|auth(?:orization)?|client[_-]?secret|private[_-]?key)\s*[:=]\s*)(["\']?)(\S+?)\2(?=[\s,;)]|$)/i',
	);

	/**
	 * Best-effort secret redaction applied to every free-text field pulled
	 * into a generated prompt. See the class docblock for scope/limits.
	 *
	 * Patterns run narrowest/most-specific first: an "Authorization: Bearer
	 * <token>" header must have its Bearer <token> pair redacted as a whole
	 * before the generic key=value pattern runs — otherwise the generic
	 * pattern would match only the word "Bearer" as the "value" for the
	 * "Authorization" key (stopping at the space before the real token) and
	 * leave the actual token behind, unredacted.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function redact( $text ) {
		$text = (string) $text;

		$text = preg_replace( self::$secret_patterns['bearer'], 'Bearer [REDACTED]', $text );
		$text = preg_replace( self::$secret_patterns['aws_key'], '[REDACTED]', $text );
		$text = preg_replace( self::$secret_patterns['jwt'], '[REDACTED]', $text );
		$text = preg_replace( self::$secret_patterns['key_value'], '$1[REDACTED]', $text );

		return null !== $text ? $text : (string) $text;
	}

	/**
	 * Strip tags, redact, and trim a rich-text/description field down to a
	 * manageable length for inclusion in a context block.
	 *
	 * @param string $text  Raw text (may contain limited HTML).
	 * @param int    $words Max words to keep.
	 * @return string
	 */
	private static function clean_snippet( $text, $words = 60 ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = self::redact( $text );

		return trim( wp_trim_words( $text, $words, '…' ) );
	}

	/**
	 * Sanitize and validate a prompt's context-selection config (task_ids,
	 * milestone_ids, include_* flags, requirements/constraints, custom role/
	 * output labels). Shared by the repository's prepare_fields() and the
	 * REST "generate" endpoint so the two never drift apart.
	 *
	 * @param array $raw Raw config input.
	 * @return array|WP_Error
	 */
	public static function sanitize_config( array $raw ) {
		$errors = new WP_Error();
		$config = array();

		$task_ids = array();

		foreach ( (array) ( $raw['task_ids'] ?? array() ) as $task_id ) {
			$task_id = absint( $task_id );

			if ( ! $task_id ) {
				continue;
			}

			if ( ! PTP_Tasks_Repository::exists( $task_id ) ) {
				$errors->add( 'task_invalid', __( 'One of the selected tasks does not exist.', 'personal-project-tracker' ) );
				continue;
			}

			$task_ids[] = $task_id;
		}

		$config['task_ids'] = array_values( array_unique( $task_ids ) );

		$milestone_ids = array();

		foreach ( (array) ( $raw['milestone_ids'] ?? array() ) as $milestone_id ) {
			$milestone_id = absint( $milestone_id );

			if ( ! $milestone_id ) {
				continue;
			}

			if ( ! PTP_Milestones_Repository::exists( $milestone_id ) ) {
				$errors->add( 'milestone_invalid', __( 'One of the selected milestones does not exist.', 'personal-project-tracker' ) );
				continue;
			}

			$milestone_ids[] = $milestone_id;
		}

		$config['milestone_ids'] = array_values( array_unique( $milestone_ids ) );

		foreach ( array( 'include_time', 'include_notes', 'include_links', 'include_files', 'include_finance', 'include_reports', 'include_activity' ) as $flag ) {
			$config[ $flag ] = ! empty( $raw[ $flag ] );
		}

		$config['requirements']         = isset( $raw['requirements'] ) ? substr( PTP_Security::sanitize_textarea( $raw['requirements'] ), 0, 2000 ) : '';
		$config['constraints']          = isset( $raw['constraints'] ) ? substr( PTP_Security::sanitize_textarea( $raw['constraints'] ), 0, 2000 ) : '';
		$config['custom_role_label']    = isset( $raw['custom_role_label'] ) ? substr( PTP_Security::sanitize_text( $raw['custom_role_label'] ), 0, 255 ) : '';
		$config['custom_output_label']  = isset( $raw['custom_output_label'] ) ? substr( PTP_Security::sanitize_text( $raw['custom_output_label'] ), 0, 255 ) : '';

		$ai_tool           = isset( $raw['ai_tool'] ) ? sanitize_key( wp_unslash( (string) $raw['ai_tool'] ) ) : '';
		$config['ai_tool'] = in_array( $ai_tool, array( 'gemini', 'claude', 'chatgpt', 'other', 'generic' ), true ) ? $ai_tool : 'generic';

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return $config;
	}

	/**
	 * @param string $role               Role slug.
	 * @param string $custom_role_label  Custom label, used when $role is 'custom'.
	 * @return string
	 */
	private static function resolve_role_label( $role, $custom_role_label ) {
		if ( 'custom' === $role ) {
			return '' !== trim( (string) $custom_role_label ) ? trim( (string) $custom_role_label ) : __( 'Custom Role', 'personal-project-tracker' );
		}

		return self::ROLES[ $role ] ?? self::ROLES['custom'];
	}

	/**
	 * @param string $format               Output format slug.
	 * @param string $custom_output_label  Custom label, used when $format is 'custom'.
	 * @return string
	 */
	private static function resolve_output_instruction( $format, $custom_output_label ) {
		if ( 'custom' === $format ) {
			return '' !== trim( (string) $custom_output_label )
				? trim( (string) $custom_output_label )
				: __( 'Respond in the format the user requests below.', 'personal-project-tracker' );
		}

		$instructions = array(
			'plain_text'   => __( 'Respond in plain, unformatted text.', 'personal-project-tracker' ),
			'markdown'     => __( 'Respond using Markdown formatting (headings, lists, code blocks as appropriate).', 'personal-project-tracker' ),
			'bullet_list'  => __( 'Respond as a bulleted list.', 'personal-project-tracker' ),
			'step_by_step' => __( 'Respond as a numbered, step-by-step sequence.', 'personal-project-tracker' ),
			'table'        => __( 'Respond as a table with clear column headers.', 'personal-project-tracker' ),
			'json'         => __( 'Respond as valid JSON only, with no surrounding prose.', 'personal-project-tracker' ),
			'checklist'    => __( 'Respond as a checklist of actionable items.', 'personal-project-tracker' ),
			'report'       => __( 'Respond as a structured report with clear headings and sections.', 'personal-project-tracker' ),
		);

		return $instructions[ $format ] ?? $instructions['plain_text'];
	}

	/**
	 * Generate prompt text from a full prompt configuration. Purely a
	 * read-and-assemble operation — nothing here persists anything, so this
	 * is safe to call repeatedly (e.g. from a "Regenerate" action) without
	 * side effects; saving a prompt document is a separate, explicit step.
	 *
	 * @param array $args {
	 *     @type string $role                Role slug (one of self::ROLES).
	 *     @type string $custom_role_label   Custom label when role is 'custom'.
	 *     @type string $goal                Required goal/objective text.
	 *     @type string $output_format       Output format slug (one of self::OUTPUT_FORMATS).
	 *     @type string $custom_output_label Custom label when output_format is 'custom'.
	 *     @type int    $project_id          Optional project context.
	 *     @type int[]  $task_ids            Optional task context.
	 *     @type int[]  $milestone_ids       Optional milestone context.
	 *     @type bool   $include_time        Include Time Context.
	 *     @type bool   $include_notes       Include Notes.
	 *     @type bool   $include_links       Include Links.
	 *     @type bool   $include_files       Include file metadata (nested under Project Context).
	 *     @type bool   $include_finance     Include Financial Context.
	 *     @type bool   $include_reports     Include a report summary (nested under Project Context).
	 *     @type bool   $include_activity    Include recent activity (nested under Project Context).
	 *     @type bool   $finance_allowed     Whether the current user may view Finance (checked by the
	 *                                       caller, e.g. REST/admin — never by this class); gates both
	 *                                       Financial Context and the Report Summary's revenue/expenses/profit.
	 *     @type string $requirements        Optional free-text requirements.
	 *     @type string $constraints         Optional free-text constraints.
	 * }
	 * @return array{content: string, warnings: string[]}
	 */
	public static function generate( array $args ) {
		$warnings = array();
		$sections = array();

		$project_id    = ! empty( $args['project_id'] ) ? (int) $args['project_id'] : 0;
		$task_ids      = array_map( 'absint', (array) ( $args['task_ids'] ?? array() ) );
		$milestone_ids = array_map( 'absint', (array) ( $args['milestone_ids'] ?? array() ) );

		$sections['ROLE']      = self::resolve_role_label( $args['role'] ?? 'custom', $args['custom_role_label'] ?? '' );
		$sections['OBJECTIVE'] = self::redact( trim( (string) ( $args['goal'] ?? '' ) ) );

		if ( $project_id ) {
			$block = self::build_project_context( $project_id, $args );

			if ( '' !== $block ) {
				$sections['PROJECT CONTEXT'] = $block;
			}
		}

		if ( ! empty( $task_ids ) ) {
			$block = self::build_task_context( $task_ids );

			if ( '' !== $block ) {
				$sections['TASK CONTEXT'] = $block;
			}
		}

		if ( ! empty( $milestone_ids ) ) {
			$block = self::build_milestone_context( $milestone_ids );

			if ( '' !== $block ) {
				$sections['MILESTONE CONTEXT'] = $block;
			}
		}

		if ( ! empty( $args['include_time'] ) && ( $project_id || ! empty( $task_ids ) ) ) {
			$block = self::build_time_context( $project_id, $task_ids );

			if ( '' !== $block ) {
				$sections['TIME CONTEXT'] = $block;
			}
		}

		$finance_allowed = ! empty( $args['finance_allowed'] );

		if ( ! empty( $args['include_finance'] ) ) {
			if ( $project_id && $finance_allowed ) {
				$block = self::build_finance_context( $project_id );

				if ( '' !== $block ) {
					$sections['FINANCIAL CONTEXT'] = $block;
				}
			} elseif ( $project_id ) {
				$sections['FINANCIAL CONTEXT'] = __( 'Financial data is not available to your account.', 'personal-project-tracker' );
				$warnings[]                    = __( 'Financial context was requested but withheld because your account cannot view Finance.', 'personal-project-tracker' );
			}
		}

		if ( ! empty( $args['include_notes'] ) ) {
			$block = self::build_notes_context( $project_id, $task_ids, $milestone_ids );

			if ( '' !== $block ) {
				$sections['NOTES'] = $block;
			}
		}

		if ( ! empty( $args['include_links'] ) ) {
			$block = self::build_links_context( $project_id, $task_ids, $milestone_ids );

			if ( '' !== $block ) {
				$sections['LINKS'] = $block;
			}
		}

		$requirements = self::redact( trim( (string) ( $args['requirements'] ?? '' ) ) );

		if ( '' !== $requirements ) {
			$sections['REQUIREMENTS'] = $requirements;
		}

		$constraints = self::redact( trim( (string) ( $args['constraints'] ?? '' ) ) );

		if ( '' !== $constraints ) {
			$sections['CONSTRAINTS'] = $constraints;
		}

		$sections['EXPECTED OUTPUT'] = self::resolve_output_instruction( $args['output_format'] ?? 'plain_text', $args['custom_output_label'] ?? '' );

		$quality_rules   = array();
		$quality_rules[] = __( 'Do not invent missing information.', 'personal-project-tracker' );
		$quality_rules[] = __( 'Only use the context provided above; if something is not covered, say so instead of guessing.', 'personal-project-tracker' );
		$quality_rules[] = __( 'Never include or ask for passwords, API keys, tokens, secrets, or other authentication information.', 'personal-project-tracker' );
		$quality_rules[] = __( 'Note any assumptions you have to make.', 'personal-project-tracker' );

		$sections['QUALITY RULES'] = implode(
			"\n",
			array_map(
				function ( $rule ) {
					return '- ' . $rule;
				},
				$quality_rules
			)
		);

		$content = '';

		foreach ( $sections as $header => $body ) {
			$content .= $header . "\n" . str_repeat( '-', strlen( $header ) ) . "\n" . $body . "\n\n";
		}

		return array(
			'content'  => trim( $content ),
			'warnings' => $warnings,
		);
	}

	/**
	 * @param int   $project_id Project ID.
	 * @param array $args       Full generate() args, used here for the include_files/include_reports/include_activity/finance_allowed flags.
	 * @return string
	 */
	private static function build_project_context( $project_id, array $args ) {
		$project = PTP_Projects_Repository::get( $project_id );

		if ( ! $project ) {
			return '';
		}

		$statuses   = PTP_Projects_Repository::get_statuses();
		$priorities = PTP_Projects_Repository::get_priorities();

		$lines   = array();
		$lines[] = 'Title: ' . $project->title;
		$lines[] = 'Status: ' . ( $statuses[ $project->status ] ?? $project->status );
		$lines[] = 'Priority: ' . ( $priorities[ $project->priority ] ?? $project->priority );
		$lines[] = 'Progress: ' . (int) $project->progress . '%';

		if ( $project->start_date ) {
			$lines[] = 'Start Date: ' . $project->start_date;
		}

		if ( $project->deadline ) {
			$lines[] = 'Deadline: ' . $project->deadline;
		}

		if ( $project->description ) {
			$lines[] = 'Description: ' . self::clean_snippet( $project->description, 120 );
		}

		if ( ! empty( $args['include_files'] ) && class_exists( 'PTP_Files_Repository' ) ) {
			$files = PTP_Files_Repository::get_list( array( 'project_id' => $project_id, 'per_page' => 20 ) )['items'];

			if ( ! empty( $files ) ) {
				$lines[] = '';
				$lines[] = 'Files:';

				foreach ( $files as $file ) {
					$size    = $file->file_size ? size_format( (int) $file->file_size ) : '';
					$lines[] = '- ' . $file->file_name . ' (' . $file->file_type . ( $size ? ', ' . $size : '' ) . ')';
				}
			}
		}

		if ( ! empty( $args['include_reports'] ) && class_exists( 'PTP_Reports_Service' ) ) {
			$report_rows = PTP_Reports_Service::get_project_report( array( 'project_id' => $project_id ), ! empty( $args['finance_allowed'] ) )['items'];
			$report_row  = $report_rows[0] ?? null;

			if ( $report_row ) {
				$lines[] = '';
				$lines[] = 'Report Summary:';
				$lines[] = '- Tasks: ' . $report_row['tasks_completed'] . '/' . $report_row['tasks_total'] . ' completed, ' . $report_row['tasks_overdue'] . ' overdue';
				$lines[] = '- Milestones: ' . $report_row['milestones_completed'] . '/' . $report_row['milestones_total'] . ' completed';
				$lines[] = '- Tracked Time: ' . ptp_format_duration( $report_row['tracked_seconds'] );

				if ( ! empty( $args['finance_allowed'] ) ) {
					if ( null !== $report_row['currency'] ) {
						$lines[] = '- Revenue: ' . ptp_format_currency( $report_row['revenue'], $report_row['currency'] );
						$lines[] = '- Expenses: ' . ptp_format_currency( $report_row['expenses'], $report_row['currency'] );
						$lines[] = '- Profit: ' . ptp_format_currency( $report_row['profit'], $report_row['currency'] );
					}
				} else {
					$lines[] = '- Financial figures are not available to your account.';
				}
			}
		}

		if ( ! empty( $args['include_activity'] ) && class_exists( 'PTP_Activity_Log' ) ) {
			$activity = PTP_Activity_Log::get_for_object( 'project', $project_id, 10 );

			if ( ! empty( $activity ) ) {
				$lines[] = '';
				$lines[] = 'Recent Activity:';

				foreach ( $activity as $entry ) {
					$lines[] = '- ' . self::redact( $entry->description ? $entry->description : $entry->action ) . ' (' . $entry->created_at . ')';
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param int[] $task_ids Task IDs.
	 * @return string
	 */
	private static function build_task_context( array $task_ids ) {
		$statuses   = PTP_Tasks_Repository::get_statuses();
		$priorities = PTP_Tasks_Repository::get_priorities();
		$blocks     = array();

		foreach ( $task_ids as $task_id ) {
			$task = PTP_Tasks_Repository::get( $task_id );

			if ( ! $task ) {
				continue;
			}

			$lines   = array();
			$lines[] = '- ' . $task->title . ' [' . ( $statuses[ $task->status ] ?? $task->status ) . ', ' . ( $priorities[ $task->priority ] ?? $task->priority ) . ']';

			if ( $task->due_date ) {
				$lines[] = '  Due: ' . $task->due_date;
			}

			if ( $task->description ) {
				$lines[] = '  ' . self::clean_snippet( $task->description, 60 );
			}

			$blocks[] = implode( "\n", $lines );
		}

		return implode( "\n", $blocks );
	}

	/**
	 * @param int[] $milestone_ids Milestone IDs.
	 * @return string
	 */
	private static function build_milestone_context( array $milestone_ids ) {
		$statuses   = PTP_Milestones_Repository::get_statuses();
		$priorities = PTP_Milestones_Repository::get_priorities();
		$blocks     = array();

		foreach ( $milestone_ids as $milestone_id ) {
			$milestone = PTP_Milestones_Repository::get( $milestone_id );

			if ( ! $milestone ) {
				continue;
			}

			$lines   = array();
			$lines[] = '- ' . $milestone->title . ' [' . ( $statuses[ $milestone->status ] ?? $milestone->status ) . ', ' . ( $priorities[ $milestone->priority ] ?? $milestone->priority ) . ', ' . (int) $milestone->progress . '%]';

			if ( $milestone->due_date ) {
				$lines[] = '  Due: ' . $milestone->due_date;
			}

			if ( $milestone->description ) {
				$lines[] = '  ' . self::clean_snippet( $milestone->description, 60 );
			}

			$blocks[] = implode( "\n", $lines );
		}

		return implode( "\n", $blocks );
	}

	/**
	 * @param int   $project_id Project ID (0 if none selected).
	 * @param int[] $task_ids   Task IDs.
	 * @return string
	 */
	private static function build_time_context( $project_id, array $task_ids ) {
		$lines = array();

		if ( $project_id ) {
			$lines[] = 'Project Total: ' . ptp_format_duration( PTP_Time_Repository::get_project_total( $project_id ) );
		}

		foreach ( $task_ids as $task_id ) {
			$task = PTP_Tasks_Repository::get( $task_id );

			if ( ! $task ) {
				continue;
			}

			$lines[] = '- ' . $task->title . ': ' . ptp_format_duration( PTP_Time_Repository::get_task_total( $task_id ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Only called by generate() after confirming the current user holds
	 * ptp_manage_finance — this method itself performs no capability check,
	 * matching PTP_Finance_Service's own pattern of staying free of
	 * capability side effects.
	 *
	 * @param int $project_id Project ID.
	 * @return string
	 */
	private static function build_finance_context( $project_id ) {
		$summary = PTP_Finance_Service::get_project_summary( $project_id );

		if ( ! $summary ) {
			return '';
		}

		$lines   = array();
		$lines[] = 'Currency: ' . $summary['currency'];

		if ( null !== $summary['budget'] ) {
			$lines[] = 'Budget: ' . ptp_format_currency( $summary['budget'], $summary['currency'] );
		}

		$lines[] = 'Revenue: ' . ptp_format_currency( $summary['revenue'], $summary['currency'] );
		$lines[] = 'Expenses: ' . ptp_format_currency( $summary['expenses'], $summary['currency'] );
		$lines[] = 'Profit: ' . ptp_format_currency( $summary['profit'], $summary['currency'] );

		if ( null !== $summary['profit_margin'] ) {
			$lines[] = 'Profit Margin: ' . $summary['profit_margin'] . '%';
		}

		if ( null !== $summary['budget_usage'] ) {
			$lines[] = 'Budget Usage: ' . $summary['budget_usage'] . '%';
		}

		if ( $summary['flags']['over_budget'] ) {
			$lines[] = 'Alert: Over budget.';
		} elseif ( $summary['flags']['budget_warning'] ) {
			$lines[] = 'Alert: Approaching budget limit.';
		}

		if ( $summary['flags']['negative_profit'] ) {
			$lines[] = 'Alert: Currently running at a loss.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param int   $project_id    Project ID (0 if none selected).
	 * @param int[] $task_ids      Task IDs.
	 * @param int[] $milestone_ids Milestone IDs.
	 * @return string
	 */
	private static function build_notes_context( $project_id, array $task_ids, array $milestone_ids ) {
		$notes = self::collect_related( 'PTP_Notes_Repository', $project_id, $task_ids, $milestone_ids, 15 );

		if ( empty( $notes ) ) {
			return '';
		}

		$lines = array();

		foreach ( $notes as $note ) {
			$title   = $note->title ? $note->title : __( '(untitled)', 'personal-project-tracker' );
			$lines[] = '- ' . $title . ': ' . self::clean_snippet( $note->content, 50 );
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param int   $project_id    Project ID (0 if none selected).
	 * @param int[] $task_ids      Task IDs.
	 * @param int[] $milestone_ids Milestone IDs.
	 * @return string
	 */
	private static function build_links_context( $project_id, array $task_ids, array $milestone_ids ) {
		$links = self::collect_related( 'PTP_Links_Repository', $project_id, $task_ids, $milestone_ids, 15 );

		if ( empty( $links ) ) {
			return '';
		}

		$lines = array();

		foreach ( $links as $link ) {
			$lines[] = '- ' . $link->title . ': ' . self::redact( $link->url );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Shared helper for Notes/Links: query a repository once per selected
	 * relationship (project, each task, each milestone) and merge the
	 * results by ID, since PTP_Notes_Repository::get_list()/
	 * PTP_Links_Repository::get_list() AND their filters together rather
	 * than OR them.
	 *
	 * @param string $repository    Repository class name (must expose get_list()).
	 * @param int    $project_id    Project ID (0 if none selected).
	 * @param int[]  $task_ids      Task IDs.
	 * @param int[]  $milestone_ids Milestone IDs.
	 * @param int    $limit         Max items to return.
	 * @return object[]
	 */
	private static function collect_related( $repository, $project_id, array $task_ids, array $milestone_ids, $limit ) {
		$by_id = array();

		if ( $project_id ) {
			foreach ( $repository::get_list( array( 'project_id' => $project_id, 'per_page' => $limit ) )['items'] as $item ) {
				$by_id[ $item->id ] = $item;
			}
		}

		foreach ( $task_ids as $task_id ) {
			foreach ( $repository::get_list( array( 'task_id' => $task_id, 'per_page' => $limit ) )['items'] as $item ) {
				$by_id[ $item->id ] = $item;
			}
		}

		foreach ( $milestone_ids as $milestone_id ) {
			foreach ( $repository::get_list( array( 'milestone_id' => $milestone_id, 'per_page' => $limit ) )['items'] as $item ) {
				$by_id[ $item->id ] = $item;
			}
		}

		return array_slice( array_values( $by_id ), 0, $limit );
	}
}
