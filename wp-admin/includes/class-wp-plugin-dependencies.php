<?php
/**
 * WordPress Plugin Administration API: WP_Plugin_Dependencies class
 *
 * @package WordPress
 * @subpackage Administration
 * @since 5.9.0
 */

/**
 * Core class for installing plugin dependencies.
 *
 * It is designed to add plugin dependencies as designated in the
 * `Requires Plugins` header to a new view in the plugins install page.
 */
class WP_Plugin_Dependencies {

	/**
	 * Holds `get_plugins()`.
	 *
	 * @var array
	 */
	protected $plugins;

	/**
	 * Holds an array of sanitized plugin dependency slugs.
	 *
	 * @var array
	 */
	protected $slugs;

	/**
	 * Holds plugin data for plugin dependencies.
	 *
	 * @var array
	 */
	protected $plugin_data;

	/**
	 * Initialize, load filters, and get started.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'plugins_api_result', array( $this, 'plugins_api_result' ), 10, 3 );
		add_filter( 'plugin_install_description', array( $this, 'plugin_install_description' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'network_admin_notices', array( $this, 'admin_notices' ) );

		$required_headers = $this->parse_headers();
		$this->slugs      = $this->sanitize_required_headers( $required_headers );
		$this->get_dot_org_data();
	}

	/**
	 * Run get_plugins() and store result.
	 *
	 * @return array
	 */
	public function get_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$this->plugins = get_plugins();

