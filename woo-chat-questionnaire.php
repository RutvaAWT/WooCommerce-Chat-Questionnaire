<?php
/*
Plugin Name: WooCommerce Chat Questionnaire
Text Domain: woo-chat-questionnaire
Description: Chat-style popup questions before Add to Cart on product detail page.
Version: 1.1
Author: Rutva Prajapati
*/

if (!defined('ABSPATH')) exit;

// Frontend Enqueue
add_action('wp_enqueue_scripts', function () {
  wp_enqueue_style('wc-chat-css', plugin_dir_url(__FILE__) . 'assets/chat.css');

  if ( ! is_product() ) return;
  wp_enqueue_script('wc-chat-js', plugin_dir_url(__FILE__) . 'assets/chat.js', ['jquery'], null, true);

  if ( ! is_product() ) return;
  global $product;

  wp_localize_script('wc-chat-js', 'WCChat', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'cart_url' => wc_get_cart_url(),
    'nonce'     => wp_create_nonce('wcq_nonce'),
    'has_form'  => wcq_product_has_form( $product->get_id() ),
    'product_id'=> $product->get_id(),
  ]);
});

// Admin Enqueue (SelectWoo)
add_action('admin_enqueue_scripts', function($hook){
  if (!in_array($hook, ['post.php','post-new.php'])) return;

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
  return $new_columns;
});

