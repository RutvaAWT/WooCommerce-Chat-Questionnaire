jQuery(function ($) {
  // ONLY intercept if product HAS form
  if (!WCChat.has_form) {
    return; // WooCommerce works normally
  }

  let questions = [];
  let answers = [];
  let current = 0;

  // Open popup when Add to Cart clicked
  $(document).on('click', '.single_add_to_cart_button', function (e) {
    if (typeof WCChat === 'undefined') return; 

    e.preventDefault();
    var $btn = $(this);
    var originalText = $btn.text();

    // Clear any existing notices first
    $('.woocommerce-error, .woocommerce-message, .woocommerce-info').remove();

    $btn.text('Checking...').prop('disabled', true);

    $.post(WCChat.ajaxurl, {
        action: 'wcq_check_cooldown',
        product_id: WCChat.product_id
    }, function (checkRes) {

        if (checkRes.allowed === false) {
            // 1. Define the notice HTML
            var noticeHtml = 
                '<div class="woocommerce-notices-wrapper">' +
                    '<div class="woocommerce-error" role="alert" style="margin-top: 20px;">' +
                        checkRes.message +
                    '</div>' +
                '</div>';

            // 2. Inject at the top of the page
            // We target the breadcrumb or the main site container to get it to the very top
            if ($('.woocommerce-notices-wrapper').length) {
                $('.woocommerce-notices-wrapper').after(noticeHtml);
            } else if ($('#content').length) {
                $('#content').prepend(noticeHtml);
            } else {
                $('body.single-product').find('.site-content, #main').prepend(noticeHtml);
            }

            // 3. Smooth scroll to the top so the user sees it
            $('html, body').animate({
                scrollTop: 0
            }, 600);

            // 4. Reset button state
            $btn.text(originalText).prop('disabled', false);
            return; 
        }

        // Proceed to questions if allowed
        $.post(WCChat.ajaxurl, {
            action: 'get_wcq_questions',
            product_id: WCChat.product_id,
            nonce: WCChat.nonce
        }, function (res) {
            
            $btn.text(originalText).prop('disabled', false);

            if (!res || res.length === 0) {
                window.location.href = window.location.pathname + '?add-to-cart=' + WCChat.product_id;
                return;
            }

            questions = res;
            answers = [];
            current = 0;
            $('#wcq-progress-bar').css('width', '0%');
            $('#wcq-popup').fadeIn();
            $('.wcq-messages').html('');
            showQuestion();
        });
    });
  });

  /* --- INSERT THIS NEW BLOCK --- */
  $(document).on('change', 'input[type="radio"], .wcq-select', function() {
    let nameAttr = $(this).attr('name');
    if (!nameAttr) return;
    
    // Get the index from the name (e.g., wcq_radio_2 becomes 2)
    let changedIndex = parseInt(nameAttr.replace('wcq_radio_', ''));
    
    // If the user is changing an answer to an OLD question
    if (changedIndex < current) {
      // 1. Remove all data in the array from this point forward
      answers = answers.slice(0, changedIndex);
      
      // 2. Move the logic pointer back to this question
      current = changedIndex;
      
      // 3. Remove all chat bubbles that appear AFTER this question
      $(this).closest('.bot').nextAll().remove();
      
      // The user will now have to click "Proceed" again to trigger the NEW logic path
    }
  });
  /* ----------------------------- */

  function showQuestion() {
    $('#wcq-error-msg').hide();
    // console.log("Current Question Object:", questions[current]);
    // console.log("Current Index:", current);
    while (questions[current] && questions[current].condition) {
      let cond = questions[current].condition;
      // console.log("Condition Object:", cond);
      // console.log("Condition.question_index:", cond.question_index);
      // console.log("Condition.equals:", cond.equals);
      let prevAnswer = answers[cond.question_index]?.answer || '';
      // console.log("Previous Answer:", prevAnswer);
      
      // Split the expected answers into an array: ["yes (soft lenses)", "yes (hard lenses)"]
      let expectedAnswers = cond.equals.split(',').map(s => s.trim().toLowerCase());
      // console.log("Expected Answers Array:", expectedAnswers);
      
      // Check if the user's previous answer is included in that array
      /*if (!expectedAnswers.includes(prevAnswer.toLowerCase())) {
          current++; // Skip this question if the answer doesn't match any in the list
      } else {
          break; // Match found, show this question
      }*/
     // Check match
      let match = expectedAnswers.includes(prevAnswer.toLowerCase());
      // console.log("Match? ", match);
      if (!match) {
        // console.log("Skipping question:", current, "because no match.");
        current++;
      } else {
        // console.log("Condition matched → stop at question index:", current);
        break;
      }
    }

    if (!questions[current]) {
      submitAnswers();
      return;
    }

    let q = questions[current];

    let questionText = q.question;
    let questionType = q.type || 'TEXT';
    let options = q.options || [];

    let html = `<div class="bot"><p>${questionText}</p>`;

    if (questionType === 'TEXT') {
      html += `<input type="text" class="wcq-input">`;
    }
    if (questionType === 'RADIO') {
      options.forEach(opt => {
        // Change name="wcq_radio" to name="wcq_radio_${current}"
        html += `<label><input type="radio" name="wcq_radio_${current}" value="${opt}"> ${opt}</label>`;
      });
    }
    if (questionType === 'CHECKBOX') {
      options.forEach(opt => {
        html += `<label><input type="checkbox" value="${opt}"> ${opt}</label>`;
      });
    }
    if (questionType === 'SELECT') {
      html += `<select class="wcq-select"><option value="">Select</option>`;
      options.forEach(opt => { html += `<option value="${opt}">${opt}</option>`; });
      html += `</select>`;
    }
    html += `</div>`;

    $('.wcq-messages').append(html);
    updateProgress();
    $('.wcq-messages').animate({ scrollTop: $('.wcq-messages')[0].scrollHeight }, 500);
  }

  $('#wcq-next').on('click', function () {
    let q = questions[current];
    let questionType = q.type || 'TEXT';
    let answer = '';
    let $currentBot = $('.bot').last();

    $('#wcq-error-msg').hide().text('');

    if (questionType === 'TEXT')     answer = $currentBot.find('.wcq-input').val();
    if (questionType === 'RADIO')    answer = $currentBot.find(`input[name="wcq_radio_${current}"]:checked`).val();
    if (questionType === 'SELECT') {
      answer = $currentBot.find('.wcq-select').val();
    }
    if (questionType === 'CHECKBOX') {
      let vals = [];
      $currentBot.find('input[type=checkbox]:checked').each(function () { vals.push($(this).val()); });
      answer = vals.join(', ');
    }

    if (!answer || answer.trim() === "") {
      $('#wcq-error-msg').text("Please answer the question before continuing.").show();
      return;
    }

    // 1. Check for empty answer (Validation)
    if (!answer || (Array.isArray(answer) && answer.length === 0)) {
      $('#wcq-error-msg').text("Please answer the question before continuing.").show();
      return;
    }

    // This handles "Option A" as ["option a"] and "A, B" as ["a", "b"]
    let userChoices = answer.split(',').map(s => s.trim().toLowerCase());

    // 2. RULE: Patient Alert (Hard Stop)
    if (q.patient_alert_val) {
        let stopValues = q.patient_alert_val.split(',').map(s => s.trim().toLowerCase());

        // Check if ANY of the user's choices are in the stop list
        let hitStop = userChoices.some(choice => stopValues.includes(choice));

        if (hitStop) {
            $('#wcq-error-msg').text(q.patient_alert_msg || "You cannot proceed based on your selection.").show();
            $('.wcq-messages').animate({ scrollTop: $('.wcq-messages')[0].scrollHeight }, 300);
            return;
        }
    }

    // 3. RULE: Can Proceed (Strict Validation)
    if (q.can_proceed) {
        let proceedValues = q.can_proceed.split(',').map(s => s.trim().toLowerCase());
    
        // For Select/Radio: Does the single choice match one of the allowed proceed values?
        // For Checkbox: Did they select the required box(es)?
        let canProceed = proceedValues.some(val => userChoices.includes(val));
    
        if (!canProceed) {
            $('#wcq-error-msg').text("To continue, you must select: " + q.can_proceed).show();
            $('.wcq-messages').animate({ scrollTop: $('.wcq-messages')[0].scrollHeight }, 300);
            return;
        }
    }

    // Handle the 'Admin Alert' (Orange Flag) and move to next question...
    let isFlagged = false;
    let isSafe = false;

    if (q.admin_alert_val) {
      let flagList = q.admin_alert_val.split(',').map(item => item.trim().toLowerCase());
      if (flagList.includes(answer.toLowerCase()) || q.admin_alert_val.toLowerCase() === 'any') {
        isFlagged = true;
      }
    }
    // GREEN (Safe)
    if (q.admin_alert_green_val) {
      let safeList = q.admin_alert_green_val.split(',').map(item => item.trim().toLowerCase());
      if (safeList.includes(answer.toLowerCase()) || q.admin_alert_green_val.toLowerCase() === 'any') {
        isSafe = true;
      }
    }

    if (answers[current]) {
      answers.splice(current); 
    }

    answers.push({
      question: q.question,
      answer: answer,
      flagged: !!isFlagged,
      safe: !!isSafe
    });

    //console.log("Current Saved Data (JSON):", JSON.stringify(answers, null, 2));
    console.table(answers); // This shows a nice readable grid in the console

    let cssClass = 'user';

    if (isFlagged) {
      cssClass += ' alert-orange';
    } else if (isSafe) {
      cssClass += ' alert-green';
    }

    $('.wcq-messages').append(`<div class="${cssClass}">${answer}</div>`);

    current++;

    if (current < questions.length) {
      showQuestion();
    } else {
      submitAnswers();
    }
  });

  function formatAnswer(ans) {
    if (Array.isArray(ans)) return ans.join(', ');
    if (typeof ans === 'object') return JSON.stringify(ans);
    return ans;
  }

  function submitAnswers() {
    $('#wcq-popup').fadeOut();

    $.post(WCChat.ajaxurl, {
      action: 'wcq_store_answers',
      product_id: WCChat.product_id,
      answers: answers,
      nonce: WCChat.nonce
    }, function () {
      // Add product to cart AFTER questionnaire
      window.location.href = WCChat.cart_url + '?add-to-cart=' + WCChat.product_id;
    });
  }
  function getVisibleQuestionCount() {
    let count = 0;
    questions.forEach(q => {
      if (!q.condition) count++;
    });
    return count;
  }
  function updateProgress() {
    let totalVisible = getVisibleQuestionCount();
    let visibleIndex = 0;

    for (let i = 0; i <= current; i++) {
      if (questions[i] && !questions[i].condition) {
        visibleIndex++;
      }
    }
    let percent = totalVisible === 0 ? 100 : Math.round((visibleIndex / totalVisible) * 100);
    $('#wcq-progress-bar').css('width', percent + '%');
  }

  // Close popup when clicking overlay
  $(document).on('click', '#wcq-close', function () {
    if (confirm("Are you sure you want to stop? Your progress will not be saved.")) {
        $('#wcq-popup').fadeOut();
    }
  });
});
