<?php
/*
Plugin Name: WooCommerce Chat Questionnaire
Text Domain: woo-chat-questionnaire
Description: Chat-style popup questions before Add to Cart on product detail page.
Version: 1.2
Author: Rutva Prajapati
*/

if (!defined('ABSPATH')) exit;

// Frontend Enqueue
add_action('wp_enqueue_scripts', function () {
  wp_enqueue_style('wc-chat-css', plugin_dir_url(__FILE__) . 'assets/chat.css', [], null);

  if (! is_product()) return;
  wp_enqueue_script('wc-chat-js', plugin_dir_url(__FILE__) . 'assets/chat.js', ['jquery'], null, true);

  if (! is_product()) return;
  global $product;

  wp_localize_script('wc-chat-js', 'WCChat', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'cart_url' => wc_get_cart_url(),
    'nonce'     => wp_create_nonce('wcq_nonce'),
    'has_form'  => wcq_product_has_form($product->get_id()),
    'product_id' => $product->get_id(),
  ]);
});

// Admin Enqueue (SelectWoo)
add_action('admin_enqueue_scripts', function ($hook) {
  if (!in_array($hook, ['post.php', 'post-new.php'])) return;

  wp_enqueue_script('selectWoo');
  wp_enqueue_style('select2');
  wp_enqueue_style('woocommerce_admin_styles');
});

// Custom Post Type - Forms
add_action('init', function () {
  register_post_type('wcq_form', [
    'labels' => array(
      'name'          => 'Product Forms',
      'singular_name' => 'Product Form',
      'menu_name'     => 'Product Forms',
      'add_new'       => 'Add Form',
      'add_new_item'  => 'Add New',
      'all_items'     => 'All Forms',
    ),
    'public'  => false,
    'show_ui' => true,
    'supports' => ['title'],
    'menu_icon' => 'dashicons-format-chat'
  ]);
});

add_filter('manage_wcq_form_posts_columns', function ($columns) {
  $new_columns = [];
  foreach ($columns as $key => $label) {
    $new_columns[$key] = $label;
    // Insert after Title column
    if ($key === 'title') {
      $new_columns['included_in_category'] = 'Included in Categories';
      $new_columns['included_in_products'] = 'Included in Products';
    }
  }
  $new_columns['wcq_status'] = 'Status';
  return $new_columns;
});

add_action('manage_wcq_form_posts_custom_column', function ($column, $post_id) {
  if ($column === 'wcq_status') {
    $status = get_post_meta($post_id, '_wcq_form_status', true) ?: 'active';
    echo $status === 'active' ? '<span style="color:green;font-weight:600;">Active</span>' : '<span style="color:red;">Inactive</span>';
  }
  if ($column === 'included_in_category') {
    $cats = get_post_meta($post_id, '_wcq_assigned_categories', true);
    if (!empty($cats) && is_array($cats)) {
      $cat_names = [];
      foreach ($cats as $cat_id) {
        $term = get_term($cat_id, 'product_cat'); // WooCommerce category taxonomy
        if ($term && !is_wp_error($term)) {
          $cat_names[] = $term->name;
        }
      }
      echo !empty($cat_names) ? wp_kses_post(implode('<br>', $cat_names)) : '-';
    } else {
      echo '—';
    }
  }
  if ($column === 'included_in_products') {
    $products = get_post_meta($post_id, '_wcq_assigned_products', true);
    if (!empty($products) && is_array($products)) {
      $product_names = [];
      foreach ($products as $product_id) {
        $product = get_post($product_id);
        if ($product) {
          $product_names[] = $product->post_title;
        }
      }
      echo !empty($product_names) ? wp_kses_post(implode('<br>', $product_names)) : '-';
    } else {
      echo '—';
    }
  }
}, 10, 2);

// Status meta box
add_action('add_meta_boxes', function () {
  add_meta_box(
    'wcq_form_status',
    'Form Status',
    'wcq_render_form_status_box',
    'wcq_form',
    'side',
    'high'
  );
});
function wcq_render_form_status_box($post)
{
  wp_nonce_field('wcq_form_status_nonce', 'wcq_form_status_nonce_field');
  $status = get_post_meta($post->ID, '_wcq_form_status', true);
  $status = $status ?: 'active';
?>
  <label style="display:block;margin-bottom:8px;">
    <input type="radio" name="wcq_form_status" value="active" <?php checked($status, 'active'); ?>>
    <strong>Active</strong>
  </label>
  <label style="display:block;">
    <input type="radio" name="wcq_form_status" value="inactive" <?php checked($status, 'inactive'); ?>>
    Inactive
  </label>
  <p style="font-size:12px;color:#666;margin-top:8px;">Inactive forms will not appear on products.</p>
<?php
}
add_action('save_post_wcq_form', function ($post_id) {
  // Verify nonce
  if (! isset($_POST['wcq_form_status_nonce_field']) || ! wp_verify_nonce($_POST['wcq_form_status_nonce_field'], 'wcq_form_status_nonce')) {
    return;
  }
  // Prevent autosave
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
    return;
  }
  // Permission check
  if (! current_user_can('edit_post', $post_id)) {
    return;
  }
  // SAVE STATUS
  if (isset($_POST['wcq_form_status'])) {
    update_post_meta(
      $post_id,
      '_wcq_form_status',
      $_POST['wcq_form_status'] === 'inactive' ? 'inactive' : 'active'
    );
  }
}, 10, 1);

