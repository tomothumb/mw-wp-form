<?php
/**
 * Plugin Name: MW WP Form
 * Plugin URI: http://plugins.2inc.org/mw-wp-form/
 * Description: MW WP Form can create mail form with a confirmation screen.
 * Version: 2.1.4
 * Author: Takashi Kitajima
 * Author URI: http://2inc.org
 * Created : September 25, 2012
 * Modified: December 6, 2014
 * Text Domain: mw-wp-form
 * Domain Path: /languages/
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */
include_once( plugin_dir_path( __FILE__ ) . 'system/mwf_functions.php' );
include_once( plugin_dir_path( __FILE__ ) . 'system/mwf_config.php' );
$MW_WP_Form = new MW_WP_Form();
class MW_WP_Form {

	protected $Model;

	/**
	 * __construct
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_init_files' ), 9 );
		add_action( 'plugins_loaded', array( $this, 'init' ), 11 );
		// 有効化した時の処理
		register_activation_hook( __FILE__, array( __CLASS__, 'activation' ) );
		// アンインストールした時の処理
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );
	}

	/**
	 * load_init_files
	 * init に必要なファイルをロード
	 */
	public function load_init_files() {
		$plugin_dir_path = plugin_dir_path( __FILE__ );
		include_once( $plugin_dir_path . 'system/class.model.php' );
		include_once( $plugin_dir_path . 'system/mw_wp_form_admin_page.php' );
		include_once( $plugin_dir_path . 'system/mw_wp_form_contact_data_page.php' );
		include_once( $plugin_dir_path . 'system/mw_session.php' );
		include_once( $plugin_dir_path . 'system/mw_wp_form_data.php' );
		include_once( $plugin_dir_path . 'system/mw_validation_rule.php' );
		include_once( $plugin_dir_path . 'system/mw_form_field.php' );
		include_once( $plugin_dir_path . 'system/mw_wp_form_chart_page.php' );
	}

	/**
	 * init
	 * ファイルの読み込み等
	 */
	public function init() {
		load_plugin_textdomain( MWF_Config::DOMAIN, false, basename( dirname( __FILE__ ) ) . '/languages' );
		$this->Model = new MW_WP_Form_Model();

		// 管理画面の実行
		$MW_WP_Form_Contact_Data_Page = new MW_WP_Form_Contact_Data_Page();
		$MW_WP_Form_Chart_Page        = new MW_WP_Form_Chart_Page();
		add_action( 'init', array( $this, 'register_post_type' ) );

		// フォームフィールドの読み込み、インスタンス化
		$this->Model->init_form_fields();

		// バリデーションルールの読み込み、インスタンス化
		$this->Model->init_validation_rules();
		$this->Model->set_validation_rules_in_admin_page();

		if ( is_admin() ) {
			return;
		}

		$plugin_dir_path = plugin_dir_path( __FILE__ );
		include_once( $plugin_dir_path . 'system/mw_akismet.php' );
		include_once( $plugin_dir_path . 'system/mw_error.php' );
		include_once( $plugin_dir_path . 'system/mw_form.php' );
		include_once( $plugin_dir_path . 'system/mw_mail.php' );
		include_once( $plugin_dir_path . 'system/mw_validation.php' );
		include_once( $plugin_dir_path . 'system/mw_wp_form_file.php' );
		add_filter( 'nocache_headers' , array( $this->Model, 'nocache_headers' ) , 1 );
		add_filter( 'template_include', array( $this, 'main' ), 10000 );
		add_action( 'parse_request'   , array( $this, 'remove_query_vars_from_post' ) );
	}

	/**
	 * remove_query_vars_from_post
	 * WordPressへのリクエストに含まれている、$_POSTの値を削除
	 */
	public function remove_query_vars_from_post( $wp_query ) {
		if ( isset( $_POST['token'], $_SERVER['REQUEST_METHOD'] ) && 
			 strtolower( $_SERVER['REQUEST_METHOD'] ) === 'post' ) {
			$query_vars = $this->Model->remove_query_vars_from_post( $wp_query->query_vars, $_POST );
			$wp_query->query_vars = $query_vars;
		}
	}

