<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class GESFaturacao_Admin {
	private $option_name  = 'gesfaturacao_options';
	private $option_group = 'gesfaturacao_options_group';

	public function __construct() {
		add_action('admin_menu',          [ $this, 'add_menu' ]);
		add_action('admin_init',          [ $this, 'register_settings' ]);
		add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
		add_action('admin_notices',       [ $this, 'admin_notices' ]);
		add_action('admin_footer', function () {
			// Only run on our plugin’s “Configurações” page:
			$screen = get_current_screen();
			if ( $screen->id !== 'gesfaturacao_page_gesfaturacao-settings' ) {
				return;
			}
			?>
			<script>
                (function() {
                    // Build a URL object from the current full href
                    const url = new URL(window.location.href);

                    // Remove just the “setup” parameter (not “setup=complete”)
                    url.searchParams.delete('setup');

                    // Replace the URL in the address bar without reloading
                    window.history.replaceState({}, document.title, url.toString());
                })();
			</script>
			<?php
		});

	}

	public function add_menu() {
		add_menu_page(
			'Configurações',
			'GESFaturacao',
			'manage_options',
			'gesfaturacao-main',
			[ $this, 'render_admin_page' ],
			'dashicons-media-document',
			60
		);
	}


	public function register_settings() {
		// 1. Register the main option array
		register_setting(
			$this->option_group,
			$this->option_name,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_inputs' ],
				'default'           => [
					'domain'      => '',
					'token'       => '',
					'api_version' => 'v1.0.4',
					'serie'       => '',
					'finalize'    => false,
					'email'       => false,
				],
			]
		);

		// 2. Add a settings section (wrapping all fields)
		add_settings_section(
			'gesfaturacao_main_section',
			'', // we’ll output our <h1> separately, so leave the title blank
			'__return_empty_string',
			'gesfaturacao-main' // matches page slug in add_menu_page()
		);

		// 3. Add fields one by one:

		// Domain
		add_settings_field(
			'gesfaturacao_domain',
			'Domínio da licença',
			[ $this, 'field_domain_cb' ],
			'gesfaturacao-main',
			'gesfaturacao_main_section'
		);

		// Token
		add_settings_field(
			'gesfaturacao_token',
			'Token',
			[ $this, 'field_token_cb' ],
			'gesfaturacao-main',
			'gesfaturacao_main_section'
		);

		// API Version
		add_settings_field(
			'gesfaturacao_api_version',
			'Versão API',
			[ $this, 'field_api_version_cb' ],
			'gesfaturacao-main',
			'gesfaturacao_main_section'
		);

		// Serie
		add_settings_field(
			'gesfaturacao_serie',
			'Série',
			[ $this, 'field_serie_cb' ],
			'gesfaturacao-main',
			'gesfaturacao_main_section'
		);

		// Finalize
		add_settings_field(
			'gesfaturacao_finalize',
			'Finalizar fatura',
			[ $this, 'field_finalize_cb' ],
			'gesfaturacao-main',
			'gesfaturacao_main_section'
		);

		// Send Email
		add_settings_field(
			'gesfaturacao_email',
			'Envia fatura por email',
			[ $this, 'field_email_cb' ],
			'gesfaturacao-main',
			'gesfaturacao_main_section'
		);

	}

	/**
	 * Sanitize and return the inputs array
	 */
	/*public function sanitize_inputs( $inputs ) {
		$sanitized = [];

		$sanitized['domain']      = sanitize_text_field( $inputs['domain'] ?? '' );
		$sanitized['token']       = sanitize_text_field( $inputs['token'] ?? '' );
		$sanitized['api_version'] = sanitize_text_field( $inputs['api_version'] ?? 'v1.0.4' );
		$sanitized['serie']       = sanitize_text_field( $inputs['serie'] ?? '' );
		$sanitized['finalize']    = ! empty( $inputs['finalize'] ) ? true : false;
		$sanitized['email']       = ! empty( $inputs['email'] ) ? true : false;

		update_option( 'gesfaturacao_setup_done', 1 );

		return $sanitized;
	}*/

	public function sanitize_inputs($input) {
		// Get previous saved options for fallback
		$old_options = get_option($this->option_name, []);

		// Sanitize minimally for usage in API calls
		$sanitized = [];
		$sanitized['domain'] = isset($input['domain']) ? sanitize_text_field($input['domain']) : '';
		$sanitized['token'] = isset($input['token']) ? sanitize_text_field($input['token']) : '';
		$sanitized['api_version'] = isset($input['api_version']) ? sanitize_text_field($input['api_version']) : 'v1.0.4';
		$sanitized['serie'] = isset($input['serie']) ? sanitize_text_field($input['serie']) : '';
		$sanitized['finalize'] = !empty($input['finalize']);
		$sanitized['email'] = !empty($input['email']);

		set_transient('gesfaturacao_has_errors', false, 0);
		// Instantiate API class
		$api = new GesFaturacao_API();

		// Validate domain via API
		$domain_data = [
			'subdomain' => $sanitized['domain'],
			'opcao' => 2,
		];
		$validate_domain = $api->validate_domain($domain_data);

		if (is_wp_error($validate_domain)) {
			add_settings_error(
				'gesfaturacao_messages',
				'domain_error',
				'Domínio inválido. Foi revertido para o valor anterior. Por favor, verifique o domínio e tente novamente.',
				'error'
			);
			set_transient('gesfaturacao_has_errors', true, 0); //
			return $old_options; // revert to old
		}

		// Validate API version (simple check)
		if ($sanitized['api_version'] !== 'v1.0.4') {
			add_settings_error(
				'gesfaturacao_messages',
				'api_version_error',
				'Versão API inválida. Foi revertida para o valor anterior. Por favor, selecione a versão v1.0.4',
				'error'
			);
			set_transient('gesfaturacao_has_errors', true, 0); //
			return $old_options; // revert to old
		}

		// Validate token via API
		$validate_token = $api->validate_token($sanitized['token'], $sanitized['domain']);


		if (is_wp_error($validate_token) || !isset($validate_token['_token'])) {
			add_settings_error(
				'gesfaturacao_messages',
				'token_error',
				'Token é inválido. Foi revertido para o valor anterior. Por favor, verifique o token e tente novamente.',
				'error'
			);
			set_transient('gesfaturacao_has_errors', true, 0); //
			return $old_options; // revert to old
		}

		// If all validation passes, return sanitized inputs to be saved
		return $sanitized;
	}


	/**
	 * Render the main admin page (HTML wrapper + form)
	 */
	public function render_admin_page() {
		$setup_done = (int) get_option('gesfaturacao_setup_done', 0);

		/*if ( $setup_done === 0 ) {
			//Dhow the setup screen instead.
			$this->render_setup_page();
			return;
		}*/
		// Otherwise setup_done, show the normal settings page:
		$this->render_settings_page();
	}


	//Normal settings page
	public function render_settings_page(){

		if (isset($_GET['setup']) && $_GET['setup'] == 'complete') {
			add_settings_error(
				'gesfaturacao_messages',
				'success_message',
				'Configurações guardadas',
				'success'
			);
		}

		// Load stored options
		$options  = get_option( 'gesfaturacao_options', [] );
		//$logoUrl  = esc_url( $options['logo_url'] ?? '' ); // or whatever key holds your logo URL
		$logoUrl = plugin_dir_url(__FILE__) . '../assets/gesfaturacao-light.png'; 

		echo '<div class="wrap">';
		echo '<h1 class="gesfaturacao-title">Configurações</h1>';

		// Begin container for logo+form
		echo '<div class="gesfaturacao-main-wrapper">';



		// 2) The actual form
		echo '<form method="post" action="options.php" class="gesfaturacao-form">';
		// 1) Logo on top, centered
		if ( $logoUrl ) {
			echo '<div class="gesfaturacao-logo-container">';
			echo '<img src="' . $logoUrl . '" alt="Logo GesFaturacao" class="gesfaturacao-logo" />';
			echo '</div>';
		}
		settings_fields( $this->option_group );
		do_settings_sections( 'gesfaturacao-main' );
		// Submit button will wrap itself in <p class="submit">…</p>
		echo '<p class="submit">';
		echo '<button type="submit" name="gesfaturacao-submit" class="gesfaturacao-submit">Gravar</button>';
		echo '</p>';
		echo '</form>';

		echo '</div>'; // .gesfaturacao-form-wrapper
		echo '</div>

		<style>
		.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
			background-color: #f2994b;
			color: white;
		}
		</style>
		'; // .wrap
	}


	//First setup page