// Meta box - Questions + assignment
add_action('add_meta_boxes', function () {
  add_meta_box('wcq_questions', 'Chat Questions', 'wcq_render_questions_box', 'wcq_form', 'normal', 'high');
});

function wcq_render_questions_box($post)
{
  $questions = get_post_meta($post->ID, '_wcq_questions', true);
  $questions = $questions ? json_decode($questions, true) : [];

  // Load products & categories for assignment
  $products = get_posts(['post_type' => 'product', 'numberposts' => -1]);
  $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);

  $assigned_products = get_post_meta($post->ID, '_wcq_assigned_products', true) ?: [];
  $assigned_categories = get_post_meta($post->ID, '_wcq_assigned_categories', true) ?: [];
?>
  <div id="wcq-builder">
    <div id="wcq-question-list" style="margin-top:12px;">
      <?php foreach ($questions as $index => $q):
        $cond_index = isset($q['condition']['question_index']) ? intval($q['condition']['question_index']) : '';
        $cond_value = isset($q['condition']['equals']) ? $q['condition']['equals'] : '';
      ?>
        <div class="wcq-question-item" style="padding: 18px; margin-bottom: 20px; background: #fff; border-radius: 15px; box-shadow: 0px 0px 5px 0px #000000f7;">
          <select class="wcq-type" style="width:50%;">
            <option value="TEXT" <?php selected($q['type'] ?? '', 'TEXT'); ?>>Text</option>
            <option value="RADIO" <?php selected($q['type'] ?? '', 'RADIO'); ?>>Radio</option>
            <option value="CHECKBOX" <?php selected($q['type'] ?? '', 'CHECKBOX'); ?>>Checkbox</option>
            <option value="SELECT" <?php selected($q['type'] ?? '', 'SELECT'); ?>>Dropdown</option>
          </select>

          <!-- <textarea class="widefat wcq-question" placeholder="Question text" style="margin-top:6px; height: 100px;"><?php //echo esc_textarea($q['question']); 
                                                                                                                          ?></textarea> -->
          <textarea class="widefat wcq-question" placeholder="Question text" style="margin-top:6px; height: 100px;"><?php echo htmlentities($q['question'], ENT_QUOTES, 'UTF-8'); ?></textarea>
          <input type="text" class="widefat wcq-options" placeholder="Options (comma separated)" style="margin-top:6px;" value="<?php echo isset($q['options']) ? esc_attr(join(',', $q['options'])) : ''; ?>">

          <div class="wcq-condition-box" style="margin-top:8px;">
            <label style="display:block;margin-bottom:4px;">Show only if (previous question equals)</label>
            <!-- store saved selected index in data-selected so JS can restore after building options -->
            <!-- <select class="wcq-cond-question" data-selected="<?php echo esc_attr($cond_index); ?>" style="width:49%;">
              <option value="">None</option>
            </select> -->
            <select class="wcq-cond-question" data-selected="<?php echo esc_attr($cond_index); ?>" style="width:49%;">
              <option value="">None</option>
              <?php foreach ($questions as $i => $prev_q): ?>
                <?php if ($i < $index): ?>
                  <option value="<?php echo $i; ?>" <?php selected($i, $cond_index); ?>>
                    <?php echo esc_html($prev_q['question']); ?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
            <input type="text" class="wcq-cond-value" placeholder="Expected answer" value="<?php echo esc_attr($cond_value); ?>" style="width:49%;">
          </div>
          <div class="wcq-validation-rules" style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; padding-top: 10px;">
            <div>
              <label>Can proceed ONLY if answer is</label>
              <input type="text" class="widefat wcq-can-proceed" value="<?php echo esc_attr($q['can_proceed'] ?? ''); ?>" placeholder="e.g. Yes">
              <p style="margin: 0;">Keep blank if user can proceed with every answer and you do not want to HARD STOP</p>
            </div>
            <div>
              <label>Hard Stop (Patient Alert) if answer is:</label>
              <input type="text" class="widefat wcq-patient-alert-val" value="<?php echo esc_attr($q['patient_alert_val'] ?? ''); ?>" placeholder="e.g. No">
            </div>
            <div style="grid-column: span 2;">
              <label>Patient Alert Message:</label>
              <textarea class="widefat wcq-patient-alert-msg" placeholder="Message to show user..."><?php echo esc_textarea($q['patient_alert_msg'] ?? ''); ?></textarea>
            </div>
            <div>
              <label>Mark as Orange (Admin Alert) if answer is:</label>
              <input type="text" class="widefat wcq-admin-alert-val" value="<?php echo esc_attr($q['admin_alert_val'] ?? ''); ?>" placeholder="e.g. Any">
            </div>
            <div>
              <label>Mark as Green if answer is:</label>
              <input type="text" class="widefat wcq-admin-alert-green-val" value="<?php echo esc_attr($q['admin_alert_green_val'] ?? ''); ?>" placeholder="e.g. Any">
            </div>
          </div>
          <button type="button" class="button wcq-remove" style="margin-top:6px;">Remove</button>
        </div>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="wcq_questions_json" id="wcq_questions_json">
    <button type="button" class="button" id="wcq-add-question">+ Add Question</button>
  </div>

  <hr style="margin:14px 0;" />

  <h4>Assign to Products</h4>
  <select name="wcq_assigned_products[]" class="wcq-product-select" multiple="multiple" style="width:100%;" data-placeholder="Search and select products...">
    <?php foreach ($products as $p): ?>
      <option value="<?php echo esc_attr($p->ID); ?>" <?php selected(in_array($p->ID, $assigned_products)); ?>>
        <?php echo esc_html($p->post_title); ?>
      </option>
    <?php endforeach; ?>
  </select>

  <h4>Assign to Categories</h4>
  <select name="wcq_assigned_categories[]" class="wcq-category-select" multiple="multiple" style="width:100%;" data-placeholder="Search and select categories...">
    <?php foreach ($categories as $c): ?>
      <option value="<?php echo esc_attr($c->term_id); ?>" <?php selected(in_array($c->term_id, $assigned_categories)); ?>>
        <?php echo esc_html($c->name); ?>
      </option>
    <?php endforeach; ?>
  </select>

  <script>
    jQuery(function($) {
      // Add question
      $('#wcq-add-question').on('click', function() {
        $('#wcq-question-list').append(`
            <div class="wcq-question-item" style="padding: 18px; margin-bottom: 20px; background: #fff; border-radius: 15px; box-shadow: 0px 0px 5px 0px #000000f7;">
              <select class="wcq-type" style="width:50%;">
                <option value="TEXT">Text</option>
                <option value="RADIO">Radio</option>
                <option value="CHECKBOX">Checkbox</option>
                <option value="SELECT">Dropdown</option>
              </select>
              <textarea class="widefat wcq-question" placeholder="Question text" style="margin-top:6px; height: 100px;"></textarea>
              <input type="text" class="widefat wcq-options" placeholder="Options (comma separated)" style="margin-top:6px;">
              <div class="wcq-condition-box" style="margin-top:8px;">
                <label style="display:block;margin-bottom:4px;">Show only if (previous question equals)</label>
                <select class="wcq-cond-question" data-selected="" style="width:49%;">
                  <option value="">None</option>
                </select>
                <input type="text" class="wcq-cond-value" placeholder="Expected answer" style="width:49%;">
              </div>
              <div class="wcq-validation-rules" style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; padding-top: 10px;">
                <div>
                  <label>Can proceed ONLY if answer is</label>
                  <input type="text" class="widefat wcq-can-proceed" value="<?php echo esc_attr($q['can_proceed'] ?? ''); ?>" placeholder="e.g. Yes">
                  <p style="margin: 0;">Keep blank if user can proceed with every answer and you do not want to HARD STOP</p>
                </div>
                <div>
                  <label>Hard Stop (Patient Alert) if answer is:</label>
                  <input type="text" class="widefat wcq-patient-alert-val" value="<?php echo esc_attr($q['patient_alert_val'] ?? ''); ?>" placeholder="e.g. No">
                </div>
                <div style="grid-column: span 2;">
                  <label>Patient Alert Message:</label>
                  <textarea class="widefat wcq-patient-alert-msg" placeholder="Message to show user..."><?php echo esc_textarea($q['patient_alert_msg'] ?? ''); ?></textarea>
                </div>
                <div>
                  <label>Mark as Orange (Admin Alert) if answer is:</label>
                  <input type="text" class="widefat wcq-admin-alert-val" value="<?php echo esc_attr($q['admin_alert_val'] ?? ''); ?>" placeholder="e.g. Any">
                </div>
                <div>
                  <label>Mark as Green if answer is:</label>
                  <input type="text" class="widefat wcq-admin-alert-green-val" value="<?php echo esc_attr($q['admin_alert_green_val'] ?? ''); ?>" placeholder="e.g. Any">
                </div>
              </div>
              <button type="button" class="button wcq-remove" style="margin-top:6px;">Remove</button>
            </div>
          `);
        updateJson();
        refreshConditionDropdowns();
      });

      // Remove question
      $(document).on('click', '.wcq-remove', function() {
        $(this).closest('.wcq-question-item').remove();
        refreshConditionDropdowns();
        updateJson();
      });

      // Update JSON when fields change
      $(document).on('input change', '.wcq-question, .wcq-type, .wcq-options, .wcq-cond-question, .wcq-cond-value, .wcq-can-proceed, .wcq-patient-alert-val, .wcq-patient-alert-msg, .wcq-admin-alert-val, .wcq-admin-alert-green-val', function() {
        refreshConditionDropdowns();
        updateJson();
      });

      function updateJson() {
        let data = [];
        $('#wcq-question-list .wcq-question-item').each(function() {
          // Collect all values including the new alert/proceed fields
          data.push({
            question: $(this).find('.wcq-question').val() || '',
            type: $(this).find('.wcq-type').val() || 'TEXT',
            options: $(this).find('.wcq-options').val() ? $(this).find('.wcq-options').val().split(',').map(s => s.trim()) : [],
            condition: $(this).find('.wcq-cond-question').val() !== '' ? {
              question_index: parseInt($(this).find('.wcq-cond-question').val(), 10),
              equals: $(this).find('.wcq-cond-value').val()
            } : null,
            // These lines are what's currently missing from your save logic:
            can_proceed: $(this).find('.wcq-can-proceed').val() || '',
            patient_alert_val: $(this).find('.wcq-patient-alert-val').val() || '',
            patient_alert_msg: $(this).find('.wcq-patient-alert-msg').val() || '',
            admin_alert_val: $(this).find('.wcq-admin-alert-val').val() || '',
            admin_alert_green_val: $(this).find('.wcq-admin-alert-green-val').val() || ''
          });
        });
        $('#wcq_questions_json').val(JSON.stringify(data));
      }

      function refreshConditionDropdowns() {
        // For each question, build a dropdown of previous questions
        $('#wcq-question-list .wcq-question-item').each(function(index) {

          let select = $(this).find('.wcq-cond-question');
          // Prefer current DOM value, otherwise use data-selected (from PHP)
          let currentVal = select.val();
          if (!currentVal) {
            currentVal = select.data('selected') !== undefined ? select.data('selected').toString() : '';
          }

          // rebuild options 
          select.html('<option value="">None</option>');

          $('#wcq-question-list .wcq-question-item').each(function(i) {
            if (i < index) {
              let qText = $(this).find('.wcq-question').val() || ('Question ' + (i + 1));
              select.append('<option value="' + i + '">' + qText + '</option>');
            }
          });

          // restore selected if present
          if (currentVal !== '' && select.find('option[value="' + currentVal + '"]').length) {
            select.val(currentVal);
          } else {
            select.val(''); // ensure none selected if invalid
          }
        });
      }

      // initial populate
      updateJson();
      refreshConditionDropdowns();

      // Initialize SelectWoo on the assignment selects
      if (typeof $.fn.selectWoo !== 'undefined') {
        $('.wcq-product-select, .wcq-category-select').selectWoo({
          width: '100%',
          placeholder: 'Search and select...'
        });
      } else if (typeof $.fn.select2 !== 'undefined') {
        // fallback if selectWoo not present
        $('.wcq-product-select, .wcq-category-select').select2({
          width: '100%',
          placeholder: 'Search and select...'
        });
      }
    });
  </script>