	/**
	 * activation
	 * 有効化した時の処理
	 */
	public static function activation() {
	}

	/**
	 * uninstall
	 * アンインストールした時の処理
	 */
	public static function uninstall() {
		$forms = get_posts( array(
			'post_type' => MWF_Config::NAME,
			'posts_per_page' => -1,
		) );

		$data_post_ids = array();
		foreach ( $forms as $form ) {
			$data_post_ids[] = $form->ID;
			wp_delete_post( $form->ID, true );
		}

		foreach ( $data_post_ids as $data_post_id ) {
			delete_option( MWF_Config::NAME . '-chart-' . $data_post_id );
			$data_posts = get_posts( array(
				'post_type' => MWF_Config::DBDATA . $data_post_id,
				'posts_per_page' => -1,
			) );
			if ( empty( $data_posts ) ) continue;
			foreach ( $data_posts as $data_post ) {
				wp_delete_post( $data_post->ID, true );
			}
		}

		include_once( plugin_dir_path( __FILE__ ) . 'system/mw_wp_form_file.php' );
		$File = new MW_WP_Form_File();
		$File->removeTempDir();

		delete_option( MWF_Config::NAME );
	}

	/**
	 * register_post_type
	 * 管理画面（カスタム投稿タイプ）の設定
	 */
	public function register_post_type() {
		register_post_type( MWF_Config::NAME, array(
			'label'    => 'MW WP Form',
			'labels'   => array(
				'name' => 'MW WP Form',
				'singular_name'      => 'MW WP Form',
				'add_new_item'       => __( 'Add New Form', MWF_Config::DOMAIN ),
				'edit_item'          => __( 'Edit Form', MWF_Config::DOMAIN ),
				'new_item'           => __( 'New Form', MWF_Config::DOMAIN ),
				'view_item'          => __( 'View Form', MWF_Config::DOMAIN ),
				'search_items'       => __( 'Search Forms', MWF_Config::DOMAIN ),
				'not_found'          => __( 'No Forms found', MWF_Config::DOMAIN ),
				'not_found_in_trash' => __( 'No Forms found in Trash', MWF_Config::DOMAIN ),
			),
			'capability_type' => 'page',
			'public'          => false,
			'show_ui'         => true,
		) );
	}

	/**
	 * wp_enqueue_scripts
	 */
	public function wp_enqueue_scripts() {
		$url = plugin_dir_url( __FILE__ );
		wp_enqueue_style( MWF_Config::NAME, $url . 'css/style.css' );

		$style  = $this->Model->get_option( 'style' );
		$styles = apply_filters( 'mwform_styles', array() );
		if ( is_array( $styles ) && isset( $styles[$style] ) ) {
			$css = $styles[$style];
			wp_enqueue_style( MWF_Config::NAME . '_style', $css );
		}
		wp_enqueue_script( MWF_Config::NAME, $url . 'js/form.js', array( 'jquery' ), false, true );
	}

	/**
	 * scroll_script
	 */
	public function scroll_script() {
		$url = plugin_dir_url( __FILE__ );
		wp_register_script(
			MWF_Config::NAME . '-scroll',
			$url . 'js/scroll.js',
			array( 'jquery' ),
			false,
			true
		);
		wp_localize_script( MWF_Config::NAME . '-scroll', 'mwform_scroll', array(
			'offset' => apply_filters( 'mwform_scroll_offset_' . $this->Model->get_key(), 0 ),
		) );
		wp_enqueue_script( MWF_Config::NAME . '-scroll' );
	}

