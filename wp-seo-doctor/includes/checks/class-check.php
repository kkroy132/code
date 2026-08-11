<?php
/**
 * Base class every SEO Check extends — Free's ~45 checks (Step 6) and
 * every Pro check register the same way via seodoc_register_check().
 *
 * @package SEODoc
 */

namespace SEODoc\Checks;

use SEODoc\Issues\Issue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Check {

	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_HIGH     = 'high';
	const SEVERITY_MEDIUM   = 'medium';
	const SEVERITY_LOW      = 'low';

	/**
	 * @return string Unique id, e.g. 'missing-meta-description'.
	 */
	abstract public function get_id();

	/**
	 * @return string 'on-page' | 'technical' | 'links' | 'content'.
	 */
	abstract public function get_category();

	/**
	 * Cheap pre-check so, e.g., an image-ALT check can skip a post with
	 * no images without doing any real work.
	 *
	 * @param Scan_Context $context
	 * @return bool
	 */
	abstract public function applies_to( Scan_Context $context );

	/**
	 * @param Scan_Context $context
	 * @return Issue[] Empty array when nothing is wrong.
	 */
	abstract public function run( Scan_Context $context );

	/**
	 * Convenience constructor for the common case of "one issue about the
	 * object this context wraps."
	 *
	 * @param Scan_Context $context
	 * @param string       $severity
	 * @param string       $title
	 * @param array        $details
	 * @return Issue
	 */
	protected function issue( Scan_Context $context, $severity, $title, array $details = array() ) {
		return new Issue(
			array(
				'check_id'    => $this->get_id(),
				'category'    => $this->get_category(),
				'severity'    => $severity,
				'object_type' => $context->get_object_type(),
				'object_id'   => $context->get_object_id(),
				'url'         => $context->get_permalink(),
				'title'       => $title,
				'details'     => $details,
			)
		);
	}
}