<?php
}

// Save meta
add_action('save_post', function ($post_id) {
  // bailouts
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  $post_type = get_post_type($post_id);
  if ($post_type !== 'wcq_form') return;

  if (isset($_POST['wcq_questions_json'])) {
    // store raw JSON as-is (sanitised a bit)
    // update_post_meta($post_id, '_wcq_questions', wp_kses_post($_POST['wcq_questions_json']));
    update_post_meta($post_id, '_wcq_questions', $_POST['wcq_questions_json']);
  } else {
    delete_post_meta($post_id, '_wcq_questions');
  }
  if (isset($_POST['wcq_assigned_products'])) {
    update_post_meta($post_id, '_wcq_assigned_products', array_map('intval', (array) $_POST['wcq_assigned_products']));
  } else {
    delete_post_meta($post_id, '_wcq_assigned_products');
  }
  if (isset($_POST['wcq_assigned_categories'])) {
    update_post_meta($post_id, '_wcq_assigned_categories', array_map('intval', (array) $_POST['wcq_assigned_categories']));
  } else {
    delete_post_meta($post_id, '_wcq_assigned_categories');
  }
}, 10, 1);

// Popup HTML (frontend)
add_action('wp_footer', function () { ?>
  <div id="wcq-popup" style="display:none;">
    <div class="wcq-overlay"></div>
    <div class="wcq-box">
      <div class="wcq-header">
        <span id="wcq-close" style="float: right; cursor: pointer; font-size: 24px; line-height: 20px; font-weight: bold; padding: 5px;">&times;</span>
        <div id="wcq-progress-wrap">
          <div id="wcq-progress-bar"></div>
        </div>
      </div>
      <div class="wcq-messages"></div>
      <div class="wcq-footer">
        <div id="wcq-error-msg" style="color: #d63638; background: #fbe9e9; padding: 10px; margin-bottom: 10px; border-radius: 5px; display: none; font-weight: bold; border: 1px solid #f1aeb1;"></div>
        <button id="wcq-next">Proceed</button>
      </div>
    </div>
  </div>
<?php });