add_action('manage_wcq_form_posts_custom_column', function ($column, $post_id) {
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
      echo !empty($cat_names) ? esc_html(implode(', ', $cat_names)) : '—';
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
      echo !empty($product_names) ? esc_html(implode(', ', $product_names)) : '—';
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
function wcq_render_form_status_box( $post ) {
  wp_nonce_field( 'wcq_form_status_nonce', 'wcq_form_status_nonce_field' );
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
  if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
    return;
  }
  // Permission check
  if ( ! current_user_can('edit_post', $post_id) ) {
    return;
  }
  // SAVE STATUS
  if ( isset($_POST['wcq_form_status']) ) {
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

function wcq_render_questions_box($post) {
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
        <div class="wcq-question-item" style="padding:8px;border:1px solid #eee;margin-bottom:20px;background:#fff;">
          <select class="wcq-type" style="width:50%;">
            <option value="TEXT" <?php selected($q['type'] ?? '', 'TEXT'); ?>>Text</option>
            <option value="RADIO" <?php selected($q['type'] ?? '', 'RADIO'); ?>>Radio</option>
            <option value="CHECKBOX" <?php selected($q['type'] ?? '', 'CHECKBOX'); ?>>Checkbox</option>
            <option value="SELECT" <?php selected($q['type'] ?? '', 'SELECT'); ?>>Dropdown</option>
          </select>

          <input type="text" class="widefat wcq-question" placeholder="Question text" value="<?php echo esc_attr($q['question']); ?>" style="margin-top:6px;">
          <input type="text" class="widefat wcq-options" placeholder="Options (comma separated)" style="margin-top:6px;" value="<?php echo isset($q['options']) ? esc_attr(join(',', $q['options'])) : ''; ?>">

          <div class="wcq-condition-box" style="margin-top:8px;">
            <label style="display:block;margin-bottom:4px;">Show only if (previous question equals)</label>
            <!-- store saved selected index in data-selected so JS can restore after building options -->
            <select class="wcq-cond-question" data-selected="<?php echo esc_attr($cond_index); ?>" style="width:49%;">
              <option value="">None</option>
            </select>
            <input type="text" class="wcq-cond-value" placeholder="Expected answer" value="<?php echo esc_attr($cond_value); ?>" style="width:49%;">
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
            <div class="wcq-question-item" style="padding:8px;border:1px solid #eee;margin-bottom:20px;background:#fff;">
              <select class="wcq-type" style="width:50%;">
                <option value="TEXT">Text</option>
                <option value="RADIO">Radio</option>
                <option value="CHECKBOX">Checkbox</option>
                <option value="SELECT">Dropdown</option>
              </select>
              <input type="text" class="widefat wcq-question" placeholder="Question text" style="margin-top:6px;">
              <input type="text" class="widefat wcq-options" placeholder="Options (comma separated)" style="margin-top:6px;">
              <div class="wcq-condition-box" style="margin-top:8px;">
                <label style="display:block;margin-bottom:4px;">Show only if (previous question equals)</label>
                <select class="wcq-cond-question" data-selected="" style="width:49%;">
                  <option value="">None</option>
                </select>
                <input type="text" class="wcq-cond-value" placeholder="Expected answer" style="width:49%;">
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
        updateJson();
        refreshConditionDropdowns();
      });

      // Update JSON when fields change
      $(document).on('input change', '.wcq-question, .wcq-type, .wcq-options, .wcq-cond-question, .wcq-cond-value', function() {
        updateJson();
        refreshConditionDropdowns();
      });

      function updateJson() {
        let data = [];
        $('#wcq-question-list .wcq-question-item').each(function() {
          let question = $(this).find('.wcq-question').val() || '';
          let type = $(this).find('.wcq-type').val() || 'TEXT';
          let options = $(this).find('.wcq-options').val() || '';
          let cond_q = $(this).find('.wcq-cond-question').val();
          let cond_val = $(this).find('.wcq-cond-value').val();

          data.push({
              question: question,
              type: type,
              options: options ? options.split(',').map(function(i){ return i.trim(); }) : [],
              condition: cond_q !== '' ? {
                  question_index: parseInt(cond_q, 10),
                  equals: cond_val
              } : null
          });
        });
        $('#wcq_questions_json').val(JSON.stringify(data));
      }

      function refreshConditionDropdowns() {
        // For each question, build a dropdown of previous questions
        $('#wcq-question-list .wcq-question-item').each(function(index){

          let select = $(this).find('.wcq-cond-question');
          // Prefer current DOM value, otherwise use data-selected (from PHP)
          let currentVal = select.val();
          if (!currentVal) {
            currentVal = select.data('selected') !== undefined ? select.data('selected').toString() : '';
          }

          // rebuild options
          select.html('<option value="">None</option>');

          $('#wcq-question-list .wcq-question-item').each(function(i){
            if (i < index) {
              let qText = $(this).find('.wcq-question').val() || ('Question ' + (i+1));
              select.append('<option value="'+i+'">'+qText+'</option>');
            }
          });

          // restore selected if present
          if (currentVal !== '' && select.find('option[value="'+currentVal+'"]').length) {
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
    update_post_meta($post_id, '_wcq_questions', wp_kses_post($_POST['wcq_questions_json']));
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
        <div id="wcq-progress-wrap">
          <div id="wcq-progress-bar"></div>
        </div>
      </div>
      <div class="wcq-messages"></div>
      <div class="wcq-footer">
        <button id="wcq-next">Proceed</button>
      </div>
    </div>
  </div>
<?php });

// AJAX: Load Questions (form-driven)
add_action('wp_ajax_get_wcq_questions', 'get_wcq_questions');
add_action('wp_ajax_nopriv_get_wcq_questions', 'get_wcq_questions');

function get_wcq_questions() {
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
      if ( wcq_product_matches_categories( $product_id, (array) $cat_ids ) ) {
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

function wcq_store_answers() {
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

// Save Answers to Order Meta
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values) {
  $product_id = $values['product_id'];
  $answers    = WC()->session ? WC()->session->get('wcq_answers_' . $product_id) : '';
  if (!empty($answers)) {
    $item->add_meta_data('wcq_answers', wp_json_encode($answers));
    if (WC()->session) {
      WC()->session->__unset('wcq_answers_' . $product_id);
    }
  }
}, 10, 3);

// Remove "Added to cart" notice on Cart page 
add_action('wp', function () {
  if (!is_cart() || !WC()->session) return;
  $notices = WC()->session->get('wc_notices', []);
  if (!empty($notices['success'])) {
    foreach ($notices['success'] as $key => $notice) {
      if ( stripos($notice['notice'], 'added to your cart') !== false || stripos($notice['notice'], 'has been added') !== false ) {
        unset($notices['success'][$key]);
      }
    }
    WC()->session->set('wc_notices', $notices);
  }
}, 1);

// 1. Hide raw questionnaire data from frontend, emails, and admin meta table
add_filter('woocommerce_hidden_order_itemmeta', function($hidden){
  $hidden[] = 'wcq_answers';
  return $hidden;
});
add_filter('woocommerce_order_item_get_formatted_meta_data', function($meta, $item){
  foreach ($meta as $key => $m) {
    if ($m->key === 'wcq_answers' || $m->key === '_wcq_answers') {
      unset($meta[$key]);
    }
  }
  return $meta;
}, 10, 2);

// 2. Show formatted answers in FRONTEND order details
add_action('woocommerce_order_item_meta_end', function($item_id, $item, $order){ 
  $raw = $item->get_meta('wcq_answers', true);
  if (empty($raw)) return;
  $answers = json_decode($raw, true);
  if (!is_array($answers)) return;
  echo '<div class="wcq-answers" style="margin-top: 20px;"><strong>Questionnaire Answers:</strong><br><table style="border-width: 1px 1px 1px 1px;"><thead><th>Question</th><th>Answer</th></thead><tbody>';
  foreach ($answers as $qa) {
    echo '<tr><td>' . esc_html($qa['question']) . '</td><td>' . esc_html($qa['answer']) . '</td></tr>';
  }
  echo '</tbody></table></div>';
}, 10, 3);

// 3. Show formatted answers in ADMIN order page (optional)
add_action('woocommerce_after_order_itemmeta', function($item_id, $item, $product){
  $raw = $item->get_meta('wcq_answers', true);
  if (empty($raw)) return;
  $answers = json_decode($raw, true);
  if (!is_array($answers)) return;
  echo '<div style="margin-top: 20px;"><strong>Questionnaire Answers:</strong><br>';
  foreach ($answers as $qa) {
    echo '<div><strong>' . esc_html($qa['question']) . ':</strong> ' . esc_html($qa['answer']) . '</div>';
  }
  echo '</div>';
}, 10, 3);

function wcq_product_matches_categories( $product_id, array $assigned_cat_ids ) {
  if ( empty($assigned_cat_ids) ) {
    return false;
  }
  $product_terms = wp_get_post_terms($product_id, 'product_cat', [
    'fields' => 'all',
  ]);
  if ( empty($product_terms) || is_wp_error($product_terms) ) {
    return false;
  }
  foreach ( $product_terms as $term ) {
    // Direct match
    if ( in_array($term->term_id, $assigned_cat_ids, true) ) {
      return true;
    }
    // Parent match
    $ancestors = get_ancestors($term->term_id, 'product_cat');
    if ( array_intersect($ancestors, $assigned_cat_ids) ) {
      return true;
    }
  }
  return false;
}

function wcq_product_has_form( $product_id ) {
  $forms = get_posts([
    'post_type'   => 'wcq_form',
    'numberposts' => -1,
    'fields'      => 'ids',
    'meta_key'    => '_wcq_form_status',
    'meta_value'  => 'active',
  ]);
  foreach ( $forms as $form_id ) {
    $prod_ids = get_post_meta($form_id, '_wcq_assigned_products', true) ?: [];
    $cat_ids  = get_post_meta($form_id, '_wcq_assigned_categories', true) ?: [];
    if ( in_array($product_id, (array) $prod_ids, true) ) {
      return true;
    }
    if ( ! empty($cat_ids) ) {
      if ( wcq_product_matches_categories( $product_id, (array) $cat_ids ) ) {
        return true;
      }
    }
  }
  return false;
}
add_filter('woocommerce_loop_add_to_cart_link', function ($html, $product) {
  if ( wcq_product_has_form( $product->get_id() ) ) {
    return sprintf(
      '<a href="%s" class="button add_to_cart_button">%s</a>',
      esc_url( get_permalink( $product->get_id() ) ),
      esc_html__( 'View Product', 'woo-chat-questionnaire' )
    );
  }
  return $html;
}, 10, 2);

add_filter('manage_wcq_form_posts_columns', function ($cols) {
  $cols['wcq_status'] = 'Status';
  return $cols;
});
add_action('manage_wcq_form_posts_custom_column', function ($column, $post_id) {
  if ( $column === 'wcq_status' ) {
    $status = get_post_meta($post_id, '_wcq_form_status', true) ?: 'active';
    echo $status === 'active' ? '<span style="color:green;font-weight:600;">Active</span>' : '<span style="color:red;">Inactive</span>';
  }
}, 10, 2);

/**********************************************************************************************************
* Need validations in place to prevent certain products from being purchased after x number of days. + 
* Limitation for One time purchase as well. (this all will be at product level)
***********************************************************************************************************/
// Product-level settings (Simple + Variable parent)
add_action('woocommerce_product_options_inventory_product_data', function () {
  woocommerce_wp_text_input([
    'id'          => '_wcq_cooldown_days',
    'label'       => 'Purchase Cooldown (days)',
    'type'        => 'number',
    'desc_tip'    => true,
    'description' => 'Number of days customer must wait before repurchasing.',
  ]);
  woocommerce_wp_checkbox([
    'id'          => '_wcq_one_time_purchase',
    'label'       => 'One-Time Purchase Only',
    'description' => 'Customer can purchase this product only once.',
  ]);
});

add_action('woocommerce_admin_process_product_object', function ($product) {
  if (isset($_POST['_wcq_cooldown_days'])) {
    $product->update_meta_data(
      '_wcq_cooldown_days',
      absint($_POST['_wcq_cooldown_days'])
    );
  }
  $product->update_meta_data(
    '_wcq_one_time_purchase',
    isset($_POST['_wcq_one_time_purchase']) ? 'yes' : 'no'
  );
});

add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id, $qty, $variation_id = 0) {
  $customer_id = get_current_user_id();
  if (!$customer_id) {
    return $passed; // guests allowed
  }

  // Determine the "base" product ID
  $base_product_id = $variation_id ? wp_get_post_parent_id($variation_id) : $product_id;

  // Fetch settings from parent (or simple product)
  $cooldown_days = (int) get_post_meta($base_product_id, '_wcq_cooldown_days', true);
  $one_time      = get_post_meta($base_product_id, '_wcq_one_time_purchase', true) === 'yes';
  
  if (!$cooldown_days && !$one_time) {
    return $passed; // no restriction
  }

  // Get all previous orders (completed + processing)
  $orders = wc_get_orders([
    'customer_id' => $customer_id,
    'limit'       => -1,
    'status'      => ['completed', 'processing'],
    'return'      => 'objects',
  ]);

  if (!$orders) {
    return $passed;
  }

  $purchased_before = false;
  $last_purchase_ts = null;

  foreach ($orders as $order) {
    foreach ($order->get_items() as $item) {
      $item_product_id   = $item->get_product_id();     // parent ID
      $item_variation_id = $item->get_variation_id();   // variation ID
      // Match simple OR variation
      if (
        $item_product_id == $base_product_id ||
        ($variation_id && $item_variation_id == $variation_id)
      ) {
        $purchased_before = true;
        $date = $order->get_date_completed() ?: $order->get_date_created();
        if ($date) {
          $ts = $date->getTimestamp();
          if (!$last_purchase_ts || $ts > $last_purchase_ts) {
            $last_purchase_ts = $ts;
          }
        }
      }
    }
  }

  // ---------- RULE 1: ONE-TIME PURCHASE ----------
  if ($one_time && $purchased_before) {
    wc_add_notice(
      __('You can purchase this product only once.', 'woocommerce'),
      'error'
    );
    return false;
  }

  // ---------- RULE 2: COOLDOWN ----------
  if ($cooldown_days > 0 && $purchased_before && $last_purchase_ts) {
    $days_since = (time() - $last_purchase_ts) / DAY_IN_SECONDS;
    if ($days_since < $cooldown_days) {
      $remaining = ceil($cooldown_days - $days_since);
      wc_add_notice(
        sprintf(
          __('You can repurchase this product after %d day(s).', 'woocommerce'),
          $remaining
        ),
        'error'
      );
      return false;
    }
  }
  return $passed;
}, 10, 4);