<?php
/**
 * Plugin Name: KGI Quote Checkout (WooCommerce)
 * Description: Custom multi-step quote checkout: Items -> Transport -> Details -> Quote Submitted. Creates a Woo order in "Quote Requested" status and lets admin send a pay link later.
 * Version: 1.2.0
 */

if (!defined('ABSPATH')) exit;

class KGI_Quote_Checkout {

  const SESSION_KEY      = 'kgi_quote_data';
  const NONCE_ACTION     = 'kgi_quote_nonce_action';
  const ORDER_META_KEY   = '_kgi_quote_data';

  const SETTINGS_SLUG    = 'kgi_quote_checkout_settings';
  const OPTION_GETADDRESS_KEY = 'kgi_getaddress_api_key';

  public function __construct() {

    // Shortcode
    add_shortcode('kgi_quote_checkout', [$this, 'shortcode_quote_checkout']);

    // Ensure WC session/cart
    add_action('init', [$this, 'maybe_init_wc_session'], 5);

    // AJAX
    add_action('wp_ajax_kgi_quote_save_step',        [$this, 'ajax_save_step']);
    add_action('wp_ajax_nopriv_kgi_quote_save_step', [$this, 'ajax_save_step']);

    add_action('wp_ajax_kgi_quote_submit',        [$this, 'ajax_submit_quote']);
    add_action('wp_ajax_nopriv_kgi_quote_submit', [$this, 'ajax_submit_quote']);

    add_action('wp_ajax_kgi_quote_cart_update',        [$this, 'ajax_cart_update']);
    add_action('wp_ajax_nopriv_kgi_quote_cart_update', [$this, 'ajax_cart_update']);

    add_action('wp_ajax_kgi_quote_cart_remove',        [$this, 'ajax_cart_remove']);
    add_action('wp_ajax_nopriv_kgi_quote_cart_remove', [$this, 'ajax_cart_remove']);

    // Single postcode lookup endpoint (server-side key)
    add_action('wp_ajax_kgi_quote_lookup_postcode',        [$this, 'ajax_lookup_postcode']);
    add_action('wp_ajax_nopriv_kgi_quote_lookup_postcode', [$this, 'ajax_lookup_postcode']);

    // Custom order status
    add_action('init', [$this, 'register_order_status']);
    add_filter('wc_order_statuses', [$this, 'add_order_status_to_list']);

    // Admin display
    add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'admin_render_quote_details'], 10, 1);

    // Meta box tools (legacy + HPOS)
    add_action('add_meta_boxes', [$this, 'add_admin_metabox_legacy']);
    add_action('add_meta_boxes_woocommerce_page_wc-orders', [$this, 'add_admin_metabox_hpos'], 10, 1);

    add_action('admin_post_kgi_quote_send_pay_link', [$this, 'admin_send_pay_link']);
    add_action('admin_post_kgi_quote_apply_fees',    [$this, 'admin_apply_fees']);

    // Settings page (WooCommerce submenu)
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'admin_register_settings']);
  }

  // ----------------------------
  // WC bootstrap
  // ----------------------------
  public function maybe_init_wc_session() {
    if (!function_exists('WC')) return;
    if (WC()->session) {
      WC()->session->get(self::SESSION_KEY);
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

  private function clean_postcode($postcode) {
    $postcode = strtoupper(trim((string)$postcode));
    $postcode = preg_replace('/\s+/', '', $postcode);
    $postcode = preg_replace('/[^A-Z0-9]/', '', $postcode);
    return $postcode;
  }

  // ----------------------------
  // Settings page
  // ----------------------------
  public function admin_menu() {
    add_submenu_page(
      'woocommerce',
      'Quote Checkout',
      'Quote Checkout',
      'manage_woocommerce',
      self::SETTINGS_SLUG,
      [$this, 'admin_settings_page']
    );
  }

  public function admin_register_settings() {
    register_setting(
      self::SETTINGS_SLUG,
      self::OPTION_GETADDRESS_KEY,
      [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '',
      ]
    );

    add_settings_section(
      'kgi_qc_main',
      'GetAddress.io',
      function () {
        echo '<p>Enter your GetAddress.io API key to enable postcode address lookup. This key is used server-side and is not exposed in the browser.</p>';
      },
      self::SETTINGS_SLUG
    );

    add_settings_field(
      self::OPTION_GETADDRESS_KEY,
      'GetAddress.io API Key',
      function () {
        $val = get_option(self::OPTION_GETADDRESS_KEY, '');
        echo '<input type="text" style="width:420px;max-width:100%;" name="' . esc_attr(self::OPTION_GETADDRESS_KEY) . '" value="' . esc_attr($val) . '" />';
      },
      self::SETTINGS_SLUG,
      'kgi_qc_main'
    );
  }

  public function admin_settings_page() {
    ?>
    <div class="wrap">
      <h1>Quote Checkout</h1>
      <form method="post" action="options.php">
        <?php
          settings_fields(self::SETTINGS_SLUG);
          do_settings_sections(self::SETTINGS_SLUG);
          submit_button();
        ?>
      </form>
    </div>
    <?php
  }

  // ----------------------------
  // Disclaimer + Email content
  // ----------------------------
  private function get_disclaimer_text() {
    return "No stock has been booked at this stage. Only once a quote is accepted and payment made is the booking confirmed. All hires are quoted on a dry hire basis and no set up is included unless specifically listed in the quote. We have 4 delivery and collection timings available, please make sure you have selected the most appropriate one, advising us of any alterations, restrictions or handling that is required. At the time of quoting all items listed are available to hire. We work on a first come first served basis and in order to avoid disappointment, we strongly advise that you confirm your requirements and pay a deposit to secure your hire as soon as possible. Minimum order values apply. Price excludes VAT. Please check our website FAQ’s for more information.";
  }

  private function customer_submit_subject() {
    return "Your Quote Request Has Been Received";
  }

  private function customer_submit_message_intro() {
    return "Thank you for getting in touch. We have received your quote request and one of our team will be in touch with your full quote within 24 hours. If you require a quicker response, please call the office on 0330 133 4840 and one of the team will be happy to help.\n\nPlease see details of your request below:\n";
  }

  // ----------------------------
  // Shortcode UI
  // ----------------------------
  public function shortcode_quote_checkout() {
    if (!function_exists('WC') || !WC()->cart) return '<div class="kgi-quote-error">WooCommerce cart not available.</div>';
    if (WC()->cart->is_empty()) return '<div class="kgi-quote-empty">Your quote cart is empty.</div>';

    wp_enqueue_style('kgi-quote-css', plugins_url('assets/quote.css', __FILE__), [], '1.2.0');
    wp_enqueue_script('kgi-quote-js', plugins_url('assets/quote.js', __FILE__), ['jquery'], '1.2.0', true);

    wp_localize_script('kgi-quote-js', 'KGI_QUOTE', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce'    => wp_create_nonce(self::NONCE_ACTION),
    ]);

    $data = $this->session_get();

    ob_start();
    ?>
    <div class="kgi-quote-wrap" data-kgi-quote>
      <div class="kgi-quote-top">
        <div class="kgi-quote-stepper" role="navigation" aria-label="Quote steps">
          <div class="kgi-step is-active" data-step="1"><span class="kgi-badge">1</span><span class="kgi-label">Items</span></div>
          <div class="kgi-line"></div>
          <div class="kgi-step" data-step="2"><span class="kgi-badge">2</span><span class="kgi-label">Transport</span></div>
          <div class="kgi-line"></div>
          <div class="kgi-step" data-step="3"><span class="kgi-badge">3</span><span class="kgi-label">Details</span></div>
          <div class="kgi-line"></div>
          <div class="kgi-step" data-step="4"><span class="kgi-badge">4</span><span class="kgi-label">Quote</span></div>
        </div>

        <div class="kgi-quote-top-actions">
          <button class="kgi-pill" type="button" data-kgi-next-top>Next</button>
        </div>
      </div>

      <div class="kgi-quote-stage">
        <section class="kgi-panel is-active" data-panel="1">
          <?php echo $this->render_items(); ?>
          <div class="kgi-note">Hire prices cover you for up to 5 days of hire</div>
          <button class="kgi-cta" type="button" data-kgi-next>Next step</button>
        </section>

        <section class="kgi-panel" data-panel="2">
          <?php echo $this->render_transport_event($data); ?>
          <button class="kgi-cta" type="button" data-kgi-save-transport>Next step</button>
        </section>

        <section class="kgi-panel" data-panel="3">
          <?php echo $this->render_details($data); ?>
          <button class="kgi-cta" type="button" data-kgi-save-details>Complete quote</button>
        </section>

        <section class="kgi-panel" data-panel="4">
          <div class="kgi-submitted">
            <h2>QUOTE SUBMITTED SUCCESSFULLY</h2>
            <p>Thank you for placing your quote.</p>
            <p>This order is important to us, so we are manually checking over your items and delivery options.</p>
            <p>A member of the team will send you a quote shortly. We aim to do this within 24 hours.</p>
            <div class="kgi-submitted-meta" data-kgi-submitted-meta></div>
          </div>
        </section>
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

          $qty  = (int)$item['quantity'];
          $name = $product->get_name();
          $img  = $product->get_image('woocommerce_thumbnail');
          $unit = wc_price($product->get_price());
          $line_total = wc_price($item['line_total']);
        ?>
        <div class="kgi-item" data-cart-key="<?php echo esc_attr($cart_item_key); ?>">
          <div class="kgi-item-img"><?php echo $img; ?></div>

          <div class="kgi-item-main">
            <div class="kgi-item-title"><?php echo esc_html($name); ?></div>
            <div class="kgi-item-unit"><?php echo $unit; ?></div>

            <div class="kgi-item-controls">
              <div class="kgi-qty">
                <button type="button" class="kgi-qty-btn" data-kgi-qty="dec">-</button>
                <input type="number" min="0" step="1" value="<?php echo esc_attr($qty); ?>" class="kgi-qty-input" />
                <button type="button" class="kgi-qty-btn" data-kgi-qty="inc">+</button>
              </div>
              <button type="button" class="kgi-link" data-kgi-remove>Remove</button>
            </div>
          </div>

          <div class="kgi-item-total"><?php echo $line_total; ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="kgi-items-footer">
      <div class="kgi-items-subtotal">
        <span>Subtotal</span>
        <strong><?php echo wc_price(WC()->cart->get_subtotal()); ?></strong>
      </div>
      <div class="kgi-items-subnote">Minimum order values may apply. Price excludes VAT.</div>
    </div>
    <?php
    return ob_get_clean();
  }

  private function render_transport_event($data) {
    $t = isset($data['transport']) && is_array($data['transport']) ? $data['transport'] : [];
    $e = isset($data['event']) && is_array($data['event']) ? $data['event'] : [];

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

    $event_start  = $e['start_datetime'] ?? '';
    $event_finish = $e['finish_datetime'] ?? '';

    ob_start();
    ?>
    <div class="kgi-transport-grid">
      <div class="kgi-card">
        <h3>Delivery</h3>

        <label>Date</label>
        <input type="date" data-kgi-field="transport.delivery_date" value="<?php echo esc_attr($delivery_date); ?>">

        <label>Timing</label>
        <select data-kgi-field="transport.delivery_slot">
          <?php foreach ($delivery_slots as $k => $label): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($delivery_slot, $k); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>

        <label>Postcode *</label>
        <div class="kgi-row">
          <input type="text" placeholder="Enter postcode" data-kgi-field="transport.delivery_postcode" value="<?php echo esc_attr($delivery_postcode); ?>">
          <button type="button" class="kgi-pill kgi-pill-ghost" data-kgi-find="delivery">Find</button>
        </div>

        <div class="kgi-address-results" data-kgi-address-results="delivery" style="display:none;">
          <label>Select address</label>
          <select data-kgi-address-select="delivery"></select>
        </div>

        <label>Delivery address *</label>
        <textarea rows="3" data-kgi-field="transport.delivery_address" placeholder="Use Find to search and select an address"><?php echo esc_textarea($delivery_address); ?></textarea>

        <div class="kgi-footnote" style="margin:10px 0 0; text-align:left;">
          Tip: Start with postcode, then select the address from the list.
        </div>
      </div>

      <div class="kgi-card">
        <h3>Collection / Return</h3>

        <label>Date</label>
        <input type="date" data-kgi-field="transport.collection_date" value="<?php echo esc_attr($collection_date); ?>">

        <label>Timing</label>
        <select data-kgi-field="transport.collection_slot">
          <?php foreach ($collection_slots as $k => $label): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($collection_slot, $k); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>

        <div class="kgi-footnote" style="margin:10px 0 0; text-align:left;">
          Free self return/collection applies Monday to Friday only.
        </div>
      </div>
    </div>

    <div class="kgi-card" style="margin-top:20px;">
      <h3>Event</h3>

      <div class="kgi-row" style="gap:18px; align-items:flex-start;">
        <div style="flex:1;">
          <label>Event Start Date &amp; Time *</label>
          <input type="datetime-local" data-kgi-field="event.start_datetime" value="<?php echo esc_attr($event_start); ?>">
        </div>
        <div style="flex:1;">
          <label>Event Finish Date &amp; Time *</label>
          <input type="datetime-local" data-kgi-field="event.finish_datetime" value="<?php echo esc_attr($event_finish); ?>">
        </div>
      </div>
    </div>

    <div class="kgi-disclaimer">
      <?php echo esc_html($this->get_disclaimer_text()); ?>
    </div>
    <?php
    return ob_get_clean();
  }

  private function render_details($data) {
    $d = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];

    $first   = $d['first_name'] ?? '';
    $last    = $d['last_name'] ?? '';
    $email   = $d['email'] ?? '';
    $phone   = $d['phone'] ?? '';
    $eventnm = $d['event_name'] ?? '';
    $company = $d['company'] ?? '';
    $billing_diff = !empty($d['billing_different']) ? '1' : '';
    $billing_pc   = $d['billing_postcode'] ?? '';
    $billing_addr = $d['billing_address'] ?? '';
    $comments     = $d['comments'] ?? '';

    ob_start();
    ?>
    <div class="kgi-details-grid">
      <div class="kgi-card">
        <h2>Details</h2>

        <div class="kgi-field">
          <label>First Name *</label>
          <input type="text" data-kgi-field="details.first_name" value="<?php echo esc_attr($first); ?>" placeholder="First Name">
        </div>

        <div class="kgi-field">
          <label>Last Name *</label>
          <input type="text" data-kgi-field="details.last_name" value="<?php echo esc_attr($last); ?>" placeholder="Last Name">
        </div>

        <div class="kgi-field">
          <label>Email *</label>
          <input type="email" data-kgi-field="details.email" value="<?php echo esc_attr($email); ?>" placeholder="Email">
        </div>

        <div class="kgi-field">
          <label>Phone *</label>
          <input type="text" data-kgi-field="details.phone" value="<?php echo esc_attr($phone); ?>" placeholder="Phone">
        </div>

        <div class="kgi-field">
          <label>Unique Event Name (“for your documents”) *</label>
          <input type="text" data-kgi-field="details.event_name" value="<?php echo esc_attr($eventnm); ?>" placeholder="for your documents">
        </div>

        <div class="kgi-field">
          <label>Company</label>
          <input type="text" data-kgi-field="details.company" value="<?php echo esc_attr($company); ?>" placeholder="Company (optional)">
        </div>

        <label class="kgi-check">
          <input type="checkbox" data-kgi-field="details.billing_different" value="1" <?php checked($billing_diff, '1'); ?>>
          <span>Is the billing address different from the delivery address?</span>
        </label>

        <div class="kgi-conditional" data-kgi-conditional="details.billing_different" data-show-when="1" style="margin-top:12px;">
          <label>Billing postcode *</label>
          <div class="kgi-row">
            <input type="text" placeholder="Enter postcode" data-kgi-field="details.billing_postcode" value="<?php echo esc_attr($billing_pc); ?>">
            <button type="button" class="kgi-pill kgi-pill-ghost" data-kgi-find="billing">Find</button>
          </div>

          <div class="kgi-address-results" data-kgi-address-results="billing" style="display:none;">
            <label>Select billing address</label>
            <select data-kgi-address-select="billing"></select>
          </div>

          <label>Billing address *</label>
          <textarea rows="3" data-kgi-field="details.billing_address" placeholder="Use Find to search and select an address"><?php echo esc_textarea($billing_addr); ?></textarea>
        </div>

        <div class="kgi-field" style="margin-top:14px;">
          <label>Is there anything else you want us to know?</label>
          <textarea rows="4" data-kgi-field="details.comments" placeholder="Is there anything else you want us to know?"><?php echo esc_textarea($comments); ?></textarea>
        </div>

        <div class="kgi-error" data-kgi-error style="display:none;"></div>
      </div>

      <div class="kgi-card kgi-card-image" aria-hidden="true"></div>
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
    $qty      = isset($_POST['qty']) ? max(0, (int)$_POST['qty']) : 0;

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
  // GetAddress.io lookup
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

    $url = 'https://api.getaddress.io/find/' . rawurlencode($postcode) . '?api-key=' . rawurlencode($api_key);

    $resp = wp_remote_get($url, [
      'timeout' => 12,
      'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($resp)) {
      wp_send_json_error(['message' => 'Lookup failed: ' . $resp->get_error_message()], 500);
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);

    if ($code !== 200) {
      // Attempt to read error from GetAddress
      $maybe = json_decode($body, true);
      $msg = is_array($maybe) && !empty($maybe['Message']) ? (string)$maybe['Message'] : 'Lookup failed. Please check the postcode and try again.';
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

    $formatted = [];
    foreach ($addresses as $addr) {
      $addr = (string)$addr;
      $pretty = trim(preg_replace('/\s*,\s*/', ', ', $addr));
      // GetAddress gives address lines without postcode; append original (spaced) postcode
      $pretty = rtrim($pretty, ', ') . ', ' . trim($postcode_raw);
      $formatted[] = $pretty;
    }

    wp_send_json_success([
      'postcode'   => trim($postcode_raw),
      'addresses'  => $formatted,
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

    $transport = is_array($data['transport'] ?? null) ? $data['transport'] : [];
    $event     = is_array($data['event'] ?? null) ? $data['event'] : [];
    $details   = is_array($data['details'] ?? null) ? $data['details'] : [];

    $errors = [];

    if (empty($transport['delivery_date'])) $errors[] = 'Please select a delivery date.';
    if (empty($transport['collection_date'])) $errors[] = 'Please select a collection/return date.';
    if (empty(trim($transport['delivery_postcode'] ?? ''))) $errors[] = 'Please enter a delivery postcode.';
    if (empty(trim($transport['delivery_address'] ?? ''))) $errors[] = 'Please select a delivery address (use Find).';

    if (empty(trim($event['start_datetime'] ?? ''))) $errors[] = 'Please select an event start date/time.';
    if (empty(trim($event['finish_datetime'] ?? ''))) $errors[] = 'Please select an event finish date/time.';

    if (empty(trim($details['first_name'] ?? ''))) $errors[] = 'First name is required.';
    if (empty(trim($details['last_name'] ?? ''))) $errors[] = 'Last name is required.';
    if (empty(trim($details['email'] ?? '')) || !is_email($details['email'])) $errors[] = 'A valid email is required.';
    if (empty(trim($details['phone'] ?? ''))) $errors[] = 'Phone is required.';
    if (empty(trim($details['event_name'] ?? ''))) $errors[] = 'Unique event name is required.';

    $billing_diff = !empty($details['billing_different']);
    if ($billing_diff) {
      if (empty(trim($details['billing_postcode'] ?? ''))) $errors[] = 'Billing postcode is required (billing differs).';
      if (empty(trim($details['billing_address'] ?? ''))) $errors[] = 'Billing address is required (billing differs).';
    }

    if ($errors) {
      wp_send_json_error(['message' => implode(' ', $errors)], 422);
    }

    $order = wc_create_order();

    foreach (WC()->cart->get_cart() as $cart_item_key => $item) {
      if (empty($item['data'])) continue;
      $order->add_product($item['data'], (int)$item['quantity'], [
        'subtotal' => $item['line_subtotal'],
        'total'    => $item['line_total'],
      ]);
    }

    // Billing basics (for later pay link)
    $order->set_billing_first_name(sanitize_text_field($details['first_name']));
    $order->set_billing_last_name(sanitize_text_field($details['last_name']));
    $order->set_billing_email(sanitize_email($details['email']));
    $order->set_billing_phone(sanitize_text_field($details['phone']));
    $order->set_billing_company(sanitize_text_field($details['company'] ?? ''));

    if ($billing_diff) {
      $order->set_billing_postcode(sanitize_text_field($details['billing_postcode'] ?? ''));
      // Keep full multiline in address_1 to avoid splitting logic
      $order->set_billing_address_1(sanitize_textarea_field($details['billing_address'] ?? ''));
    }

    // Store everything for the admin quote pack
    $order->update_meta_data(self::ORDER_META_KEY, $data);
    $order->add_order_note('Quote request submitted via custom quote checkout.');

    $order->set_status('wc-quote-requested');
    $order->calculate_totals();
    $order->save();

    $this->email_admin_new_quote($order->get_id());
    $this->email_customer_quote_received($order->get_id());

    WC()->cart->empty_cart();
    $this->session_set([]);

    wp_send_json_success([
      'ok' => true,
      'order_id' => $order->get_id(),
      'submitted_html' => $this->submitted_meta_html($order),
    ]);
  }

  private function submitted_meta_html($order) {
    $html  = '<div class="kgi-submitted-order">';
    $html .= '<p><strong>Reference:</strong> ' . esc_html($order->get_order_number()) . '</p>';
    $html .= '<p><strong>We’ll email you within 24 hours.</strong></p>';
    $html .= '</div>';
    return $html;
  }

  // ----------------------------
  // Order status
  // ----------------------------
  public function register_order_status() {
    register_post_status('wc-quote-requested', [
      'label'                     => 'Quote Requested',
      'public'                    => true,
      'exclude_from_search'       => false,
      'show_in_admin_status_list' => true,
      'show_in_admin_all_list'    => true,
      'label_count'               => _n_noop('Quote Requested <span class="count">(%s)</span>', 'Quote Requested <span class="count">(%s)</span>'),
    ]);
  }

  public function add_order_status_to_list($statuses) {
    $new = [];
    foreach ($statuses as $k => $v) {
      $new[$k] = $v;
      if ($k === 'wc-pending') {
        $new['wc-quote-requested'] = 'Quote Requested';
      }
    }
    if (!isset($new['wc-quote-requested'])) $new['wc-quote-requested'] = 'Quote Requested';
    return $new;
  }

  // ----------------------------
  // Admin: quote details (formatted)
  // ----------------------------
  private function fmt_date($ymd) {
    $ymd = trim((string)$ymd);
    if (!$ymd) return '';
    $ts = strtotime($ymd);
    if (!$ts) return $ymd;
    return date_i18n('jS F Y', $ts);
  }

  private function fmt_datetime($dt) {
    $dt = trim((string)$dt);
    if (!$dt) return '';
    $ts = strtotime(str_replace('T', ' ', $dt));
    if (!$ts) return $dt;
    return date_i18n('jS F Y, H.i', $ts);
  }

  public function admin_render_quote_details($order) {
    if (!$order instanceof WC_Order) return;

    $data = $order->get_meta(self::ORDER_META_KEY);
    if (!is_array($data)) return;

    $t = is_array($data['transport'] ?? null) ? $data['transport'] : [];
    $e = is_array($data['event'] ?? null) ? $data['event'] : [];
    $d = is_array($data['details'] ?? null) ? $data['details'] : [];

    $delivery_slot_map = [
      'all_day_0800_1800' => 'All Day 08:00 – 18:00 (Best Price)',
      'am_0800_1300'      => 'AM Slot 08:00 – 13:00',
      'pm_1300_1800'      => 'PM Slot 13:00 – 18:00',
      'premium_specific'  => 'Out of Hours/Specific Time Slot (Premium)',
      'self_collect_free' => 'Free Self Collection 10:00 – 16:00 (Monday to Friday)',
    ];
    $collection_slot_map = [
      'all_day_0800_1800' => 'All Day 08:00 – 18:00 (Best Price)',
      'am_0800_1300'      => 'AM Slot 08:00 – 13:00',
      'pm_1300_1800'      => 'PM Slot 13:00 – 18:00',
      'premium_specific'  => 'Out of Hours/Specific Time Slot (Premium)',
      'self_return_free'  => 'Free Self Return 10:00 – 16:00 (Monday to Friday)',
    ];

    $delivery_slot   = $delivery_slot_map[$t['delivery_slot'] ?? ''] ?? ($t['delivery_slot'] ?? '');
    $collection_slot = $collection_slot_map[$t['collection_slot'] ?? ''] ?? ($t['collection_slot'] ?? '');

    $billing_diff = !empty($d['billing_different']) ? 'Yes' : 'No';

    echo '<div style="margin:12px 0; padding:12px; background:#fff; border:1px solid #e5e5e5; border-radius:8px;">';
    echo '<h3 style="margin:0 0 10px;">Quote Request Details</h3>';
    echo '<table class="widefat striped" style="width:100%;">';
    echo '<tbody>';

    $rows = [
      'Event name' => $d['event_name'] ?? '',
      'Event start' => $this->fmt_datetime($e['start_datetime'] ?? ''),
      'Event finish' => $this->fmt_datetime($e['finish_datetime'] ?? ''),
      'Delivery date' => $this->fmt_date($t['delivery_date'] ?? ''),
      'Delivery timing' => $delivery_slot,
      'Delivery postcode' => $t['delivery_postcode'] ?? '',
      'Delivery address' => $t['delivery_address'] ?? '',
      'Collection/Return date' => $this->fmt_date($t['collection_date'] ?? ''),
      'Collection/Return timing' => $collection_slot,
      'Billing different?' => $billing_diff,
    ];

    foreach ($rows as $k => $v) {
      echo '<tr>';
      echo '<th style="width:220px;">' . esc_html($k) . '</th>';
      echo '<td>' . nl2br(esc_html((string)$v)) . '</td>';
      echo '</tr>';
    }

    // Only show billing fields if applicable
    if (!empty($d['billing_different'])) {
      echo '<tr><th>Billing postcode</th><td>' . esc_html($d['billing_postcode'] ?? '') . '</td></tr>';
      echo '<tr><th>Billing address</th><td>' . nl2br(esc_html($d['billing_address'] ?? '')) . '</td></tr>';
    }

    if (!empty($d['comments'])) {
      echo '<tr><th>Comments</th><td>' . nl2br(esc_html($d['comments'])) . '</td></tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
  }

  // ----------------------------
  // Admin: Tools metabox
  // ----------------------------
  public function add_admin_metabox_legacy() {
    add_meta_box(
      'kgi_quote_tools',
      'Quote Tools (KGI)',
      [$this, 'render_admin_metabox'],
      'shop_order',
      'side',
      'high'
    );
  }

  public function add_admin_metabox_hpos($screen_id) {
    // HPOS order edit screen
    add_meta_box(
      'kgi_quote_tools',
      'Quote Tools (KGI)',
      [$this, 'render_admin_metabox'],
      'woocommerce_page_wc-orders',
      'side',
      'high'
    );
  }

  public function render_admin_metabox($post_or_order) {
    $order = null;

    if ($post_or_order instanceof WC_Order) {
      $order = $post_or_order;
    } elseif (is_object($post_or_order) && isset($post_or_order->ID)) {
      $order = wc_get_order((int)$post_or_order->ID);
    }

    if (!$order && isset($_GET['id']))  $order = wc_get_order((int)$_GET['id']);
    if (!$order && isset($_GET['post'])) $order = wc_get_order((int)$_GET['post']);

    if (!$order) {
      echo '<p>Could not load order for Quote Tools.</p>';
      return;
    }

    $pay_url = $order->get_checkout_payment_url();

    echo '<p><strong>Status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';

    echo '<hr>';
    echo '<p><strong>Add fees (optional)</strong></p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="kgi_quote_apply_fees">';
    echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '">';
    wp_nonce_field('kgi_quote_apply_fees');

    echo '<p><label>Delivery fee</label><br><input style="width:100%;" name="delivery_fee" type="number" step="0.01" min="0" placeholder="e.g. 75.00"></p>';
    echo '<p><label>Deposit</label><br><input style="width:100%;" name="deposit" type="number" step="0.01" min="0" placeholder="e.g. 150.00"></p>';
    echo '<p><button class="button button-secondary" type="submit">Apply fees</button></p>';
    echo '</form>';

    echo '<hr>';
    echo '<p><strong>Pay link</strong></p>';
    echo '<p><a class="button button-primary" target="_blank" href="' . esc_url($pay_url) . '">Open pay link</a></p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="kgi_quote_send_pay_link">';
    echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '">';
    wp_nonce_field('kgi_quote_send_pay_link');
    echo '<p><button class="button" type="submit">Email pay link to customer</button></p>';
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

    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id));
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

    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id));
    exit;
  }

  // ----------------------------
  // Emails
  // ----------------------------
  private function email_admin_new_quote($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $to = get_option('admin_email');
    $subject = 'New quote request #' . $order->get_order_number();

    $data = $order->get_meta(self::ORDER_META_KEY);
    $t = is_array($data) ? ($data['transport'] ?? []) : [];
    $e = is_array($data) ? ($data['event'] ?? []) : [];
    $d = is_array($data) ? ($data['details'] ?? []) : [];

    $body  = "New quote request received.\n\n";
    $body .= "Order: #" . $order->get_order_number() . "\n";
    $body .= "Event Name: " . ($d['event_name'] ?? '') . "\n";
    $body .= "Customer: " . $order->get_formatted_billing_full_name() . "\n";
    $body .= "Email: " . $order->get_billing_email() . "\n";
    $body .= "Phone: " . $order->get_billing_phone() . "\n\n";
    $body .= "Event Start: " . ($e['start_datetime'] ?? '') . "\n";
    $body .= "Event Finish: " . ($e['finish_datetime'] ?? '') . "\n\n";
    $body .= "Delivery: " . ($t['delivery_date'] ?? '') . " / " . ($t['delivery_slot'] ?? '') . "\n";
    $body .= "Delivery Postcode: " . ($t['delivery_postcode'] ?? '') . "\n";
    $body .= "Delivery Address: " . ($t['delivery_address'] ?? '') . "\n\n";
    $body .= "Collection: " . ($t['collection_date'] ?? '') . " / " . ($t['collection_slot'] ?? '') . "\n\n";
    $body .= "Admin: " . admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id) . "\n";

    wp_mail($to, $subject, $body);
  }

  private function email_customer_quote_received($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $to = $order->get_billing_email();
    if (!$to || !is_email($to)) return;

    $subject = $this->customer_submit_subject();

    $data = $order->get_meta(self::ORDER_META_KEY);
    $t = is_array($data) ? ($data['transport'] ?? []) : [];
    $e = is_array($data) ? ($data['event'] ?? []) : [];
    $d = is_array($data) ? ($data['details'] ?? []) : [];

    $body  = "Hi " . $order->get_billing_first_name() . ",\n\n";
    $body .= $this->customer_submit_message_intro() . "\n";

    $body .= "Reference: #" . $order->get_order_number() . "\n";
    $body .= "Event Name: " . ($d['event_name'] ?? '') . "\n";
    $body .= "Event Start: " . ($e['start_datetime'] ?? '') . "\n";
    $body .= "Event Finish: " . ($e['finish_datetime'] ?? '') . "\n\n";

    $body .= "Delivery Date: " . ($t['delivery_date'] ?? '') . "\n";
    $body .= "Delivery Timing: " . ($t['delivery_slot'] ?? '') . "\n";
    $body .= "Delivery Postcode: " . ($t['delivery_postcode'] ?? '') . "\n";
    $body .= "Delivery Address: " . ($t['delivery_address'] ?? '') . "\n\n";

    $body .= "Collection/Return Date: " . ($t['collection_date'] ?? '') . "\n";
    $body .= "Collection/Return Timing: " . ($t['collection_slot'] ?? '') . "\n\n";

    // Add disclaimer (you said “maybe add disclaimer too”)
    $body .= $this->get_disclaimer_text() . "\n\n";

    $body .= "Thanks,\n" . get_bloginfo('name');

    wp_mail($to, $subject, $body);
  }
}

new KGI_Quote_Checkout();
