(function($){
  function setStep($wrap, step){
    $wrap.find('.kgi-panel').removeClass('is-active');
    $wrap.find('.kgi-panel[data-panel="'+step+'"]').addClass('is-active');

    $wrap.find('.kgi-step').removeClass('is-active is-done');
    $wrap.find('.kgi-step').each(function(){
      var s = parseInt($(this).data('step'),10);
      if (s < step) $(this).addClass('is-done');
      if (s === step) $(this).addClass('is-active');
    });

    // Top "Next" only for steps 1-3
    $wrap.find('[data-kgi-next-top]').toggle(step < 4);
  }

  function getPayload($wrap){
    // Collect all data-kgi-field values into nested object
    var payload = {};
    $wrap.find('[data-kgi-field]').each(function(){
      var key = $(this).data('kgi-field'); // e.g. "transport.delivery_date"
      if (!key) return;

      var parts = key.split('.');
      var root = parts[0];
      var field = parts[1];

      if (!payload[root]) payload[root] = {};

      var val;
      if (this.type === 'checkbox') {
        val = this.checked ? '1' : '';
      } else {
        val = $(this).val();
      }
      payload[root][field] = val;
    });
    return payload;
  }

  function showConditionals($wrap){
    $wrap.find('[data-kgi-conditional]').each(function(){
      var target = $(this).data('kgi-conditional'); // e.g. transport.collection_same
      var showWhen = String($(this).data('show-when'));
      var $field = $wrap.find('[data-kgi-field="'+target+'"]');
      var current = $field.length ? String($field.val()) : '';
      $(this).toggle(current === showWhen);
    });
  }

  function ajaxSaveStep(payload){
    return $.post(KGI_QUOTE.ajax_url, {
      action: 'kgi_quote_save_step',
      nonce: KGI_QUOTE.nonce,
      payload: JSON.stringify(payload)
    });
  }

  function ajaxSubmit(payload){
    return $.post(KGI_QUOTE.ajax_url, {
      action: 'kgi_quote_submit',
      nonce: KGI_QUOTE.nonce,
      payload: JSON.stringify(payload)
    });
  }

  // Optional: basic cart qty change via wc-ajax endpoints (keeps it simple)
  function updateCartItem(cartKey, qty){
    // Using Woo's "update_cart" requires a form submit normally.
    // We'll do a lightweight POST to current URL with cart updates.
    var data = {};
    data['cart['+cartKey+'][qty]'] = qty;
    data['update_cart'] = 'Update cart';
    return $.post(window.location.href, data);
  }

  function removeCartItem(cartKey){
    // Remove uses cart_item_key via ?remove_item=xxx usually; we can POST similarly
    var url = new URL(window.location.href);
    url.searchParams.set('remove_item', cartKey);
    return fetch(url.toString(), { credentials: 'same-origin' });
  }

  $(document).on('click', '[data-kgi-qty]', function(){
    var $wrap = $(this).closest('[data-kgi-quote]');
    var $item = $(this).closest('.kgi-item');
    var cartKey = $item.data('cart-key');
    var $input = $item.find('.kgi-qty-input');
    var qty = parseInt($input.val(),10) || 0;

    if ($(this).data('kgi-qty') === 'inc') qty++;
    if ($(this).data('kgi-qty') === 'dec') qty = Math.max(0, qty-1);

    $input.val(qty);

    // Update cart then reload to reflect totals nicely
    updateCartItem(cartKey, qty).always(function(){ window.location.reload(); });
  });

  $(document).on('click', '[data-kgi-remove]', function(){
    var $item = $(this).closest('.kgi-item');
    var cartKey = $item.data('cart-key');
    removeCartItem(cartKey).then(function(){ window.location.reload(); });
  });

  $(document).on('change', '[data-kgi-field]', function(){
    var $wrap = $(this).closest('[data-kgi-quote]');
    showConditionals($wrap);
  });

  $(document).on('click', '[data-kgi-next],[data-kgi-next-top]', function(){
    var $wrap = $(this).closest('[data-kgi-quote]');
    var $active = $wrap.find('.kgi-panel.is-active');
    var step = parseInt($active.data('panel'),10);
    if (step < 4) setStep($wrap, step+1);
  });

  $(document).on('click', '[data-kgi-save-transport]', function(){
    var $wrap = $(this).closest('[data-kgi-quote]');
    var payload = getPayload($wrap);

    ajaxSaveStep(payload).done(function(){
      setStep($wrap, 3);
    }).fail(function(xhr){
      alert((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Could not save transport step.');
    });
  });

  $(document).on('click', '[data-kgi-save-details]', function(){
    var $wrap = $(this).closest('[data-kgi-quote]');
    var payload = getPayload($wrap);

    $wrap.find('[data-kgi-error]').hide().text('');

    ajaxSubmit(payload).done(function(res){
      if (res && res.data && res.data.submitted_html) {
        $wrap.find('[data-kgi-submitted-meta]').html(res.data.submitted_html);
      }
      setStep($wrap, 4);
    }).fail(function(xhr){
      var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Please check the form and try again.';
      $wrap.find('[data-kgi-error]').show().text(msg);
      // stay on step 3
    });
  });

  // Init
  $(function(){
    $('[data-kgi-quote]').each(function(){
      setStep($(this), 1);
      showConditionals($(this));
    });
  });

})(jQuery);