// AJAX: Load Questions (form-driven)
add_action('wp_ajax_get_wcq_questions', 'get_wcq_questions');
add_action('wp_ajax_nopriv_get_wcq_questions', 'get_wcq_questions');

function get_wcq_questions()
{
  $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
  if (!$product_id) {
    wp_send_json([]);
  }

  $forms = get_posts([
    'post_type'      => 'wcq_form',
    'numberposts'    => -1,
    'meta_key'       => '_wcq_form_status',
    'meta_value'     => 'active',
  ]);

  $matched_form = null;
  foreach ($forms as $form) {
    $prod_ids = get_post_meta($form->ID, '_wcq_assigned_products', true) ?: [];
    $cat_ids  = get_post_meta($form->ID, '_wcq_assigned_categories', true) ?: [];

    // Direct product match
    if (!empty($prod_ids) && in_array($product_id, (array) $prod_ids)) {
      $matched_form = $form->ID;
      break;
    }

    // Category match
    if (!empty($cat_ids)) {
      if (wcq_product_matches_categories($product_id, (array) $cat_ids)) {
        $matched_form = $form->ID;
        break;
      }
    }
  }

  if (!$matched_form) {
    wp_send_json([]);
  }

  $questions_json = get_post_meta($matched_form, '_wcq_questions', true);
  $questions = $questions_json ? json_decode($questions_json, true) : [];

  if (!is_array($questions)) {
    wp_send_json([]);
  }
  wp_send_json($questions);
}

