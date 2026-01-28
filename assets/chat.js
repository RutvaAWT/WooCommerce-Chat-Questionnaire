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
    e.preventDefault();

    $.post(WCChat.ajaxurl, {
      action: 'get_wcq_questions',
      product_id: WCChat.product_id,
      nonce: WCChat.nonce
    }, function (res) {

      if (!res || res.length === 0) {
        // safety fallback — should never happen
        window.location.href = WCChat.cart_url + '?add-to-cart=' + WCChat.product_id;
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

  function showQuestion() {
    // Safely skip conditional questions
    while (questions[current] && questions[current].condition) {
      let cond = questions[current].condition;
      let prevAnswer = answers[cond.question_index]?.answer || '';

      if (prevAnswer !== cond.equals) {
        current++;
      } else {
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
        html += `<label><input type="radio" name="wcq_radio" value="${opt}"> ${opt}</label>`;
      });
    }

    if (questionType === 'CHECKBOX') {
      options.forEach(opt => {
        html += `<label><input type="checkbox" value="${opt}"> ${opt}</label>`;
      });
    }

    if (questionType === 'SELECT') {
      html += `<select class="wcq-select"><option value="">Select</option>`;
      options.forEach(opt => {
        html += `<option value="${opt}">${opt}</option>`;
      });
      html += `</select>`;
    }

    html += `</div>`;

    $('.wcq-messages').append(html);
    updateProgress();
  }

  $('#wcq-next').on('click', function () {

    let q = questions[current];
    let questionType = q.type || 'TEXT';
    let questionText = q.question;
    let answer = '';

    if (questionType === 'TEXT') {
      answer = $('.wcq-input').last().val();
    }

    if (questionType === 'RADIO') {
      answer = $('input[name="wcq_radio"]:checked').val();
    }

    if (questionType === 'CHECKBOX') {
      let vals = [];
      $('.bot:last input[type=checkbox]:checked').each(function () {
        vals.push($(this).val());
      });
      answer = vals.join(', ');
    }

    if (questionType === 'SELECT') {
      answer = $('.wcq-select').last().val();
    }

    if (!answer) {
      alert('Please answer the question');
      return;
    }

    let safeAnswer = formatAnswer(answer);
    $('.wcq-messages').append(`<div class="user">${safeAnswer}</div>`);

    answers.push({
      question: questionText,
      answer: safeAnswer
    });

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
  $(document).on('click', '.wcq-overlay', function () {
    $('#wcq-popup').fadeOut();
  });
});