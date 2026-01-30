<?php
/**
 * Plugin Name: KGI Quote Checkout (WooCommerce)
 * Description: Custom multi-step quote checkout: Items -> Transport -> Details -> Quote Submitted. Creates a Woo order in "Quote Requested" status and lets admin send a pay link later.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) exit;

class KGI_Quote_Checkout {

  const SESSION_KEY          = 'kgi_quote_data';
  const NONCE_ACTION         = 'kgi_quote_nonce_action';
  const ORDER_META_KEY       = '_kgi_quote_data';
  const DISCLAIMER_META_KEY  = '_kgi_quote_disclaimer';

  const SETTINGS_KEY         = 'kgi_quote_checkout_settings';
  const OPTION_GETADDRESS_KEY = 'kgi_getaddress_api_key';

  public function __construct() {

    // Shortcode
    add_shortcode('kgi_quote_checkout', [$this, 'shortcode_quote_checkout']);

    // AJAX
    add_action('wp_ajax_kgi_quote_save_step',      [$this, 'ajax_save_step']);
    add_action('wp_ajax_nopriv_kgi_quote_save_step', [$this, 'ajax_save_step']);

    add_action('wp_ajax_kgi_quote_submit',         [$this, 'ajax_submit_quote']);
    add_action('wp_ajax_nopriv_kgi_quote_submit',  [$this, 'ajax_submit_quote']);

    add_action('wp_ajax_kgi_quote_cart_update',       [$this, 'ajax_cart_update']);
    add_action('wp_ajax_nopriv_kgi_quote_cart_update',[$this, 'ajax_cart_update']);

    add_action('wp_ajax_kgi_quote_cart_remove',       [$this, 'ajax_cart_remove']);
    add_action('wp_ajax_nopriv_kgi_quote_cart_remove',[$this, 'ajax_cart_remove']);

    // Postcode lookup (GetAddress.io via server-side WP AJAX)
    add_action('wp_ajax_kgi_quote_lookup_postcode',       [$this, 'ajax_lookup_postcode']);
    add_action('wp_ajax_nopriv_kgi_quote_lookup_postcode',[$this, 'ajax_lookup_postcode']);

    // Custom order status
    add_action('init', [$this, 'register_order_status']);
    add_filter('wc_order_statuses', [$this, 'add_order_status_to_list']);

    // Admin tools / display
    add_action('add_meta_boxes', [$this, 'add_admin_metabox']);
    add_action('add_meta_boxes_woocommerce_page_wc-orders', [$this, 'add_admin_metabox'], 10, 1); // HPOS orders screen
    add_action('admin_post_kgi_quote_send_pay_link', [$this, 'admin_send_pay_link']);
    add_action('admin_post_kgi_quote_apply_fees', [$this, 'admin_apply_fees']);

    // Show quote meta in order edit screen
    add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'admin_render_quote_details'], 10, 1);

    // Settings page
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'admin_register_settings']);

    // Ensure sessions available
    add_action('init', [$this, 'maybe_init_wc_session'], 5);
  }

  public function maybe_init_wc_session() {
    if (function_exists('WC') && WC()->session) {
      WC()->session->get(self::SESSION_KEY);
    }
  }

  // ----------------------------
  // Settings (GetAddress.io key)
  // ----------------------------
  public function admin_menu() {
    add_submenu_page(
      'woocommerce',
      'Quote Checkout',
      'Quote Checkout',
      'manage_woocommerce',
      self::SETTINGS_KEY,
      [$this, 'admin_settings_page']
    );
  }

  public function admin_register_settings() {
    register_setting(self::SETTINGS_KEY, self::OPTION_GETADDRESS_KEY, [
      'type' => 'string',
      'sanitize_callback' => 'sanitize_text_field',
      'default' => '',
    ]);

    add_settings_section('kgi_qc_main', 'GetAddress.io', function () {
      echo '<p>Enter your GetAddress.io API key to enable postcode address lookup.</p>';
    }, self::SETTINGS_KEY);

    add_settings_field(self::OPTION_GETADDRESS_KEY, 'GetAddress.io API Key', function () {
      $val = get_option(self::OPTION_GETADDRESS_KEY, '');
      echo '<input type="text" name="' . esc_attr(self::OPTION_GETADDRESS_KEY) . '" value="' . esc_attr($val) . '" class="regular-text" />';
      echo '<p class="description">This key is used server-side (not exposed in browser).</p>';
    }, self::SETTINGS_KEY, 'kgi_qc_main');
  }

  public function admin_settings_page() { ?>
    <div class="wrap">
      <h1>Quote Checkout</h1>
      <form method="post" action="options.php">
        <?php
        settings_fields(self::SETTINGS_KEY);
        do_settings_sections(self::SETTINGS_KEY);
        submit_button();
        ?>
      </form>
    </div>
  <?php }

  // ----------------------------
  // Helpers
  // ----------------------------
  private function session_get() {
    if (!function_exists('WC') || !WC()->session) return [];
    $data = WC()->session->get(self::SESSION_KEY);
    return is_array($data) ? $data : [];
  }

  private function session_set($data) {
    if (!function_exists('WC') || !WC()->session) return;
    WC()->session->set(self::SESSION_KEY, $data);
  }

  private function assert_ajax_nonce() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['message' => 'Security check failed'], 403);
    }
  }

  private function ensure_wc_cart() {
    if (!function_exists('WC')) return;

    if (null === WC()->session) {
      WC()->session = new WC_Session_Handler();
      WC()->session->init();
    }
    if (null === WC()->customer) {
      WC()->customer = new WC_Customer(get_current_user_id(), true);
    }
    if (null === WC()->cart) {
      WC()->cart = new WC_Cart();
    }
  }

  private function clean_postcode($postcode) {
    $postcode = strtoupper(trim((string)$postcode));
    $postcode = preg_replace('/\s+/', '', $postcode); // remove spaces
    $postcode = preg_replace('/[^A-Z0-9]/', '', $postcode);
    return $postcode;
  }

  // ----------------------------
  // Shortcode UI
  // ----------------------------
  public function shortcode_quote_checkout() {
    if (!function_exists('WC') || !WC()->cart) {
      return '<p>WooCommerce cart not available.</p>';
    }
    if (WC()->cart->is_empty()) {
      return '<p>Your quote cart is empty.</p>';
    }

    // Always enqueue on shortcode render (Elementor-safe)
    wp_enqueue_style('kgi-quote-css', plugins_url('assets/quote.css', __FILE__), [], '1.1.8');
    wp_enqueue_script('kgi-quote-js', plugins_url('assets/quote.js', __FILE__), ['jquery'], '1.1.8', true);

    wp_localize_script('kgi-quote-js', 'KGI_QUOTE', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce'    => wp_create_nonce(self::NONCE_ACTION),
    ]);

    $data = $this->session_get();

    ob_start();
    ?>
    <div class="kgi-quote" data-kgi-quote>
      <div class="kgi-steps">
        <div class="kgi-step is-active" data-step="1">1 Items</div>
        <div class="kgi-step" data-step="2">2 Transport</div>
        <div class="kgi-step" data-step="3">3 Details</div>
        <div class="kgi-step" data-step="4">4 Quote</div>
      </div>

      <div class="kgi-actions-top">
        <button type="button" class="kgi-btn" data-kgi-next-top>Next</button>
      </div>

      <div class="kgi-panels">

        <div class="kgi-panel is-active" data-panel="1">
          <?php echo $this->render_items(); ?>
          <p class="kgi-hint">Hire prices cover you for up to 5 days of hire</p>
          <button type="button" class="kgi-btn" data-kgi-next>Next step</button>
        </div>

        <div class="kgi-panel" data-panel="2">
          <?php echo $this->render_transport_and_event($data); ?>
          <button type="button" class="kgi-btn" data-kgi-save-transport>Next step</button>
        </div>

        <div class="kgi-panel" data-panel="3">
          <?php echo $this->render_details($data); ?>
          <div class="kgi-error" data-kgi-error style="display:none;"></div>
          <button type="button" class="kgi-btn" data-kgi-save-details>Complete quote</button>
        </div>

        <div class="kgi-panel" data-panel="4">
          <h2>QUOTE SUBMITTED SUCCESSFULLY</h2>
          <p>Thank you for placing your quote.</p>
          <p>This order is important to us, so we are manually checking over your items and delivery options.</p>
          <p>A member of the team will send you a quote shortly. We aim to do this within 24 hours.</p>
          <div data-kgi-submitted-meta></div>
          <div class="kgi-disclaimer">
            <?php echo wp_kses_post($this->get_disclaimer_text()); ?>
          </div>
        </div>

      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  private function render_items() {
    $cart = WC()->cart;
    ob_start();
    ?>
    <div class="kgi-items">
      <?php foreach ($cart->get_cart() as $cart_item_key => $item): ?>
        <?php
          $product = $item['data'];
          if (!$product) continue;
          $unit = wc_price($product->get_price());
          $line_total = wc_price($item['line_total']);
        ?>
        <div class="kgi-item" data-cart-key="<?php echo esc_attr($cart_item_key); ?>">
          <div class="kgi-item-img"><?php echo $product->get_image('woocommerce_thumbnail'); ?></div>
          <div class="kgi-item-meta">
            <div class="kgi-item-name"><?php echo esc_html($product->get_name()); ?></div>
            <div class="kgi-item-unit"><?php echo $unit; ?></div>
            <div class="kgi-item-qty">
              <button type="button" class="kgi-qty-btn" data-kgi-qty="dec">-</button>
              <input class="kgi-qty-input" type="number" min="0" value="<?php echo esc_attr((int)$item['quantity']); ?>">
              <button type="button" class="kgi-qty-btn" data-kgi-qty="inc">+</button>
              <button type="button" class="kgi-remove-btn" data-kgi-remove>Remove</button>
            </div>
            <div class="kgi-item-total"><?php echo $line_total; ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="kgi-subtotal">
      <strong>Subtotal</strong>
      <span><?php echo wc_price($cart->get_subtotal()); ?></span>
    </div>

    <p class="kgi-note">Minimum order values may apply. Price excludes VAT.</p>
    <?php
    return ob_get_clean();
  }

  private function render_transport_and_event($data) {
    $t = $data['transport'] ?? [];
    $e = $data['event'] ?? [];

    $delivery_slots = [
      'all_day_0800_1800' => 'All Day 08:00 – 18:00 (Best Price)',
      'am_0800_1300'      => 'AM Slot 08:00 – 13:00',
      'pm_1300_1800'      => 'PM Slot 13:00 – 18:00',
      'premium_specific'  => 'Out of Hours/Specific Time Slot (Premium)',
      'self_collect_free' => 'Free Self Collection 10:00 – 16:00 (Monday to Friday)',
    ];

    $collection_slots = [
      'all_day_0800_1800' => 'All Day 08:00 – 18:00 (Best Price)',
      'am_0800_1300'      => 'AM Slot 08:00 – 13:00',
      'pm_1300_1800'      => 'PM Slot 13:00 – 18:00',
      'premium_specific'  => 'Out of Hours/Specific Time Slot (Premium)',
      'self_return_free'  => 'Free Self Return 10:00 – 16:00 (Monday to Friday)',
    ];

    $delivery_date     = $t['delivery_date'] ?? '';
    $delivery_slot     = $t['delivery_slot'] ?? 'all_day_0800_1800';
    $delivery_postcode = $t['delivery_postcode'] ?? '';
    $delivery_address  = $t['delivery_address'] ?? '';

    $collection_date   = $t['collection_date'] ?? '';
    $collection_slot   = $t['collection_slot'] ?? 'all_day_0800_1800';

    $event_start       = $e['start_datetime'] ?? '';
    $event_finish      = $e['finish_datetime'] ?? '';

    ob_start();
    ?>
    <h3>Delivery</h3>

    <div class="kgi-row">
      <label>Date</label>
      <input type="date" data-kgi-field="transport.delivery_date" value="<?php echo esc_attr($delivery_date); ?>">
    </div>

    <div class="kgi-row">
      <label>Timing</label>
      <select data-kgi-field="transport.delivery_slot">
        <?php foreach ($delivery_slots as $val => $label): ?>
          <option value="<?php echo esc_attr($val); ?>" <?php selected($delivery_slot, $val); ?>>
            <?php echo esc_html($label); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="kgi-row">
      <label>Postcode *</label>
      <input type="text" data-kgi-field="transport.delivery_postcode" value="<?php echo esc_attr($delivery_postcode); ?>">
      <button type="button" class="kgi-btn kgi-btn-small" data-kgi-find="delivery">Find</button>
    </div>

    <div class="kgi-address-results" data-kgi-address-results="delivery" style="display:none;">
      <label>Select address</label>
      <select data-kgi-address-select="delivery"></select>
    </div>

    <div class="kgi-row">
      <label>Delivery address *</label>
      <textarea data-kgi-field="transport.delivery_address"><?php echo esc_textarea($delivery_address); ?></textarea>
      <p class="kgi-hint">Tip: Start with postcode, then select the address from the list.</p>
    </div>

    <h3>Collection / Return</h3>

    <div class="kgi-row">
      <label>Date</label>
      <input type="date" data-kgi-field="transport.collection_date" value="<?php echo esc_attr($collection_date); ?>">
    </div>

    <div class="kgi-row">
      <label>Timing</label>
      <select data-kgi-field="transport.collection_slot">
        <?php foreach ($collection_slots as $val => $label): ?>
          <option value="<?php echo esc_attr($val); ?>" <?php selected($collection_slot, $val); ?>>
            <?php echo esc_html($label); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="kgi-hint">Free self return/collection applies Monday to Friday only.</p>
    </div>

    <h3>Event</h3>

    <div class="kgi-row">
      <label>Event Start Date & Time *</label>
      <input type="datetime-local" data-kgi-field="event.start_datetime" value="<?php echo esc_attr($event_start); ?>">
    </div>

    <div class="kgi-row">
      <label>Event Finish Date & Time *</label>
      <input type="datetime-local" data-kgi-field="event.finish_datetime" value="<?php echo esc_attr($event_finish); ?>">
    </div>
    <?php
    return ob_get_clean();
  }

  private function render_details($data) {
    $d = $data['details'] ?? [];

    $first_name = $d['first_name'] ?? '';
    $last_name  = $d['last_name'] ?? '';
    $email      = $d['email'] ?? '';
    $phone      = $d['phone'] ?? '';
    $event_name = $d['event_name'] ?? '';
    $company    = $d['company'] ?? '';

    $billing_different = !empty($d['billing_different']) ? '1' : '';
    $billing_postcode  = $d['billing_postcode'] ?? '';
    $billing_address   = $d['billing_address'] ?? '';

    $comments          = $d['comments'] ?? '';

    ob_start();
    ?>
    <h2>Details</h2>

    <div class="kgi-row">
      <label>First Name *</label>
      <input type="text" data-kgi-field="details.first_name" value="<?php echo esc_attr($first_name); ?>">
    </div>

    <div class="kgi-row">
      <label>Last Name *</label>
      <input type="text" data-kgi-field="details.last_name" value="<?php echo esc_attr($last_name); ?>">
    </div>

    <div class="kgi-row">
      <label>Email *</label>
      <input type="email" data-kgi-field="details.email" value="<?php echo esc_attr($email); ?>">
    </div>

    <div class="kgi-row">
      <label>Phone *</label>
      <input type="text" data-kgi-field="details.phone" value="<?php echo esc_attr($phone); ?>">
    </div>

    <div class="kgi-row">
      <label>Unique Event Name *</label>
      <input type="text" data-kgi-field="details.event_name" value="<?php echo esc_attr($event_name); ?>">
    </div>

    <div class="kgi-row">
      <label>Company</label>
      <input type="text" data-kgi-field="details.company" value="<?php echo esc_attr($company); ?>">
    </div>

    <div class="kgi-row kgi-row-inline">
      <label>
        <input type="checkbox" data-kgi-field="details.billing_different" value="1" <?php checked($billing_different, '1'); ?>>
        Is the billing address different from the delivery address?
      </label>
    </div>

    <div data-kgi-conditional="details.billing_different" data-show-when="1" style="display:none;">
      <div class="kgi-row">
        <label>Billing postcode</label>
        <input type="text" data-kgi-field="details.billing_postcode" value="<?php echo esc_attr($billing_postcode); ?>">
        <button type="button" class="kgi-btn kgi-btn-small" data-kgi-find="billing">Find</button>
      </div>

      <div class="kgi-address-results" data-kgi-address-results="billing" style="display:none;">
        <label>Select billing address</label>
        <select data-kgi-address-select="billing"></select>
      </div>

      <div class="kgi-row">
        <label>Billing address</label>
        <textarea data-kgi-field="details.billing_address"><?php echo esc_textarea($billing_address); ?></textarea>
      </div>
    </div>

    <div class="kgi-row">
      <label>Is there anything else you want us to know?</label>
      <textarea data-kgi-field="details.comments"><?php echo esc_textarea($comments); ?></textarea>
    </div>
    <?php
    return ob_get_clean();
  }

  // ----------------------------
  // Cart AJAX
  // ----------------------------
  public function ajax_cart_update() {
    $this->assert_ajax_nonce();
    $this->ensure_wc_cart();

    $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
    $qty = isset($_POST['qty']) ? max(0, (int)$_POST['qty']) : 0;

    if (!$cart_key) wp_send_json_error(['message' => 'Missing cart key'], 400);

    if ($qty <= 0) {
      WC()->cart->remove_cart_item($cart_key);
    } else {
      WC()->cart->set_quantity($cart_key, $qty, true);
    }

    WC()->cart->calculate_totals();

    wp_send_json_success([
      'subtotal_html' => wc_price(WC()->cart->get_subtotal()),
    ]);
  }

  public function ajax_cart_remove() {
    $this->assert_ajax_nonce();
    $this->ensure_wc_cart();

    $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
    if (!$cart_key) wp_send_json_error(['message' => 'Missing cart key'], 400);

    WC()->cart->remove_cart_item($cart_key);
    WC()->cart->calculate_totals();

    wp_send_json_success([
      'subtotal_html' => wc_price(WC()->cart->get_subtotal()),
    ]);
  }

  // ----------------------------
  // AJAX: GetAddress lookup
  // ----------------------------
  public function ajax_lookup_postcode() {
    $this->assert_ajax_nonce();

    $api_key = trim((string)get_option(self::OPTION_GETADDRESS_KEY, ''));
    if (!$api_key) {
      wp_send_json_error(['message' => 'Address lookup not configured (missing GetAddress.io API key).'], 400);
    }

    $postcode_raw = (string)($_POST['postcode'] ?? '');
    $postcode = $this->clean_postcode($postcode_raw);
    if (!$postcode) {
      wp_send_json_error(['message' => 'Please enter a postcode.'], 422);
    }

    // ✅ expand=true returns structured address objects (line_1, town_or_city, county, etc.)
    $url = 'https://api.getaddress.io/find/' . rawurlencode($postcode) . '?api-key=' . rawurlencode($api_key) . '&expand=true';

    $resp = wp_remote_get($url, [
      'timeout' => 15,
      'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($resp)) {
      wp_send_json_error(['message' => 'Lookup failed: ' . $resp->get_error_message()], 500);
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);

    if ($code !== 200) {
      $maybe = json_decode($body, true);
      $msg = is_array($maybe) && !empty($maybe['Message'])
        ? (string)$maybe['Message']
        : 'Lookup failed. Please check the postcode and try again.';
      wp_send_json_error(['message' => $msg], 400);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
      wp_send_json_error(['message' => 'Lookup returned an invalid response.'], 500);
    }

    $addresses = $json['addresses'] ?? [];
    if (!is_array($addresses) || empty($addresses)) {
      wp_send_json_error(['message' => 'No addresses found for this postcode.'], 404);
    }

    // ✅ Normalise to an array of readable one-line strings (what your JS expects)
    $formatted = [];
    foreach ($addresses as $addr) {

      // expand=true => each item is an associative array
      if (is_array($addr)) {
        $parts = [];
        foreach (['line_1','line_2','line_3','line_4','town_or_city','county'] as $k) {
          if (!empty($addr[$k])) $parts[] = trim((string)$addr[$k]);
        }
        $pretty = implode(', ', array_filter($parts));
        $pretty = rtrim($pretty, ', ');

        $pretty = ($pretty !== '')
          ? ($pretty . ', ' . trim($postcode_raw))
          : trim($postcode_raw);

        $formatted[] = $pretty;
        continue;
      }

      // Non-expanded => CSV-like string
      $addr = (string)$addr;
      $pretty = trim(preg_replace('/\s*,\s*/', ', ', $addr));
      $pretty = rtrim($pretty, ', ') . ', ' . trim($postcode_raw);
      $formatted[] = $pretty;
    }

    wp_send_json_success([
      'postcode'  => trim($postcode_raw),
      'addresses' => $formatted,
    ]);
  }

  // ----------------------------
  // Save step + Submit quote
  // ----------------------------
  public function ajax_save_step() {
    $this->assert_ajax_nonce();

    $payload = json_decode(stripslashes($_POST['payload'] ?? ''), true);
    if (!is_array($payload)) $payload = [];

    $data = $this->session_get();
    $data = array_merge($data, $payload);
    $this->session_set($data);

    wp_send_json_success(['ok' => true, 'data' => $data]);
  }

  public function ajax_submit_quote() {
    $this->assert_ajax_nonce();
    $this->ensure_wc_cart();

    if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
      wp_send_json_error(['message' => 'Your cart is empty.'], 400);
    }

    $payload = json_decode(stripslashes($_POST['payload'] ?? ''), true);
    if (!is_array($payload)) $payload = [];

    $data = $this->session_get();
    $data = array_merge($data, $payload);
    $this->session_set($data);

    // Basic validation
    $details = $data['details'] ?? [];
    foreach (['first_name','last_name','email','phone','event_name'] as $req) {
      if (empty($details[$req])) {
        wp_send_json_error(['message' => 'Please fill in all required fields.'], 422);
      }
    }

    // Create order
    $order = wc_create_order();
    if (is_wp_error($order) || !$order) {
      wp_send_json_error(['message' => 'Could not create order.'], 500);
    }

    foreach (WC()->cart->get_cart() as $cart_item) {
      $order->add_product($cart_item['data'], (int)$cart_item['quantity']);
    }

    // Set billing details
    $order->set_billing_first_name(sanitize_text_field($details['first_name']));
    $order->set_billing_last_name(sanitize_text_field($details['last_name']));
    $order->set_billing_email(sanitize_email($details['email']));
    $order->set_billing_phone(sanitize_text_field($details['phone']));
    $order->set_billing_company(sanitize_text_field($details['company'] ?? ''));

    // Store quote data on order
    $order->update_meta_data(self::ORDER_META_KEY, $data);
    $order->update_meta_data(self::DISCLAIMER_META_KEY, $this->get_disclaimer_text());

    // Totals + status
    $order->calculate_totals();
    $order->update_status('quote-requested', 'Quote requested via front-end quote checkout.', true);
    $order->save();

    // Clear cart & session data
    WC()->cart->empty_cart();
    $this->session_set([]);

    wp_send_json_success([
      'order_id' => $order->get_id(),
      'submitted_html' => '<p>Order reference: <strong>#' . esc_html($order->get_order_number()) . '</strong></p>',
    ]);
  }

  // ----------------------------
  // Custom order status
  // ----------------------------
  public function register_order_status() {
    register_post_status('wc-quote-requested', [
      'label'                     => 'Quote Requested',
      'public'                    => true,
      'exclude_from_search'       => false,
      'show_in_admin_all_list'    => true,
      'show_in_admin_status_list' => true,
      'label_count'               => _n_noop('Quote Requested <span class="count">(%s)</span>', 'Quote Requested <span class="count">(%s)</span>'),
    ]);
  }

  public function add_order_status_to_list($statuses) {
    $out = [];
    foreach ($statuses as $k => $v) {
      $out[$k] = $v;
      if ($k === 'wc-pending') {
        $out['wc-quote-requested'] = 'Quote Requested';
      }
    }
    return $out;
  }

  // ----------------------------
  // Admin metabox + pay link tools
  // ----------------------------
  public function add_admin_metabox($screen = null) {
    add_meta_box(
      'kgi_quote_tools',
      'Quote Tools (KGI)',
      [$this, 'render_admin_metabox'],
      $screen ?: 'shop_order',
      'side',
      'high'
    );
  }

  public function render_admin_metabox($post_or_order) {
    $order = null;

    if ($post_or_order instanceof WC_Order) {
      $order = $post_or_order;
    }
    if (!$order && is_object($post_or_order) && isset($post_or_order->ID)) {
      $order = wc_get_order((int)$post_or_order->ID);
    }
    if (!$order && isset($_GET['id'])) {
      $order = wc_get_order((int)$_GET['id']);
    }
    if (!$order && isset($_GET['post'])) {
      $order = wc_get_order((int)$_GET['post']);
    }

    if (!$order) {
      echo '<p>Could not load order for Quote Tools.</p>';
      return;
    }

    $pay_url = $order->get_checkout_payment_url();

    echo '<p><strong>Status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';
    echo '<hr/>';

    echo '<p><strong>Add fees (optional)</strong></p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="kgi_quote_apply_fees" />';
    echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '" />';
    wp_nonce_field('kgi_quote_apply_fees');
    echo '<p><label>Delivery fee<br/><input type="number" step="0.01" name="delivery_fee" value="" /></label></p>';
    echo '<p><label>Deposit<br/><input type="number" step="0.01" name="deposit" value="" /></label></p>';
    echo '<p><button class="button button-primary">Apply fees</button></p>';
    echo '</form>';

    echo '<hr/>';

    echo '<p><strong>Pay link</strong></p>';
    echo '<p><a href="' . esc_url($pay_url) . '" target="_blank" rel="noopener">Open pay link</a></p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="kgi_quote_send_pay_link" />';
    echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '" />';
    wp_nonce_field('kgi_quote_send_pay_link');
    echo '<p><button class="button">Email pay link to customer</button></p>';
    echo '</form>';
  }

  public function admin_apply_fees() {
    if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
    check_admin_referer('kgi_quote_apply_fees');

    $order_id = (int)($_POST['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    if (!$order) wp_die('Order not found');

    $delivery_fee = (float)($_POST['delivery_fee'] ?? 0);
    $deposit      = (float)($_POST['deposit'] ?? 0);

    if ($delivery_fee > 0) {
      $fee = new WC_Order_Item_Fee();
      $fee->set_name('Delivery');
      $fee->set_amount($delivery_fee);
      $fee->set_total($delivery_fee);
      $order->add_item($fee);
    }

    if ($deposit > 0) {
      $fee = new WC_Order_Item_Fee();
      $fee->set_name('Deposit');
      $fee->set_amount($deposit);
      $fee->set_total($deposit);
      $order->add_item($fee);
    }

    $order->calculate_totals();
    $order->add_order_note('Fees applied via Quote Tools.');
    $order->save();

    wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit'));
    exit;
  }

  public function admin_send_pay_link() {
    if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
    check_admin_referer('kgi_quote_send_pay_link');

    $order_id = (int)($_POST['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    if (!$order) wp_die('Order not found');

    $to = $order->get_billing_email();
    if (!$to || !is_email($to)) wp_die('Customer email missing');

    $pay_url = $order->get_checkout_payment_url();

    $subject = 'Your quote is ready – payment link';
    $body  = "Hi " . $order->get_billing_first_name() . ",\n\n";
    $body .= "Thanks for your quote request — your quote is ready.\n\n";
    $body .= "Pay here to confirm your booking:\n" . $pay_url . "\n\n";
    $body .= "Once payment is made, your booking is confirmed.\n\n";
    $body .= "Thanks,\n" . get_bloginfo('name');

    wp_mail($to, $subject, $body);

    $order->add_order_note('Pay link emailed to customer.');
    $order->save();

    wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit'));
    exit;
  }

  // ----------------------------
  // Admin: render quote details
  // ----------------------------
  public function admin_render_quote_details($order) {
    if (!$order instanceof WC_Order) return;

    $data = $order->get_meta(self::ORDER_META_KEY);
    if (!is_array($data) || empty($data)) return;

    $t = $data['transport'] ?? [];
    $e = $data['event'] ?? [];
    $d = $data['details'] ?? [];

    echo '<h3>Quote Request Details</h3>';
    echo '<p><strong>Event name:</strong> ' . esc_html($d['event_name'] ?? '') . '</p>';
    echo '<p><strong>Event start:</strong> ' . esc_html($this->fmt_datetime($e['start_datetime'] ?? '')) . '</p>';
    echo '<p><strong>Event finish:</strong> ' . esc_html($this->fmt_datetime($e['finish_datetime'] ?? '')) . '</p>';
    echo '<p><strong>Delivery date:</strong> ' . esc_html($this->fmt_date($t['delivery_date'] ?? '')) . '</p>';
    echo '<p><strong>Delivery postcode:</strong> ' . esc_html($t['delivery_postcode'] ?? '') . '</p>';
    echo '<p><strong>Delivery address:</strong><br/>' . nl2br(esc_html($t['delivery_address'] ?? '')) . '</p>';
    echo '<p><strong>Collection/Return date:</strong> ' . esc_html($this->fmt_date($t['collection_date'] ?? '')) . '</p>';

    $billing_diff = !empty($d['billing_different']) ? 'Yes' : 'No';
    echo '<p><strong>Billing different?</strong> ' . esc_html($billing_diff) . '</p>';

    if (!empty($d['billing_different'])) {
      echo '<p><strong>Billing postcode:</strong> ' . esc_html($d['billing_postcode'] ?? '') . '</p>';
      echo '<p><strong>Billing address:</strong><br/>' . nl2br(esc_html($d['billing_address'] ?? '')) . '</p>';
    }

    if (!empty($d['comments'])) {
      echo '<p><strong>Comments:</strong><br/>' . nl2br(esc_html($d['comments'])) . '</p>';
    }
  }

  private function fmt_date($date) {
    $date = trim((string)$date);
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date_i18n(get_option('date_format'), $ts) : $date;
  }

  private function fmt_datetime($dt) {
    $dt = trim((string)$dt);
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts) : $dt;
  }

  private function get_disclaimer_text() {
    return '<p><strong>Disclaimer:</strong> This is a quote request only. Your booking is not confirmed until payment is received.</p>';
  }
}

new KGI_Quote_Checkout();