// Store Answers in Session
add_action('wp_ajax_wcq_store_answers', 'wcq_store_answers');
add_action('wp_ajax_nopriv_wcq_store_answers', 'wcq_store_answers');

function wcq_store_answers()
{
  check_ajax_referer('wcq_nonce', 'nonce');
  if (!WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
  }
  $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
  $answers    = isset($_POST['answers']) ? $_POST['answers'] : [];
  if (!$product_id) {
    wp_send_json_error();
  }
  WC()->session->set('wcq_answers_' . $product_id, $answers);
  wp_send_json_success();
}

// 1. Tag the order if an answer was flagged
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values) {
  $product_id = $values['product_id'];

  // Ensure we are checking the exact session key set in wcq_store_answers
  if (WC()->session) {
    $answers = WC()->session->get('wcq_answers_' . $product_id);

    if (!empty($answers)) {
      // Save as a structured array so we can read the 'flagged' status later
      $item->add_meta_data('wcq_answers', $answers);
    }
  }
}, 10, 3);

// 2. Display Answers with Orange Highlighting in Admin
// Remove "Added to cart" notice on Cart page 
add_action('wp', function () {
  if (!is_cart() || !WC()->session) return;
  $notices = WC()->session->get('wc_notices', []);
  if (!empty($notices['success'])) {
    foreach ($notices['success'] as $key => $notice) {
      if (stripos($notice['notice'], 'added to your cart') !== false || stripos($notice['notice'], 'has been added') !== false) {
        unset($notices['success'][$key]);
      }
    }
    WC()->session->set('wc_notices', $notices);
  }
}, 1);
// 1. Hide raw questionnaire data from frontend, emails, and admin meta table
add_filter('woocommerce_hidden_order_itemmeta', function ($hidden) {
  $hidden[] = 'wcq_answers';
  return $hidden;
});
add_filter('woocommerce_order_item_get_formatted_meta_data', function ($meta, $item) {
  foreach ($meta as $key => $m) {
    if ($m->key === 'wcq_answers' || $m->key === '_wcq_answers') {
      unset($meta[$key]);
    }
  }
  return $meta;
}, 10, 2);

