<?php
/**
 * Plugin Name: KGI Quote Checkout (WooCommerce)
 * Description: Custom multi-step quote checkout: Items -> Transport -> Details -> Submitted. Creates a Woo order in "Quote Requested" status and lets admin send a pay link later.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

class KGI_Quote_Checkout {
  const SESSION_KEY = 'kgi_quote_data';
  const NONCE_ACTION = 'kgi_quote_nonce_action';
  const ORDER_META_KEY = '_kgi_quote_data';
  const DISCLAIMER_META_KEY = '_kgi_quote_disclaimer';

  public function __construct() {
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    add_shortcode('kgi_quote_checkout', [$this, 'shortcode_quote_checkout']);

    add_action('wp_ajax_kgi_quote_save_step', [$this, 'ajax_save_step']);
    add_action('wp_ajax_nopriv_kgi_quote_save_step', [$this, 'ajax_save_step']);

    add_action('wp_ajax_kgi_quote_submit', [$this, 'ajax_submit_quote']);
    add_action('wp_ajax_nopriv_kgi_quote_submit', [$this, 'ajax_submit_quote']);

    // Custom order status
    add_action('init', [$this, 'register_order_status']);
    add_filter('wc_order_statuses', [$this, 'add_order_status_to_list']);

    // Admin tools
    add_action('add_meta_boxes', [$this, 'add_admin_metabox']);
    add_action('admin_post_kgi_quote_send_pay_link', [$this, 'admin_send_pay_link']);
    add_action('admin_post_kgi_quote_apply_fees', [$this, 'admin_apply_fees']);

    // Ensure sessions available
    add_action('init', [$this, 'maybe_init_wc_session'], 5);
  }

  public function maybe_init_wc_session() {
    if (function_exists('WC') && WC()->session) {
      // touch session to ensure it exists
      WC()->session->get(self::SESSION_KEY);
    }
  }

  public function enqueue_assets() {
    if (!function_exists('is_page')) return;

    // Only load on pages containing the shortcode (best effort)
    global $post;
    if (!$post || !has_shortcode($post->post_content, 'kgi_quote_checkout')) return;

    wp_enqueue_style('kgi-quote-css', plugins_url('assets/quote.css', __FILE__), [], '1.0.0');
    wp_enqueue_script('kgi-quote-js', plugins_url('assets/quote.js', __FILE__), ['jquery'], '1.0.0', true);

    $ajax_url = admin_url('admin-ajax.php');

    wp_localize_script('kgi-quote-js', 'KGI_QUOTE', [
      'ajax_url' => $ajax_url,
      'nonce'    => wp_create_nonce(self::NONCE_ACTION),
    ]);
  }

  private function get_disclaimer_text() {
    // Your provided message
    return "No stock has been booked at this stage. Only once a quote is accepted and payment made is the booking confirmed. All hires are quoted on a dry hire basis and no set up is included unless specifically listed in the quote. We have 4 delivery and collection timings available, please make sure you have selected the most appropriate one, advising us of any alterations, restrictions or handling that is required. At the time of quoting all items listed are available to hire. We work on a first come first served basis and in order to avoid disappointment, we strongly advise that you confirm your requirements and pay a deposit to secure your hire as soon as possible. Minimum order values apply. Price excludes VAT. Please check our website FAQ’s for more information.";
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

  private function cart_required() {
    return function_exists('WC') && WC()->cart && !WC()->cart->is_empty();
  }

  public function shortcode_quote_checkout() {
    if (!function_exists('WC') || !WC()->cart) {
      return '<div class="kgi-quote-error">WooCommerce cart not available.</div>';
    }

    if (WC()->cart->is_empty()) {
      return '<div class="kgi-quote-empty">Your quote cart is empty.</div>';
    }

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
        <!-- STEP 1 -->
        <section class="kgi-panel is-active" data-panel="1">
          <?php echo $this->render_items(); ?>
          <div class="kgi-note">Hire prices cover you for up to 5 days of hire</div>
          <button class="kgi-cta" type="button" data-kgi-next>Next step</button>
        </section>

        <!-- STEP 2 -->
        <section class="kgi-panel" data-panel="2">
          <?php echo $this->render_transport($data); ?>
          <button class="kgi-cta" type="button" data-kgi-save-transport>Next step</button>
        </section>

        <!-- STEP 3 -->
        <section class="kgi-panel" data-panel="3">
          <?php echo $this->render_details($data); ?>
          <button class="kgi-cta" type="button" data-kgi-save-details>Complete quote</button>
        </section>

        <!-- STEP 4 -->
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

      <input type="hidden" data-kgi-nonce value="<?php echo esc_attr(wp_create_nonce(self::NONCE_ACTION)); ?>">
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
          $qty = (int)$item['quantity'];
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

  private function render_transport($data) {
    $t = isset($data['transport']) && is_array($data['transport']) ? $data['transport'] : [];
    $slots = [
      'economy_0800_1730' => 'Economy 08:00-17:30 (Best Price)',
      'am_0800_1200'      => 'AM 08:00-12:00',
      'pm_1200_1730'      => 'PM 12:00-17:30',
      'out_of_hours'      => 'Out of Hours (By arrangement)',
    ];

    $delivery_date = $t['delivery_date'] ?? '';
    $delivery_slot = $t['delivery_slot'] ?? 'economy_0800_1730';
    $postcode      = $t['postcode'] ?? '';
    $street_level  = !empty($t['street_level']) ? '1' : '';
    $notes         = $t['notes'] ?? '';

    $collection_date = $t['collection_date'] ?? '';
    $collection_slot = $t['collection_slot'] ?? 'economy_0800_1730';
    $collection_same = !isset($t['collection_same']) || $t['collection_same'] === '1' ? '1' : '';
    $collection_addr = $t['collection_address'] ?? '';

    ob_start();
    ?>
    <div class="kgi-transport-grid">
      <div class="kgi-card">
        <h3>Delivery</h3>
        <label>Date</label>
        <input type="date" data-kgi-field="transport.delivery_date" value="<?php echo esc_attr($delivery_date); ?>">

        <label>Type</label>
        <select data-kgi-field="transport.delivery_slot">
          <?php foreach ($slots as $k => $label): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($delivery_slot, $k); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>

        <label>Post Code *</label>
        <div class="kgi-row">
          <input type="text" placeholder="Enter your Postcode" data-kgi-field="transport.postcode" value="<?php echo esc_attr($postcode); ?>">
          <button type="button" class="kgi-pill kgi-pill-ghost" data-kgi-find-postcode>Find</button>
        </div>

        <label class="kgi-check">
          <input type="checkbox" data-kgi-field="transport.street_level" value="1" <?php checked($street_level, '1'); ?>>
          <span>By checking this box you confirm your order is going to the street level, with no set up, dismantle or access restrictions.</span>
        </label>

        <label>Restrictions / handling notes (optional)</label>
        <textarea rows="3" data-kgi-field="transport.notes" placeholder="Tell us about access, lifts, stairs, restrictions, timing constraints..."><?php echo esc_textarea($notes); ?></textarea>
      </div>

      <div class="kgi-card">
        <h3>Collection</h3>
        <label>Date</label>
        <input type="date" data-kgi-field="transport.collection_date" value="<?php echo esc_attr($collection_date); ?>">

        <label>Type</label>
        <select data-kgi-field="transport.collection_slot">
          <?php foreach ($slots as $k => $label): ?>
            <option value="<?php echo esc_attr($k); ?>" <?php selected($collection_slot, $k); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>

        <label>Collection Address</label>
        <select data-kgi-field="transport.collection_same">
          <option value="1" <?php selected($collection_same, '1'); ?>>Collection Address Is The Same As Delivery</option>
          <option value="0" <?php selected($collection_same, '0'); ?>>Use a different collection address</option>
        </select>

        <div class="kgi-conditional" data-kgi-conditional="transport.collection_same" data-show-when="0">
          <label>Collection address (if different)</label>
          <textarea rows="3" data-kgi-field="transport.collection_address" placeholder="Enter collection address"><?php echo esc_textarea($collection_addr); ?></textarea>
        </div>
      </div>
    </div>

    <div class="kgi-footnote">
      We recommend booking economy slots a day before and a day after your event to keep transit costs down.
    </div>

    <div class="kgi-disclaimer">
      <?php echo esc_html($this->get_disclaimer_text()); ?>
    </div>
    <?php
    return ob_get_clean();
  }

  private function render_details($data) {
    $d = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];

    $first = $d['first_name'] ?? '';
    $last  = $d['last_name'] ?? '';
    $email = $d['email'] ?? '';
    $phone = $d['phone'] ?? '';
    $company = $d['company'] ?? '';
    $voucher = $d['voucher'] ?? '';

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
          <label>Email address *</label>
          <input type="email" data-kgi-field="details.email" value="<?php echo esc_attr($email); ?>" placeholder="Email Address">
        </div>

        <div class="kgi-field">
          <label>Phone *</label>
          <input type="text" data-kgi-field="details.phone" value="<?php echo esc_attr($phone); ?>" placeholder="Telephone Number">
        </div>

        <div class="kgi-field">
          <label>Company</label>
          <input type="text" data-kgi-field="details.company" value="<?php echo esc_attr($company); ?>" placeholder="Company">
        </div>

        <div class="kgi-field">
          <label>Gift Card or Voucher</label>
          <input type="text" data-kgi-field="details.voucher" value="<?php echo esc_attr($voucher); ?>" placeholder="Gift Card or Voucher">
        </div>

        <div class="kgi-error" data-kgi-error style="display:none;"></div>
      </div>

      <div class="kgi-card kgi-card-image" aria-hidden="true"></div>
    </div>
    <?php
    return ob_get_clean();
  }

  public function ajax_save_step() {
    $this->assert_ajax_nonce();

    if (!function_exists('WC') || !WC()->cart) {
      wp_send_json_error(['message' => 'Cart unavailable'], 400);
    }

    $payload = json_decode(stripslashes($_POST['payload'] ?? ''), true);
    if (!is_array($payload)) $payload = [];

    $data = $this->session_get();

    // shallow merge
    $data = array_merge($data, $payload);

    $this->session_set($data);

    wp_send_json_success(['ok' => true, 'data' => $data]);
  }

  public function ajax_submit_quote() {
    $this->assert_ajax_nonce();

    if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
      wp_send_json_error(['message' => 'Your cart is empty.'], 400);
    }

    $payload = json_decode(stripslashes($_POST['payload'] ?? ''), true);
    if (!is_array($payload)) $payload = [];

    $data = $this->session_get();
    $data = array_merge($data, $payload);

    // Validate required fields
    $errors = [];

    $transport = $data['transport'] ?? [];
    $details   = $data['details'] ?? [];

    if (empty($transport['delivery_date'])) $errors[] = 'Please select a delivery date.';
    if (empty($transport['collection_date'])) $errors[] = 'Please select a collection date.';
    if (empty(trim($transport['postcode'] ?? ''))) $errors[] = 'Please enter a postcode.';
    if (empty($transport['street_level'])) $errors[] = 'Please confirm street level / no restrictions checkbox.';

    if (empty(trim($details['first_name'] ?? ''))) $errors[] = 'First name is required.';
    if (empty(trim($details['last_name'] ?? ''))) $errors[] = 'Last name is required.';
    if (empty(trim($details['email'] ?? '')) || !is_email($details['email'])) $errors[] = 'A valid email is required.';
    if (empty(trim($details['phone'] ?? ''))) $errors[] = 'Phone is required.';

    if ($errors) {
      wp_send_json_error(['message' => implode(' ', $errors)], 422);
    }

    // Create order
    $order = wc_create_order();

    // Add items from cart
    foreach (WC()->cart->get_cart() as $cart_item_key => $item) {
      if (empty($item['data'])) continue;
      $order->add_product($item['data'], (int)$item['quantity'], [
        'subtotal' => $item['line_subtotal'],
        'total'    => $item['line_total'],
      ]);
    }

    // Set billing details (so pay link checkout is prefilled)
    $order->set_billing_first_name(sanitize_text_field($details['first_name']));
    $order->set_billing_last_name(sanitize_text_field($details['last_name']));
    $order->set_billing_email(sanitize_email($details['email']));
    $order->set_billing_phone(sanitize_text_field($details['phone']));
    $order->set_billing_company(sanitize_text_field($details['company'] ?? ''));

    // Store meta
    $order->update_meta_data(self::ORDER_META_KEY, $data);
    $order->update_meta_data(self::DISCLAIMER_META_KEY, $this->get_disclaimer_text());

    // Put a clear internal note
    $order->add_order_note('Quote request submitted via custom quote checkout.');

    // Set custom status
    $order->set_status('wc-quote-requested');

    // Totals (no payment, but keep item totals)
    $order->calculate_totals();

    $order->save();

    // Emails
    $this->email_admin_new_quote($order->get_id());
    $this->email_customer_quote_received($order->get_id());

    // Clear cart after quote submitted (matches Yahire flow)
    WC()->cart->empty_cart();
    $this->session_set([]); // reset session quote data

    wp_send_json_success([
      'ok' => true,
      'order_id' => $order->get_id(),
      'submitted_html' => $this->submitted_meta_html($order),
    ]);
  }

  private function submitted_meta_html($order) {
    $order_id = $order->get_id();
    $html = '<div class="kgi-submitted-order">';
    $html .= '<p><strong>Reference:</strong> ' . esc_html($order->get_order_number()) . '</p>';
    $html .= '<p><strong>We’ll email you within 24 hours.</strong></p>';
    $html .= '</div>';
    return $html;
  }

  private function assert_ajax_nonce() {
    $nonce = $_POST['nonce'] ?? '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['message' => 'Security check failed'], 403);
    }
  }

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
    // Insert after pending if possible
    $new = [];
    foreach ($statuses as $k => $v) {
      $new[$k] = $v;
      if ($k === 'wc-pending') {
        $new['wc-quote-requested'] = 'Quote Requested';
      }
    }
    if (!isset($new['wc-quote-requested'])) {
      $new['wc-quote-requested'] = 'Quote Requested';
    }
    return $new;
  }

  public function add_admin_metabox() {
    add_meta_box(
      'kgi_quote_tools',
      'Quote Tools (KGI)',
      [$this, 'render_admin_metabox'],
      'shop_order',
      'side',
      'high'
    );
  }

  public function render_admin_metabox($post) {
    $order = wc_get_order($post->ID);
    if (!$order) return;

    $data = $order->get_meta(self::ORDER_META_KEY);
    $pay_url = $order->get_checkout_payment_url();

    echo '<p><strong>Status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';

    if (is_array($data)) {
      $t = $data['transport'] ?? [];
      echo '<p><strong>Delivery:</strong> ' . esc_html(($t['delivery_date'] ?? '') . ' / ' . ($t['delivery_slot'] ?? '')) . '</p>';
      echo '<p><strong>Collection:</strong> ' . esc_html(($t['collection_date'] ?? '') . ' / ' . ($t['collection_slot'] ?? '')) . '</p>';
      echo '<p><strong>Postcode:</strong> ' . esc_html($t['postcode'] ?? '') . '</p>';
    }

    // Apply fees (optional helpers)
    echo '<hr>';
    echo '<p><strong>Add fees (optional)</strong></p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="kgi_quote_apply_fees">';
    echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '">';
    wp_nonce_field('kgi_quote_apply_fees');

    echo '<p><label>Delivery fee</label><br><input style="width:100%;" name="delivery_fee" type="number" step="0.01" min="0" placeholder="e.g. 75.00"></p>';
    echo '<p><label>Deposit (negative discount not applied; this is a fee/line)</label><br><input style="width:100%;" name="deposit" type="number" step="0.01" min="0" placeholder="e.g. 150.00"></p>';
    echo '<p><button class="button button-secondary" type="submit">Apply fees</button></p>';
    echo '</form>';

    // Send pay link
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
    $body .= "Thanks,\n";
    $body .= get_bloginfo('name');

    wp_mail($to, $subject, $body);

    $order->add_order_note('Pay link emailed to customer.');
    $order->save();

    wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit'));
    exit;
  }

  private function email_admin_new_quote($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $to = get_option('admin_email');
    $subject = 'New quote request #' . $order->get_order_number();

    $data = $order->get_meta(self::ORDER_META_KEY);
    $t = is_array($data) ? ($data['transport'] ?? []) : [];

    $body  = "New quote request received.\n\n";
    $body .= "Order: #" . $order->get_order_number() . "\n";
    $body .= "Customer: " . $order->get_formatted_billing_full_name() . "\n";
    $body .= "Email: " . $order->get_billing_email() . "\n";
    $body .= "Phone: " . $order->get_billing_phone() . "\n\n";
    $body .= "Delivery: " . ($t['delivery_date'] ?? '') . " / " . ($t['delivery_slot'] ?? '') . "\n";
    $body .= "Collection: " . ($t['collection_date'] ?? '') . " / " . ($t['collection_slot'] ?? '') . "\n";
    $body .= "Postcode: " . ($t['postcode'] ?? '') . "\n";
    $body .= "Notes: " . ($t['notes'] ?? '') . "\n\n";
    $body .= "Admin: " . admin_url('post.php?post=' . $order_id . '&action=edit') . "\n";

    wp_mail($to, $subject, $body);
  }

  private function email_customer_quote_received($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $to = $order->get_billing_email();
    if (!$to || !is_email($to)) return;

    $subject = 'Quote request received #' . $order->get_order_number();

    $body  = "Hi " . $order->get_billing_first_name() . ",\n\n";
    $body .= "Thanks for placing your quote request. We’re manually checking items and delivery options and will email you within 24 hours.\n\n";
    $body .= $this->get_disclaimer_text() . "\n\n";
    $body .= "Reference: #" . $order->get_order_number() . "\n\n";
    $body .= "Thanks,\n";
    $body .= get_bloginfo('name');

    wp_mail($to, $subject, $body);
  }
}

new KGI_Quote_Checkout();