		return $this->plugins;
	}

	/**
	 * Parse 'Requires Plugins' header.
	 * Store result with dependent plugin.
	 *
	 * @return \stdClass
	 */
	public function parse_headers() {
		$this->get_plugins();
		$required_headers = array();
		foreach ( array_keys( $this->plugins ) as $plugin ) {
			$requires_plugins = get_file_data( WP_PLUGIN_DIR . '/' . $plugin, array( 'RequiresPlugins' => 'Requires Plugins' ) );
			if ( ! empty( $requires_plugins['RequiresPlugins'] ) ) {
				$required_headers[ $plugin ] = $requires_plugins;
			}
		}

		return $required_headers;
	}

	/**
	 * Sanitize headers.
	 *
	 * @param array $required_headers Array of required plugin headers.
	 * @return array
	 */
	public function sanitize_required_headers( $required_headers ) {
		$all_slugs = array();
		foreach ( $required_headers as $key => $headers ) {
			$sanitized_slugs = array();
			$exploded        = explode( ',', $headers['RequiresPlugins'] );
			foreach ( $exploded as $slug ) {
				$slug = trim( $slug );

				// Match to dot org slug format.
				if ( preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
					$sanitized_slugs[] = $slug;
				}
			}
			$sanitized_slugs                          = array_unique( $sanitized_slugs );
			$this->plugins[ $key ]['RequiresPlugins'] = $sanitized_slugs;
			$all_slugs                                = array_merge( $all_slugs, $sanitized_slugs );
		}
		asort( $all_slugs );

		return array_unique( $all_slugs );
	}

	/**
	 * Get plugin data from WordPress API.
	 * Store result in $this->plugin_data.
	 */
	private function get_dot_org_data() {
		foreach ( $this->slugs as $slug ) {
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			$args     = array(
				'slug'   => $slug,
				'fields' => array(
					'short_description' => true,
					'icons'             => true,
				),
			);
			$response = plugins_api( 'plugin_information', $args );
			if ( is_wp_error( $response ) ) {
				continue;
			}

			$this->plugin_data[ $response->slug ] = (array) $response;
			asort( $this->plugin_data );
		}
	}

	/**
	 * Modify plugins_api() response.
	 *
	 * @param \stdClas  $res Object of results.
	 * @param string    $action Variable for plugins_api().
	 * @param \stdClass $args Object of plugins_api() args.
	 *
	 * @return \stdClass
	 */
	public function plugins_api_result( $res, $action, $args ) {
		if ( property_exists( $args, 'browse' ) && 'dependencies' === $args->browse ) {
			$res->info = array(
				'page'    => 1,
				'pages'   => 1,
				'results' => count( (array) $this->plugin_data ),
			);

			$res->plugins = $this->plugin_data;
		}

		return $res;
	}

	/**
	 * Add 'Required by: ...' to plugin install dependencies view.
	 *
	 * @param string $description Short description of plugin.
	 * @param array  $plugin Array of plugin data.
	 *
	 * @return string
	 */
	public function plugin_install_description( $description, $plugin ) {
		$required   = null;
		$dependents = $this->get_dependency_sources( $plugin );
		if ( ! empty( $dependents ) ) {
			$dependents = explode( ',', $dependents );
			foreach ( $dependents as $dependent ) {
				$required .= '<br>' . $dependent;
			}
			$required    = '<strong>' . __( 'Required by:' ) . '</strong>' . $required;
			$description = '<p>' . $required . '</p>' . $description;
		}

		return $description;
	}

	/**
	 * Modify the plugin row.
	 *
	 * @return void
	 */
	public function admin_init() {
		foreach ( array_keys( $this->plugins ) as $plugin_file ) {
			$this->modify_plugin_row( $plugin_file );
		}
	}

	/**
	 * Display admin notice if dependencies not installed.
	 *
	 * @return void
	 */
	public function admin_notices() {
		$installed_slugs = array_map( 'dirname', array_keys( $this->plugins ) );
		$intersect       = array_intersect( $this->slugs, $installed_slugs );
		asort( $intersect );
		if ( $intersect !== $this->slugs ) {
			printf(
				'<div class="notice-warning notice is-dismissible"><p>'
					/* translators: 1: opening tag and link to Dependencies install page, 2: closing tag */
					. esc_html__( 'There are additional plugins that must be installed. Go to the %1$sDependencies%2$s install page.' )
					. '</p></div>',
				'<a href=' . esc_url_raw( admin_url( 'plugin-install.php?tab=dependencies' ) ) . '>',
				'</a>'
			);
		}
	}

	/**
	 * Acutally make modifications to plugin row.
	 *
	 * @param string $plugin_file Plugin file.
	 */
	private function modify_plugin_row( $plugin_file ) {
		add_filter( 'network_admin_plugin_action_links_' . $plugin_file, array( $this, 'unset_action_links' ), 10, 2 );
		add_filter( 'plugin_action_links_' . $plugin_file, array( $this, 'unset_action_links' ), 10, 2 );
		add_action( 'after_plugin_row_' . $plugin_file, array( $this, 'modify_plugin_row_elements' ), 10, 2 );
	}

	/**
	 * Unset plugin action links so required plugins can't be removed or deactivated.
	 *
	 * @param array  $actions     Action links.
	 * @param string $plugin_file Plugin file.
	 *
	 * @return array
	 */
	public function unset_action_links( $actions, $plugin_file ) {
		if ( in_array( dirname( $plugin_file ), $this->slugs, true ) ) {
			if ( isset( $actions['delete'] ) ) {
				unset( $actions['delete'] );
			}
			if ( isset( $actions['deactivate'] ) ) {
				unset( $actions['deactivate'] );
			}
		}

		return $actions;
	}

	/**
	 * Modify the plugin row elements.
	 * Removes plugin row checkbox.
	 * Adds 'Required by: ...' information.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param array  $plugin_data Array of plugin data.
	 *
	 * @return void
	 */
	public function modify_plugin_row_elements( $plugin_file, $plugin_data ) {
		if ( in_array( dirname( $plugin_file ), $this->slugs, true ) ) {
			print '<script>';
			print 'jQuery("tr[data-plugin=\'' . esc_attr( $plugin_file ) . '\'] .plugin-version-author-uri").append("<br><br><strong>' . esc_html__( 'Required by:' ) . '</strong> ' . esc_html( $this->get_dependency_sources( $plugin_data ) ) . '");';
			print 'jQuery(".inactive[data-plugin=\'' . esc_attr( $plugin_file ) . '\']").attr("class", "active");';
			print 'jQuery(".active[data-plugin=\'' . esc_attr( $plugin_file ) . '\'] .check-column input").remove();';
			print '</script>';
		}
	}

	/**
	 * Get formatted string of dependent plugins.
	 *
	 * @param array $plugin_data Array of plugin data.
	 *
	 * @return string $dependents
	 */
	private function get_dependency_sources( $plugin_data ) {
		$sources = array();
		foreach ( $this->plugins as $plugin ) {
			if ( ! empty( $plugin['RequiresPlugins'] ) ) {
				foreach ( $plugin['RequiresPlugins'] as $dependent ) {
					if ( isset( $this->plugin_data[ $dependent ] ) && in_array( $plugin_data['slug'], $plugin['RequiresPlugins'], true ) ) {
						$sources[] = $plugin['Name'];
					}
				}
			}
		}
		$sources = array_unique( $sources );
		asort( $sources );
		$sources = implode( ', ', $sources );

		return $sources;
	}
}

( new WP_Plugin_Dependencies() )->init();