// 3. Show formatted answers in ADMIN order page (optional)
add_action('woocommerce_after_order_itemmeta', function ($item_id, $item, $product) {
  $answers = $item->get_meta('wcq_answers', true);

  if (empty($answers) || !is_array($answers)) return;
  echo '<div class="wcq-admin-display" style="margin-top: 15px; border: 1px solid #ddd; padding: 10px; background: #fff;">';
  echo '<strong style="display:block; margin-bottom: 8px;">Medical Questionnaire Results:</strong>';

  foreach ($answers as $qa) {
    // Strict check for the boolean flag sent by chat.js
    $is_flagged = !empty($qa['flagged']) && ($qa['flagged'] === true || $qa['flagged'] === 'true');
    $is_safe    = !empty($qa['safe']) && ($qa['safe'] === true || $qa['safe'] === 'true' || $qa['safe'] == 1);

    // Apply orange highlight only if flagged is true
    if ($is_flagged) {
      $row_style = 'background-color: #fff3cd !important; border-left: 4px solid #ffa500; padding: 8px; margin-bottom: 4px; border-radius: 4px;';
    } elseif ($is_safe) {
      $row_style = 'background-color: #e6f9e6 !important; border-left: 4px solid #28a745; padding: 8px; margin-bottom: 4px; border-radius: 4px;';
    } else {
      $row_style = 'padding: 4px; border-bottom: 1px solid #f0f0f0; margin-bottom: 2px;';
    }
    echo '<div style="' . $row_style . '">';
    //echo '<strong>' . esc_html($qa['question']) . ':</strong> ' . esc_html($qa['answer']);
    echo '<strong>' . wp_kses($qa['question'], ['strong' => [], 'br' => [], 'ul' => [], 'li' => []]) . ':</strong> ' . esc_html($qa['answer']);
    echo '</div>';
  }
  echo '</div>';
}, 10, 3);
function wcq_product_matches_categories($product_id, array $assigned_cat_ids)
{
  if (empty($assigned_cat_ids)) {
    return false;
  }
  $product_terms = wp_get_post_terms($product_id, 'product_cat', [
    'fields' => 'all',
  ]);
  if (empty($product_terms) || is_wp_error($product_terms)) {
    return false;
  }
  foreach ($product_terms as $term) {
    // Direct match
    if (in_array($term->term_id, $assigned_cat_ids, true)) {
      return true;
    }
    // Parent match
    $ancestors = get_ancestors($term->term_id, 'product_cat');
    if (array_intersect($ancestors, $assigned_cat_ids)) {
      return true;
    }
  }
  return false;
}
function wcq_product_has_form($product_id)
{
  $forms = get_posts([
    'post_type'   => 'wcq_form',
    'numberposts' => -1,
    'fields'      => 'ids',
    'meta_key'    => '_wcq_form_status',
    'meta_value'  => 'active',
  ]);
  foreach ($forms as $form_id) {
    $prod_ids = get_post_meta($form_id, '_wcq_assigned_products', true) ?: [];
    $cat_ids  = get_post_meta($form_id, '_wcq_assigned_categories', true) ?: [];
    if (in_array($product_id, (array) $prod_ids, true)) {
      return true;
    }
    if (! empty($cat_ids)) {
      if (wcq_product_matches_categories($product_id, (array) $cat_ids)) {
        return true;
      }
    }
  }
  return false;
}
add_filter('woocommerce_loop_add_to_cart_link', function ($html, $product) {
  $product_id = $product->get_id();
  // 1. Check for Cooldown/One-Time Restrictions first
  $check = wcq_check_customer_purchase_allowed($product_id);
  if (!$check['allowed']) {
    return sprintf(
      '<div class="wcq-shop-restriction-msg" style="color: #d63638; font-size: 0.9em; font-weight: 600; text-align: center;">%s</div>',
      esc_html($check['message'])
    );
  }
  // 2. If allowed, check if product has a Questionnaire Form
  if (wcq_product_has_form($product_id)) {
    return sprintf(
      '<a href="%s" class="button add_to_cart_button">%s</a>',
      esc_url(get_permalink($product_id)),
      esc_html__('View Product', 'woo-chat-questionnaire')
    );
  }
  return $html;
}, 10, 2);
// Replace Add to Cart button with Restriction Message on Detail Page
add_action('woocommerce_before_single_product', function () {
  global $product;
  if (!$product) return;

  // Check if the customer is restricted
  $check = wcq_check_customer_purchase_allowed($product->get_id());

  if (!$check['allowed']) {
    // 1. Remove the default Add to Cart button/qty selector
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
    remove_action('woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart', 30);
    remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);

    // 2. Output the restriction message in its place
    echo sprintf(
      '<div class="wcq-single-restriction-msg" style="color: #d63638; font-size: 1.1em; font-weight: 600; padding: 15px; border: 1px solid #f1aeb1; background: #fbe9e9; border-radius: 5px; margin: 20px 0; display: inline-block; width: 100%%;">%s</div>',
      esc_html($check['message'])
    );
  }
}, 5); // Priority 5 ensures we check before the button at 30 is rendered

