/**
 * KGI Quote Checkout - Full JS (fixed)
 * - Stepper navigation (click step numbers to go back)
 * - Conditional fields (billing address block)
 * - Save Transport step (AJAX)
 * - Submit Quote (AJAX -> creates Woo order)
 * - Cart qty + remove via AJAX endpoints
 * - GetAddress.io lookup via server-side WP AJAX (kgi_quote_lookup_postcode)
 *
 * Requires localized global:
 * KGI_QUOTE = { ajax_url: ".../admin-ajax.php", nonce: "..." }
 */
(function ($) {
  "use strict";

  function $wrapFrom(el) {
    return $(el).closest("[data-kgi-quote]");
  }

  function getCurrentStep($wrap) {
    var $active = $wrap.find(".kgi-panel.is-active");
    var step = parseInt($active.data("panel"), 10);
    return isNaN(step) ? 1 : step;
  }

  function setStep($wrap, step) {
    step = parseInt(step, 10);
    if (isNaN(step) || step < 1) step = 1;
    if (step > 4) step = 4;

    $wrap.find(".kgi-panel").removeClass("is-active");
    $wrap.find('.kgi-panel[data-panel="' + step + '"]').addClass("is-active");

    $wrap.find(".kgi-step").removeClass("is-active is-done");
    $wrap.find(".kgi-step").each(function () {
      var s = parseInt($(this).data("step"), 10);
      if (s < step) $(this).addClass("is-done");
      if (s === step) $(this).addClass("is-active");
    });

    $wrap.find("[data-kgi-next-top]").toggle(step < 4);

    showConditionals($wrap);
  }

  function showConditionals($wrap) {
    $wrap.find("[data-kgi-conditional]").each(function () {
      var target = $(this).data("kgi-conditional"); // e.g. details.billing_different
      var showWhen = String($(this).data("show-when"));
      var $field = $wrap.find('[data-kgi-field="' + target + '"]');

      var current = "";
      if ($field.length) {
        if ($field[0].type === "checkbox") current = $field[0].checked ? "1" : "";
        else current = String($field.val());
      }

      $(this).toggle(String(current) === showWhen);
    });
  }

  function getPayload($wrap) {
    var payload = {};

    $wrap.find("[data-kgi-field]").each(function () {
      var key = $(this).data("kgi-field"); // transport.delivery_date
      if (!key) return;

      var parts = String(key).split(".");
      if (parts.length !== 2) return;

      var root = parts[0];
      var field = parts[1];

      if (!payload[root]) payload[root] = {};

      var val;
      if (this.type === "checkbox") val = this.checked ? "1" : "";
      else val = $(this).val();

      payload[root][field] = val;
    });

    return payload;
  }

  function ajaxPost(action, extra) {
    extra = extra || {};
    return $.post(
      KGI_QUOTE.ajax_url,
      $.extend({ action: action, nonce: KGI_QUOTE.nonce }, extra)
    );
  }

  // ----------------------------
  // Cart AJAX
  // ----------------------------
  function cartUpdate(cartKey, qty) {
    return ajaxPost("kgi_quote_cart_update", { cart_key: cartKey, qty: qty });
  }

  function cartRemove(cartKey) {
    return ajaxPost("kgi_quote_cart_remove", { cart_key: cartKey });
  }

  // ----------------------------
  // Save / Submit AJAX
  // ----------------------------
  function saveStep(payload) {
    return ajaxPost("kgi_quote_save_step", { payload: JSON.stringify(payload) });
  }

  function submitQuote(payload) {
    return ajaxPost("kgi_quote_submit", { payload: JSON.stringify(payload) });
  }

  // ----------------------------
  // Address lookup AJAX
  // ----------------------------
  function lookupPostcode(postcode) {
    return ajaxPost("kgi_quote_lookup_postcode", { postcode: postcode });
  }

  function toMultilineAddress(oneLine) {
    // Convert "A, B, C, POSTCODE" into multiline
    return String(oneLine || "")
      .split(",")
      .map(function (s) {
        return s.trim();
      })
      .filter(Boolean)
      .join("\n");
  }

  function renderAddressOptions($select, addresses) {
    $select.empty();

    // ✅ FIX: must be <option>, not $("")
    $select.append($("<option></option>").val("").text("Select an address..."));

    (addresses || []).forEach(function (addr) {
      $select.append($("<option></option>").val(addr).text(addr));
    });
  }

  function bindAddressLookup() {
    // Handle Find buttons (delivery / billing)
    $(document).on("click", "[data-kgi-find]", function () {
      var $wrap = $wrapFrom(this);
      var which = $(this).data("kgi-find"); // "delivery" or "billing"

      var postcodeField =
        which === "delivery"
          ? "transport.delivery_postcode"
          : "details.billing_postcode";

      var $pc = $wrap.find('[data-kgi-field="' + postcodeField + '"]');
      var postcode = ($pc.val() || "").trim();

      if (!postcode) {
        alert("Please enter a postcode.");
        return;
      }

      var $results = $wrap.find('[data-kgi-address-results="' + which + '"]');
      var $select = $wrap.find('[data-kgi-address-select="' + which + '"]');

      var $btn = $(this);
      $btn.prop("disabled", true);

      lookupPostcode(postcode)
        .done(function (res) {
          // WP JSON format: { success: true, data: { addresses: [...] } }
          var addresses = res && res.data && res.data.addresses ? res.data.addresses : [];

          if (!addresses.length) {
            alert("No addresses found for that postcode.");
            $results.hide();
            return;
          }

          renderAddressOptions($select, addresses);
          $results.show();
        })
        .fail(function (xhr) {
          var msg =
            (xhr.responseJSON &&
              xhr.responseJSON.data &&
              xhr.responseJSON.data.message) ||
            "Address lookup failed.";
          alert(msg);
        })
        .always(function () {
          $btn.prop("disabled", false);
        });
    });

    // When address selected, write it into the textarea field that is actually stored
    $(document).on("change", "[data-kgi-address-select]", function () {
      var $wrap = $wrapFrom(this);
      var which = $(this).data("kgi-address-select"); // "delivery" or "billing"
      var val = $(this).val() || "";

      if (which === "delivery") {
        $wrap
          .find('[data-kgi-field="transport.delivery_address"]')
          .val(toMultilineAddress(val));
      } else if (which === "billing") {
        $wrap
          .find('[data-kgi-field="details.billing_address"]')
          .val(toMultilineAddress(val));
      }
    });
  }

  // ----------------------------
  // Stepper click-to-go-back
  // ----------------------------
  function bindStepperClicks() {
    $(document).on("click", ".kgi-step", function () {
      var $wrap = $wrapFrom(this);
      var targetStep = parseInt($(this).data("step"), 10);
      var currentStep = getCurrentStep($wrap);

      if (isNaN(targetStep)) return;

      // only go back (or stay)
      if (targetStep <= currentStep) {
        setStep($wrap, targetStep);
      }
    });
  }

  // ----------------------------
  // UI Events
  // ----------------------------
  function bindNavButtons() {
    $(document).on("click", "[data-kgi-next],[data-kgi-next-top]", function () {
      var $wrap = $wrapFrom(this);
      var step = getCurrentStep($wrap);
      if (step < 4) setStep($wrap, step + 1);
    });

    $(document).on("change", "[data-kgi-field]", function () {
      var $wrap = $wrapFrom(this);
      showConditionals($wrap);
    });

    $(document).on("click", "[data-kgi-save-transport]", function () {
      var $wrap = $wrapFrom(this);
      var payload = getPayload($wrap);

      var $btn = $(this);
      $btn.prop("disabled", true).addClass("is-loading");

      saveStep(payload)
        .done(function () {
          setStep($wrap, 3);
        })
        .fail(function (xhr) {
          var msg =
            (xhr.responseJSON &&
              xhr.responseJSON.data &&
              xhr.responseJSON.data.message) ||
            "Could not save transport step.";
          alert(msg);
        })
        .always(function () {
          $btn.prop("disabled", false).removeClass("is-loading");
        });
    });

    $(document).on("click", "[data-kgi-save-details]", function () {
      var $wrap = $wrapFrom(this);
      var payload = getPayload($wrap);

      $wrap.find("[data-kgi-error]").hide().text("");

      var $btn = $(this);
      $btn.prop("disabled", true).addClass("is-loading");

      submitQuote(payload)
        .done(function (res) {
          if (res && res.data && res.data.submitted_html) {
            $wrap.find("[data-kgi-submitted-meta]").html(res.data.submitted_html);
          }
          setStep($wrap, 4);
        })
        .fail(function (xhr) {
          var msg =
            (xhr.responseJSON &&
              xhr.responseJSON.data &&
              xhr.responseJSON.data.message) ||
            "Please check the form and try again.";
          $wrap.find("[data-kgi-error]").show().text(msg);
          setStep($wrap, 3);
        })
        .always(function () {
          $btn.prop("disabled", false).removeClass("is-loading");
        });
    });
  }

  function bindCartControls() {
    $(document).on("click", "[data-kgi-qty]", function () {
      var $item = $(this).closest(".kgi-item");
      var cartKey = $item.data("cart-key");
      if (!cartKey) return;

      var $input = $item.find(".kgi-qty-input");
      var qty = parseInt($input.val(), 10) || 0;

      if ($(this).data("kgi-qty") === "inc") qty++;
      if ($(this).data("kgi-qty") === "dec") qty = Math.max(0, qty - 1);

      $input.val(qty);

      cartUpdate(cartKey, qty).always(function () {
        window.location.reload();
      });
    });

    $(document).on("change", ".kgi-qty-input", function () {
      var $item = $(this).closest(".kgi-item");
      var cartKey = $item.data("cart-key");
      if (!cartKey) return;

      var qty = parseInt($(this).val(), 10);
      if (isNaN(qty) || qty < 0) qty = 0;

      cartUpdate(cartKey, qty).always(function () {
        window.location.reload();
      });
    });

    $(document).on("click", "[data-kgi-remove]", function () {
      var $item = $(this).closest(".kgi-item");
      var cartKey = $item.data("cart-key");
      if (!cartKey) return;

      cartRemove(cartKey).always(function () {
        window.location.reload();
      });
    });
  }

  // ----------------------------
  // Init
  // ----------------------------
  $(function () {
    if (
      typeof window.KGI_QUOTE === "undefined" ||
      !KGI_QUOTE.ajax_url ||
      !KGI_QUOTE.nonce
    ) {
      console.error("KGI_QUOTE is not defined. Check enqueue + wp_localize_script().");
      return;
    }

    bindStepperClicks();
    bindNavButtons();
    bindCartControls();
    bindAddressLookup();

    $("[data-kgi-quote]").each(function () {
      var $wrap = $(this);
      setStep($wrap, 1);
      showConditionals($wrap);
    });
  });
})(jQuery);
