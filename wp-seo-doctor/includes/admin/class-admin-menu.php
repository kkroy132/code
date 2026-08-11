<?php
/**
 * Registers the menu tree from Step 1 §16. All pages render the same
 * mount point (#seodoc-app) with a data-route attribute — this is one
 * React SPA with a real WP submenu item (and bookmarkable URL) per
 * section, not one PHP screen per section. Sections with no backing data
 * yet (Links, 404 Monitor, Redirects, Content, Search Console, AI
 * Assistant — landing in Steps 9-13) still get a real menu entry now;
 * the client shows "coming soon" for routes it doesn't recognize rather
 * than the menu tree changing shape release to release.
 *
 * @package SEODoc
 */

namespace SEODoc\Admin;

use SEODoc\Capabilities;
use SEODoc\Module_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Menu {

	const PARENT_SLUG = 'seodoc';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	public function register() {
		$cap = Capabilities::required_capability();

		add_menu_page(
			__( 'WP SEO Doctor', 'wp-seo-doctor' ),
			__( 'WP SEO Doctor', 'wp-seo-doctor' ),
			$cap,
			self::PARENT_SLUG,
			$this->render_callback( 'overview' ),
			'dashicons-search',
			80
		);

		foreach ( $this->get_submenu_pages() as $page ) {
			add_submenu_page(
				self::PARENT_SLUG,
				$page['title'],
				$page['title'],
				$cap,
				$page['slug'],
				$this->render_callback( $page['route'] )
			);
		}

		foreach ( Module_Registry::get_admin_pages() as $page ) {
			add_submenu_page(
				self::PARENT_SLUG,
				$page['title'],
				$page['title'],
				isset( $page['capability'] ) ? $page['capability'] : $cap,
				$page['slug'],
				isset( $page['render'] ) ? $page['render'] : $this->render_callback( $page['slug'] )
			);
		}
	}

	/**
	 * @param string $route
	 * @return callable
	 */
	private function render_callback( $route ) {
		return function () use ( $route ) {
			printf( '<div id="seodoc-app" class="wrap" data-route="%s"></div>', esc_attr( $route ) );
		};
	}

	/**
	 * @return array<int, array{slug: string, route: string, title: string}>
	 */
	private function get_submenu_pages() {
		return array(
			array(
				'slug'  => self::PARENT_SLUG,
				'route' => 'overview',
				'title' => __( 'Overview', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-audit',
				'route' => 'audit',
				'title' => __( 'SEO Audit', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-action-plan',
				'route' => 'action-plan',
				'title' => __( 'Action Plan', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-links',
				'route' => 'links',
				'title' => __( 'Links', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-404',
				'route' => '404-monitor',
				'title' => __( '404 Monitor', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-redirects',
				'route' => 'redirects',
				'title' => __( 'Redirects', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-content',
				'route' => 'content',
				'title' => __( 'Content', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-gsc',
				'route' => 'search-console',
				'title' => __( 'Search Console', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-ai',
				'route' => 'ai-assistant',
				'title' => __( 'AI Assistant', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-reports',
				'route' => 'reports',
				'title' => __( 'Reports', 'wp-seo-doctor' ),
			),
			array(
				'slug'  => 'seodoc-settings',
				'route' => 'settings',
				'title' => __( 'Settings', 'wp-seo-doctor' ),
			),
		);
	}
}