/**********************************************************************************************************
 * Need validations in place to prevent certain products from being purchased after x number of days. + 
 * Limitation for One time purchase as well. (this all will be at product level)
 ***********************************************************************************************************/
// SETTINGS PAGE: Master Toggles for Restrictions
add_action('admin_menu', function () {
  add_submenu_page(
    'edit.php?post_type=wcq_form', // Parent slug (your custom plugin menu)
    'Settings',    // Page title
    'Settings',             // Menu title
    'manage_options',              // Capability
    'wcq-safety-settings',         // Menu slug
    'wcq_render_settings_page'     // Callback function
  );
});

function wcq_render_settings_page()
{
?>
  <div class="wrap">
    <h1>Purchase Restrictions</h1>
    <form method="post" action="options.php">
      <?php
      settings_fields('wcq_safety_group');
      do_settings_sections('wcq-safety-settings');
      submit_button();
      ?>
    </form>
  </div>
<?php
}

add_action('admin_init', function () {
  register_setting('wcq_safety_group', 'wcq_enable_cat_cd');
  register_setting('wcq_safety_group', 'wcq_enable_prod_cd');
  register_setting('wcq_safety_group', 'wcq_enable_one_time');

  add_settings_section('wcq_main_section', 'Restriction Switches', null, 'wcq-safety-settings');

  add_settings_field('cat_cd', 'Enable category cooldown', 'wcq_field_html', 'wcq-safety-settings', 'wcq_main_section', ['id' => 'wcq_enable_cat_cd']);
  add_settings_field('prod_cd', 'Enable product cooldown', 'wcq_field_html', 'wcq-safety-settings', 'wcq_main_section', ['id' => 'wcq_enable_prod_cd']);
  add_settings_field('one_time', 'Enable product one-time purchase', 'wcq_field_html', 'wcq-safety-settings', 'wcq_main_section', ['id' => 'wcq_enable_one_time']);
});

function wcq_field_html($args)
{
  $val = get_option($args['id']);
  echo '<input type="checkbox" name="' . esc_attr($args['id']) . '" value="1" ' . checked(1, $val, false) . ' />';
  echo '<p class="description">' . $args['desc'] . '</p>';
}

// PRODUCT & CATEGORY LEVEL SETTINGS 
add_action('woocommerce_product_options_inventory_product_data', function () {
  // Only show if the global toggles are ON
  $prod_enabled = get_option('wcq_enable_prod_cd') == '1';
  $otp_enabled  = get_option('wcq_enable_one_time') == '1';
  if ($prod_enabled) {
    woocommerce_wp_text_input([
      'id'          => '_wcq_cooldown_days',
      'label'       => 'Purchase Cooldown (days)',
      'type'        => 'number',
      'desc_tip'    => true,
      'description' => 'Number of days customer must wait before repurchasing.',
    ]);
  }
  if ($otp_enabled) {
    woocommerce_wp_checkbox([
      'id'          => '_wcq_one_time_purchase',
      'label'       => 'One-Time Purchase Only',
      'description' => 'Customer can purchase this product only once.',
    ]);
  }
});

add_action('product_cat_edit_form_fields', function ($term) {
  // Only show if the Category Cooldown toggle is ON
  if (get_option('wcq_enable_cat_cd') != '1') return;

  $cooldown = get_term_meta($term->term_id, 'category_cooldown_days', true);
?>
  <tr class="form-field">
    <th scope="row"><label>Cooldown Days</label></th>
    <td>
      <input type="number" name="category_cooldown_days" value="<?php echo esc_attr($cooldown); ?>">
      <p class="description">Days to wait before repurchasing from this category.</p>
    </td>
  </tr>
<?php
}, 10, 1);