	/**
	 * main
	 * 表示画面でのプラグインの処理等。
	 * @param string $template
	 * @return string $template
	 */
	public function main( $template ) {
		global $post;

		$this->Model->init( $post, $template );

		// フォームが定義されていない場合は終了
		if ( !$this->Model->is_initialized() ) {
			return $template;
		}

		nocache_headers();

		// セッション初期化
		// $_FILESがあるときは$this->dataに統合
		$this->Model->init_request_data( $_POST, $_FILES );

		// フォームオブジェクト生成
		$this->Model->init_form();

		// フォームオブジェクト生成
		$this->Model->init_validation();

		// バリデーション実行（Validation->dataに値がないと$Errorは返さない（true））
		$this->Model->validate();

		// ファイル操作オブジェクト生成
		$this->Model->init_file();

		$input            = $this->Model->get_input();
		$confirm          = $this->Model->get_confirm();
		$complete         = $this->Model->get_complete();
		$validation_error = $this->Model->get_validation_error();
		$REQUEST_URI      = $this->Model->get_request_uri();
		$post_condition   = $this->Model->get_post_condition();

		// 入力画面（戻る）のとき
		if ( $post_condition === 'back' ) {
			$this->redirect( $input );
		}
		// 確認画面のとき
		elseif ( $post_condition === 'confirm' ) {
			$this->Model->fileUpload();
			if ( $this->Model->is_valid() ) {
				$this->Model->set_view_flg( 'confirm' );
				$this->redirect( $confirm );
			} else {
				if ( !$this->Model->get_validation_error() ) {
					$this->redirect( $validation_error );
				} else {
					$this->redirect( $input );
				}
			}
		}
		// 完了画面のとき
		elseif ( $post_condition === 'complete' ) {
			$this->Model->fileUpload();
			if ( $this->Model->is_valid() ) {
				$this->Model->set_view_flg( 'complete' );
				if ( $this->Model->get_token() ) {
					$this->Model->send();
					$this->Model->clear_token();

					// 手動フォーム対応
					if ( !$this->Model->is_generated_by_formkey() &&
						 $REQUEST_URI !== $complete && $input !== $complete ) {
						$this->Model->clear_data();
					}
				}
				$this->redirect( $complete );
			} else {
				if ( !empty( $validation_error ) ) {
					$this->redirect( $validation_error );
				} else {
					$this->redirect( $input );
				}
			}
		}
		// 完了 or 確認画面 or エラーURLが設定済みで
		// 完了 or 確認画面 or エラーに直接アクセスした場合、
		// 入力画面に戻れれば戻る。戻れない場合はトップに戻す
		else {
			$check_urls = array(
				$confirm,
				$complete,
			);
			$back_url = ( $input ) ? $input : home_url();
			foreach ( $check_urls as $check_url ) {
				if ( $REQUEST_URI === $check_url ) {
					$this->Model->clear_data();
					$this->redirect( $back_url );
				}
			}
			$this->redirect( $input );

			if ( $this->Model->is_valid() && $REQUEST_URI == $validation_error ) {
				$this->Model->clear_data();
				$this->redirect( $back_url );
			}
		}

		// スクロール用スクリプトのロード
		if ( $this->Model->get_option( 'scroll' ) ) {
			if ( $post_condition === 'confirm' || $post_condition === 'complete' || !$this->Model->is_valid() ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'scroll_script' ) );
			}
		}

		// 画面表示用のショートコードを登録
		$this->Model->add_shortocde_that_display_content();

		add_action( 'wp_footer'         , array( $this->Model, 'clear_data' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
		return $template;
	}

	/**
	 * redirect
	 * 現在のURLと引数で渡されたリダイレクトURLが同じであればリダイレクトしない
	 * @param string リダイレクトURL
	 */
	private function redirect( $url ) {
		$redirect = ( empty( $url ) ) ? $this->Model->get_request_uri() : $url;
		$REQUEST_URI = $this->Model->get_request_uri();
		if ( !empty( $_POST ) || $redirect != $REQUEST_URI ) {
			$redirect = wp_sanitize_redirect( $redirect );
			$redirect = wp_validate_redirect( $redirect, home_url() );
			wp_redirect( $redirect );
			exit();
		}
	}
}