/*	public function render_setup_page() {
		// If the form was just submitted, handle it
		//$this->handle_initial_setup();

		// Load stored options (so fields can be pre‐filled)
		$options = get_option( 'gesfaturacao_options', [] );
		$logoUrl = plugin_dir_url( __FILE__ ) . '../assets/gesfaturacao-light.png';

		echo '<div class="wrap">';
		echo '<h1 class="gesfaturacao-title">Configurações Iniciais</h1>';

		// Print any setup‐specific errors
		settings_errors( 'gesfaturacao_setup_messages' );

		echo '<div class="gesfaturacao-form-wrapper">';
		if ( $logoUrl ) {
			echo '<div class="gesfaturacao-logo-container">';
			echo '<img src="' . esc_url( $logoUrl ) . '" alt="Logo GesFaturacao" class="gesfaturacao-logo" />';
			echo '</div>';
		}

		// Use the exact same calls, but post back to admin.php?page=…
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=gesfaturacao-settings' ) ) . '" class="gesfaturacao-form">';
		wp_nonce_field( 'gesfaturacao_initial_setup' );

		//  This will output all registered fields (domain/token/api_version/finalize/email/series),
        // but “field_serie_cb” will detect setup_done===0 and just show its placeholder.
		settings_fields( $this->option_group );
		do_settings_sections( 'gesfaturacao-main' );

		echo '<p class="submit">';
		echo '<button type="submit" name="gesfaturacao_setup_submit" class="gesfaturacao-submit">Gravar</button>';
		echo '</p>';
		echo '</form>';
		echo '</div>'; // .gesfaturacao-form-wrapper
		echo '</div>'; // .wrap
		echo '<style>
    .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
        background-color: #f2994b; color: white;
    }
    </style>';
	}*/

	/**
	 * Field callback: Domain input + addon
	 */
	public function field_domain_cb() {
		$opts   = get_option( $this->option_name );
		$domain = esc_attr( $opts['domain'] ?? '' );

		echo '<div class="input-container">';
		echo '<input type="text" name="gesfaturacao_options[domain]" id="ges_dom_licenca" 
                  required autocomplete="off" style="width:100%; padding:5px; margin-bottom:10px;" 
                  value="' . $domain . '">';
		echo '<div class="input-addon"><span class="input-addon-text">.gesfaturacao.pt</span></div>';
		echo '</div>';
	}

	/**
	 * Field callback: Token
	 */
	public function field_token_cb() {
		$opts  = get_option( $this->option_name );
		$token = esc_attr( $opts['token'] ?? '' );
		echo '<input type="text" name="gesfaturacao_options[token]" id="ges_token" 
                  required autocomplete="off" style="width:100%; padding:5px; margin-bottom:10px;" 
                  value="' . $token . '">';
	}

	/**
	 * Field callback: API Version select
	 */
	public function field_api_version_cb() {
		$opts       = get_option( $this->option_name );
		$api_version = esc_attr( $opts['api_version'] ?? 'v1.0.4' );
		$versions   = [ 'v1.0.4', 'v1.0.3', 'v1.0.2' ];

		echo '<select name="gesfaturacao_options[api_version]" id="api_version" 
                      style="width:100%; padding:5px; margin-bottom:10px;">';
		foreach ( $versions as $ver ) {
			$sel = ( $api_version === $ver ) ? 'selected' : '';
			echo '<option value="' . esc_attr( $ver ) . '" ' . $sel . '>' . esc_html( $ver ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Field callback: Serie select (using your existing series_options builder)
	 */
	public function field_serie_cb() {
		$setup_done = (int) get_option( 'gesfaturacao_setup_done', 0 );
		if ( $setup_done !== 1 ) {
			// During first‐run, don’t render any Series dropdown
			echo '<p><em>A série será definida para a predefinida no GESFaturação. Poderá depois alterar após a configuração inicial.</em></p>';
			return;
		}

        $api = new GESFaturacao_API();
		$opts = get_option( $this->option_name );
		$current = esc_attr( $opts['serie'] ?? '' );
		$series = $api->get_series();
		$series = $series['data'];
		echo '<select name="gesfaturacao_options[serie]" id="ges_serie" 
                      style="width:100%; padding:5px; margin-bottom:10px;">';
		if ( is_wp_error( $series ) ) {
			echo '<option disabled>' . esc_html( $series->get_error_message() ) . '</option>';
		} else {
			foreach ( $series as $s ) {
				$sel = ( intval( $current ) === intval( $s['id'] ) ) ? 'selected' : '';
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $s['id'] ),
					$sel,
					esc_html( $s['name'] )
				);
			}
		}
		echo '</select>';
	}

	/**
	 * Field callback: Finalize checkbox
	 */
	public function field_finalize_cb() {
		$opts     = get_option( $this->option_name );
		$checked  = ! empty( $opts['finalize'] ) ? 'checked' : '';
		echo '<input type="checkbox" name="gesfaturacao_options[finalize]" id="ges_finalize" 
                    value="1" ' . $checked . '>';
	}

	/**
	 * Field callback: Send email checkbox
	 */
	public function field_email_cb() {
		$opts    = get_option( $this->option_name );
		$checked = ! empty( $opts['email'] ) ? 'checked' : '';
		echo '<input type="checkbox" name="gesfaturacao_options[email]" id="ges_email" 
                    value="1" ' . $checked . '>';
	}

	/**
	 * Display any settings_errors added via add_settings_error()
	 */
	public function admin_notices() {
		// Check if there are settings errors saved (WordPress built-in)
		$errors = get_settings_errors('gesfaturacao_messages');
		$has_errors = !empty($errors);

		// Check transient flag set in sanitize_inputs on error
		$has_error_flag = get_transient('gesfaturacao_has_errors');

		// If there were errors, delete the transient so message doesn't repeat
		if ($has_error_flag) {
			delete_transient('gesfaturacao_has_errors');
		}

		// If errors exist or transient flag is true, just show errors and skip success
		if ($has_errors || $has_error_flag) {
			settings_errors('gesfaturacao_messages');
			return; // stop here, no success message
		}

		// If no errors and settings-updated=true in URL, show success notice
		if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
			add_settings_error(
				'gesfaturacao_messages',
				'gesfaturacao_success',
				'Configurações alteradas com sucesso.',
				'updated'
			);

			// Remove query var so message doesn’t repeat on reload
			unset($_GET['settings-updated']);
			settings_errors('gesfaturacao_messages');
			update_option( 'gesfaturacao_setup_done', 1 );
		}
	}




	public function enqueue_assets( $hook ) {
		// Only on your plugin's settings page
		if ( $hook !== 'gesfaturacao_page_gesfaturacao-settings' ) {
			return;
		}

		// Select2
		wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
		wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);


		// Enqueue WP admin CSS so our custom CSS can build on it
		wp_enqueue_style( 'wp-admin' );

		// Enqueue a small custom CSS file:
		wp_enqueue_style(
			'gesfaturacao-admin-css',
			plugin_dir_url( __FILE__ ) . '../css/gesfaturacao-admin.css',
			[],
			GESFATURACAO_VERSION
		);


		// Ensure jQuery is available
		wp_enqueue_script( 'jquery' );

		// Inline JS to auto‐fade any .notice.is-dismissible after 4 seconds
		wp_add_inline_script( 'jquery', '
        jQuery(document).ready(function($) {
            setTimeout(function() {
                $(".notice.is-dismissible").fadeOut();
            }, 4000);
            
            function initSelect2() {
				$("#api_version, #ges_serie").select2({
					placeholder: "Escolha uma opção...",
                    width: "100%",
                    search: false,
                    minimumResultsForSearch: -1
				});
			}

			// Initial load
			initSelect2();
        });
    ' );

	}

}