// SAVE PRODUCT FIELDS
add_action('woocommerce_admin_process_product_object', function ($product) {
  // Save Cooldown Days (Product level)
  if (isset($_POST['_wcq_cooldown_days'])) {
    $product->update_meta_data(
      '_wcq_cooldown_days',
      absint($_POST['_wcq_cooldown_days'])
    );
  }
  // Save One-Time Purchase checkbox
  $product->update_meta_data(
    '_wcq_one_time_purchase',
    isset($_POST['_wcq_one_time_purchase']) ? 'yes' : 'no'
  );
});
// SAVE CATEGORY FIELDS
add_action('edited_product_cat', function ($term_id) {
  if (isset($_POST['category_cooldown_days'])) {
    update_term_meta(
      $term_id,
      'category_cooldown_days',
      sanitize_text_field($_POST['category_cooldown_days'])
    );
  }
});

// THE MASTER CHECKER: Handles Product, Category, and One-Time limits based on settings
function wcq_check_customer_purchase_allowed($product_id, $variation_id = 0)
{
  if (!function_exists('WC') || !get_current_user_id()) return ['allowed' => true];

  $customer_id = get_current_user_id();
  $base_id = $variation_id ? wp_get_post_parent_id($variation_id) : $product_id;

  // Fetch Toggles from Settings Page
  $cat_enabled  = get_option('wcq_enable_cat_cd') == '1';
  $prod_enabled = get_option('wcq_enable_prod_cd') == '1';
  $otp_enabled  = get_option('wcq_enable_one_time') == '1';

  // 1. One-Time Purchase
  $is_otp = ($otp_enabled && get_post_meta($base_id, '_wcq_one_time_purchase', true) === 'yes');

  // 2. Category Cooldown
  $max_cd = 0;
  $cat_name = '';
  if ($cat_enabled) {
    $terms = get_the_terms($base_id, 'product_cat');
    if ($terms && !is_wp_error($terms)) {
      foreach ($terms as $term) {
        $val = (int) get_term_meta($term->term_id, 'category_cooldown_days', true);
        if ($val > $max_cd) {
          $max_cd = $val;
          $cat_name = $term->name;
        }
      }
    }
  }

  // 3. Product Cooldown
  $prod_cd = $prod_enabled ? (int) get_post_meta($base_id, '_wcq_cooldown_days', true) : 0;

  // Final Cooldown Value (Category wins if set)
  $final_cd = ($max_cd > 0) ? $max_cd : $prod_cd;

  if ($final_cd <= 0 && !$is_otp) return ['allowed' => true];

  // Check Order History
  $orders = wc_get_orders(['customer_id' => $customer_id, 'status' => ['completed', 'processing']]);

  foreach ($orders as $order) {
    $date = $order->get_date_completed() ?: $order->get_date_created();
    $diff = (time() - $date->getTimestamp()) / 86400;

    foreach ($order->get_items() as $item) {
      $ordered_id = $item->get_product_id();

      // OTP Logic
      if ($is_otp && $ordered_id == $base_id) {
        return ['allowed' => false, 'message' => 'You can purchase this product only once.'];
      }
      $match = ($max_cd > 0) ? has_term($cat_name, 'product_cat', $ordered_id) : ($ordered_id == $base_id);

      if ($match && $final_cd > 0 && $diff < $final_cd) {
        $rem = ceil($final_cd - $diff);

        // If it's a category block, we specify which category for clarity
        //$message = ($max_cd > 0) ? "You can repurchase $cat_name products after $rem day(s)." : "You can repurchase this product after $rem day(s).";
        $message = ($max_cd > 0) ? "A purchase limit applies for safety reasons. Available in $rem day(s)." : "A purchase limit applies for safety reasons. Available in $rem day(s).";
        return ['allowed' => false, 'message' => $message];
      }
    }
  }
  return ['allowed' => true];
}

// AJAX GATEKEEPER
add_action('wp_ajax_wcq_check_cooldown', function () {
  /*$product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
  wp_send_json(wcq_check_customer_purchase_allowed($product_id));*/
  $product_id   = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
  $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;

  wp_send_json(wcq_check_customer_purchase_allowed($product_id, $variation_id));
});
add_action('wp_ajax_nopriv_wcq_check_cooldown', function () {
  wp_send_json(['allowed' => true]);
});

// WOOCOMMERCE VALIDATION (Clear cart on fail)
add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id, $qty, $variation_id = 0) {
  $check = wcq_check_customer_purchase_allowed($product_id, $variation_id);
  if (!$check['allowed']) {
    if (isset(WC()->cart)) WC()->cart->empty_cart();
    wc_add_notice(__($check['message'], 'woocommerce'), 'error');
    return false;
  }
  return $passed;
}, 20, 4);
