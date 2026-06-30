(function () {
  var API = window.TI_NEO_PUBLIC_SCREEN_API || (window.location.origin + '/ti_neo/api/v1/pantalla_publica.php');
  var state = {
    device: queryParam('device') || '',
    key: queryParam('key') || '',
    timer: null,
    loading: false,
    lastCode: '',
    lastState: '',
    lastUpdatedAt: '',
    errorCount: 0,
    format: '',
    instructions: {
      active: false,
      running: false,
      timer: null,
      audioContext: null,
      currentAudio: null,
      blocked: false,
      enabled: false,
    },
    turnAudio: {
      enabled: false,
      ready: false,
      blocked: false,
      loading: false,
      playing: false,
      queue: [],
      buffers: {},
      seen: {},
      audioContext: null,
      keepAlive: null,
      lastSignature: '',
      currentSignature: '',
      announceTimer: null,
    },
    appointments: {
      manana: [],
      tarde: [],
      pages: { manana: 0, tarde: 0 },
      timer: null,
      signature: '',
      pageSize: 6,
    },
  };

  function $(id) {
    return document.getElementById(id);
  }

  function audioPreferenceKey() {
    return 'tiNeo.publicScreen.audioEnabled.' + (state.device || 'default');
  }

  function turnAudioPreferenceKey() {
    return 'tiNeo.publicScreen.turnAudioEnabled.' + (state.device || 'default');
  }

  function loadAudioPreference() {
    try {
      state.instructions.enabled = window.localStorage.getItem(audioPreferenceKey()) === '1';
    } catch (error) {
      state.instructions.enabled = false;
    }
  }

  function loadTurnAudioPreference() {
    try {
      state.turnAudio.enabled = window.localStorage.getItem(turnAudioPreferenceKey()) === '1';
    } catch (error) {
      state.turnAudio.enabled = false;
    }
  }

  function saveAudioPreference(enabled) {
    try {
      if (enabled) {
        window.localStorage.setItem(audioPreferenceKey(), '1');
      } else {
        window.localStorage.removeItem(audioPreferenceKey());
      }
    } catch (error) {}
  }

  function saveTurnAudioPreference(enabled) {
    try {
      if (enabled) {
        window.localStorage.setItem(turnAudioPreferenceKey(), '1');
      } else {
        window.localStorage.removeItem(turnAudioPreferenceKey());
      }
    } catch (error) {}
  }

  function queryParam(name) {
    var query = window.location.search ? window.location.search.substring(1).split('&') : [];
    var index;
    var part;
    var key;
    var value;
    for (index = 0; index < query.length; index += 1) {
      part = query[index].split('=');
      key = decodeURIComponent((part[0] || '').replace(/\+/g, ' '));
      if (key === name) {
        value = part.slice(1).join('=');
        return decodeURIComponent((value || '').replace(/\+/g, ' '));
      }
    }
    return '';
  }

  function queueStateLabel(value) {
    var stateName = String(value || '').toLowerCase();
    var labels = {
      llamando: 'Llamando',
      atendiendo: 'Atendiendo',
      ausente: 'Ausente',
    };
    return labels[stateName] || 'Sin llamado activo';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function buildApiUrl() {
    var separator = API.indexOf('?') === -1 ? '?' : '&';
    return API + separator
      + 'device=' + encodeURIComponent(state.device)
      + '&key=' + encodeURIComponent(state.key)
      + '&_=' + String(Date.now());
  }

  function loadScreen() {
    if (state.loading) {
      return;
    }

    if (!state.device || !state.key) {
      renderScreenError('Pantalla sin configurar.');
      scheduleNext(30000);
      return;
    }

    state.loading = true;
    requestJson(buildApiUrl(), function (error, payload) {
      if (error) {
        state.errorCount += 1;
        renderScreenError(error.message || 'Reconectando pantalla...');
        scheduleNext(Math.min(60000, 15000 * state.errorCount));
        state.loading = false;
        return;
      }

      try {
        var data = payload.data || {};
        var polling = data.polling || {};
        state.errorCount = 0;

        var format = normalizeFormat(data.device && data.device.formato);
        if (!isSupportedFormat(format)) {
          renderScreenError('Formato de pantalla no disponible.');
          renderDeviceMessage(data.device || {});
          scheduleNext(Number(polling.idle_ms || 12000));
          state.loading = false;
          return;
        }

        applyScreenFormat(format);
        if (isAppointmentsFormat(format)) {
          renderAppointments((data.screen || {}).citas || {});
        } else if (isMediaFormat(format)) {
          renderMediaScreen(data.device || {});
        } else {
          renderScreen(data.screen || {});
        }
        renderDeviceMessage(data.device || {});
        scheduleNext(Number(polling.normal_ms || 5000));
      } catch (exception) {
        state.errorCount += 1;
        renderScreenError(exception.message || 'Reconectando pantalla...');
        scheduleNext(Math.min(60000, 15000 * state.errorCount));
      } finally {
        state.loading = false;
      }
    });
  }

  function requestJson(url, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onreadystatechange = function () {
      var payload;
      if (xhr.readyState !== 4) {
        return;
      }

      try {
        payload = JSON.parse(xhr.responseText || '{}');
      } catch (error) {
        callback(new Error('Respuesta inválida de la API.'), null);
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300 || payload.success === false) {
        callback(new Error(payload.message || 'No se pudo actualizar la pantalla.'), null);
        return;
      }

      callback(null, payload);
    };
    xhr.onerror = function () {
      callback(new Error('No se pudo conectar con la API de pantalla.'), null);
    };
    xhr.send(null);
  }

  function renderScreen(data) {
    var calls = Array.isArray(data.llamados) ? data.llamados : [];
    var displayCalls = orderCallsForTurnAudio(calls, data.actualizado_en || '');
    var current = calls[0] || null;
    var currentCode = current && current.codigo ? current.codigo : '';
    var currentState = current && current.cola_estado ? current.cola_estado : '';
    var updatedAt = data.actualizado_en || '';
    var hadPreviousUpdate = Boolean(state.lastUpdatedAt);
    var changed = Boolean(currentCode && (
      currentCode !== state.lastCode
      || currentState !== state.lastState
      || updatedAt !== state.lastUpdatedAt
    ));
    var dateLabel = $('screenOperationalDate');
    var container = $('screenRecentList');

    if (dateLabel) {
      dateLabel.textContent = 'Fecha operativa: ' + (data.fecha || currentDateValue());
    }

    updateScreenStateClasses(currentState, changed);
    state.lastCode = currentCode;
    state.lastState = currentState;
    state.lastUpdatedAt = updatedAt;

    if (!container) {
      maybeQueueTurnAudio(calls, changed, updatedAt, hadPreviousUpdate);
      return;
    }

    updateRecentListDensity(container, displayCalls.length);
    container.innerHTML = '';
    if (!calls.length) {
      container.innerHTML = '<p class="empty-state">Sin llamados activos.</p>';
      maybeQueueTurnAudio(calls, changed, updatedAt, hadPreviousUpdate);
      return;
    }

    displayCalls.forEach(function (ticket, index) {
      var windowNumber = ticket.ventanilla_numero || '-';
      var itemSignature = turnAudioSignature(ticket, updatedAt);
      var item = document.createElement('article');
      item.className = 'screen-recent-item screen-state-' + (ticket.cola_estado || 'idle')
        + (index === 0 ? ' is-current' : '')
        + (itemSignature === state.turnAudio.currentSignature ? ' is-announcing' : '');
      item.setAttribute('data-turn-audio-signature', itemSignature);
      item.innerHTML = ''
        + '<strong>' + escapeHtml(ticket.codigo) + '</strong>'
        + '<span class="screen-recent-arrow" aria-hidden="true">&rarr;</span>'
        + '<span class="screen-recent-target">'
        + '<span class="screen-recent-window">V' + escapeHtml(windowNumber) + '</span>'
        + '<small>' + escapeHtml(queueStateLabel(ticket.cola_estado)) + '</small>'
        + '</span>';
      container.appendChild(item);
    });
    window.setTimeout(function () {
      maybeQueueTurnAudio(calls, changed, updatedAt, hadPreviousUpdate);
    }, 80);
  }

  function updateRecentListDensity(container, total) {
    container.classList.toggle('is-compact', total >= 7);
    container.classList.toggle('is-max', total >= 9);
  }

  function orderCallsForTurnAudio(calls, updatedAt) {
    var currentSignature = state.turnAudio.currentSignature || '';
    var active = [];
    var rest = [];

    if (!currentSignature) {
      return calls;
    }

    calls.forEach(function (ticket) {
      if (turnAudioSignature(ticket, updatedAt) === currentSignature) {
        active.push(ticket);
        return;
      }
      rest.push(ticket);
    });

    return active.concat(rest);
  }

  function renderDeviceMessage(device) {
    var ticker = $('screenTickerText');
    var message = (device && device.mensaje)
      ? device.mensaje
      : 'Hospital San José del Callao - Mantenga su DNI a la mano y acérquese cuando vea su turno en pantalla.';
    if (ticker) {
      ticker.textContent = message;
    }
  }

  function normalizeFormat(format) {
    var value = String(format || 'turnos_clasico').toLowerCase();
    if (value === 'turnero' || value === '') {
      return 'turnos_clasico';
    }
    return value;
  }

  function isSupportedFormat(format) {
    return ['turnos_clasico', 'citas_mes_doctor', 'citas_mes_doctor_limpio', 'citas_mes_doctor_aviso', 'imagen_url'].indexOf(normalizeFormat(format)) !== -1;
  }

  function isAppointmentsFormat(format) {
    return ['citas_mes_doctor', 'citas_mes_doctor_limpio', 'citas_mes_doctor_aviso'].indexOf(normalizeFormat(format)) !== -1;
  }

  function isCleanAppointmentsFormat(format) {
    return ['citas_mes_doctor_limpio', 'citas_mes_doctor_aviso'].indexOf(normalizeFormat(format)) !== -1;
  }

  function isNoticeAppointmentsFormat(format) {
    return normalizeFormat(format) === 'citas_mes_doctor_aviso';
  }

  function isMediaFormat(format) {
    return normalizeFormat(format) === 'imagen_url';
  }

  function applyScreenFormat(format) {
    var normalized = normalizeFormat(format);
    var turnero = $('turneroScreen');
    var ticker = document.querySelector('.screen-ticker');
    var appointments = $('appointmentsScreen');
    var media = $('mediaScreen');
    var isAppointments = isAppointmentsFormat(normalized);
    var isMedia = isMediaFormat(normalized);
    var isCleanAppointments = isCleanAppointmentsFormat(normalized);
    var isNoticeAppointments = isNoticeAppointmentsFormat(normalized);
    document.body.classList.toggle('screen-format-appointments', isAppointments);
    document.body.classList.toggle('screen-format-appointments-clean', isCleanAppointments);
    document.body.classList.toggle('screen-format-appointments-notice', isNoticeAppointments);
    document.body.classList.toggle('screen-format-media', isMedia);
    document.body.classList.toggle('screen-format-turns', !isAppointments && !isMedia);
    if (turnero) {
      turnero.hidden = isAppointments || isMedia;
    }
    if (ticker) {
      ticker.hidden = false;
    }
    if (appointments) {
      appointments.hidden = !isAppointments;
    }
    if (media) {
      media.hidden = !isMedia;
    }
    state.format = normalized;
    updateTurnAudioButton();
    state.appointments.pageSize = isNoticeAppointments ? 5 : (isCleanAppointments ? 8 : 6);
    hydrateAppointmentsFlowGuide(normalized);
    toggleAppointmentsExtras(!isCleanAppointments || isNoticeAppointments, isNoticeAppointments);
    if (!isAppointments) {
      stopAppointmentsPager();
      stopInstructionAudio();
    } else if (isCleanAppointments && !isNoticeAppointments) {
      stopInstructionAudio();
    } else {
      updateAudioButton();
      if (state.instructions.enabled && !state.instructions.blocked) {
        startInstructionAudio(false);
      }
    }
  }

  function toggleAppointmentsExtras(showGuide, showAudio) {
    var guide = $('appointmentsFlowGuide');
    var button = $('screenAudioButton');
    if (guide) {
      guide.hidden = !showGuide;
    }
    if (!showAudio && button) {
      button.hidden = true;
    }
  }

  function renderMediaScreen(device) {
    var container = $('mediaScreen');
    var image = $('mediaScreenImage');
    var empty = $('mediaScreenEmpty');
    var url = String((device && device.media_url) || '').trim();
    if (!image || !empty) {
      return;
    }

    if (url) {
      image.hidden = false;
      image.src = url;
      if (container) {
        container.style.setProperty('--media-screen-image', 'url("' + url.replace(/"/g, '\\"') + '")');
      }
      empty.hidden = true;
    } else {
      image.hidden = true;
      image.removeAttribute('src');
      if (container) {
        container.style.removeProperty('--media-screen-image');
      }
      empty.hidden = false;
    }
  }

  function renderAppointments(data) {
    var title = $('appointmentsTitle');
    var manana = Array.isArray(data.manana) ? data.manana : [];
    var tarde = Array.isArray(data.tarde) ? data.tarde : [];
    var signature = JSON.stringify({
      titulo: data.titulo || '',
      manana: manana,
      tarde: tarde,
    });

    if (title) {
      title.textContent = String(data.titulo || 'Citas del mes').toUpperCase();
    }

    if (signature !== state.appointments.signature) {
      state.appointments.manana = manana;
      state.appointments.tarde = tarde;
      state.appointments.pages.manana = 0;
      state.appointments.pages.tarde = 0;
      state.appointments.signature = signature;
    }

    renderAppointmentPage('manana');
    renderAppointmentPage('tarde');
    startAppointmentsPager();
  }

  function renderAppointmentPage(shift) {
    var containerId = shift === 'manana' ? 'appointmentsMorningList' : 'appointmentsAfternoonList';
    var rows = state.appointments[shift] || [];
    var container = $(containerId);
    var pageSize = state.appointments.pageSize;
    var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
    var currentPage = Math.min(state.appointments.pages[shift] || 0, totalPages - 1);
    var start = currentPage * pageSize;
    var pageRows = rows.slice(start, start + pageSize);
    if (!container) {
      return;
    }

    container.innerHTML = '';
    if (!rows.length) {
      container.innerHTML = '<p class="appointments-empty">Sin citas disponibles.</p>';
      return;
    }

    pageRows.forEach(function (row) {
      var item = document.createElement('article');
      var cupos = Number(row.cupos || 0);
      item.className = 'appointments-row' + (cupos <= 0 ? ' is-empty' : '');
      item.innerHTML = ''
        + '<strong>' + escapeHtml(row.especialidad || '-') + '</strong>'
        + '<span>' + escapeHtml(row.doctor || '-') + '</span>'
        + '<b>' + escapeHtml(cupos) + '</b>';
      container.appendChild(item);
    });

    renderAppointmentDots(container, shift, totalPages, currentPage);
  }

  function renderAppointmentDots(container, shift, totalPages, currentPage) {
    var dots = document.createElement('div');
    var index;
    dots.className = 'appointments-pager';
    if (totalPages <= 1) {
      return;
    }
    for (index = 0; index < totalPages; index += 1) {
      dots.innerHTML += '<span class="' + (index === currentPage ? 'active' : '') + '" aria-label="' + escapeHtml(shift) + ' pagina ' + String(index + 1) + '"></span>';
    }
    container.appendChild(dots);
  }

  function startAppointmentsPager() {
    if (state.appointments.timer) {
      return;
    }
    state.appointments.timer = window.setInterval(advanceAppointmentsPages, 15000);
  }

  function stopAppointmentsPager() {
    if (state.appointments.timer) {
      window.clearInterval(state.appointments.timer);
      state.appointments.timer = null;
    }
  }

  function advanceAppointmentsPages() {
    if (!isAppointmentsFormat(state.format)) {
      stopAppointmentsPager();
      return;
    }
    advanceAppointmentShift('manana');
    advanceAppointmentShift('tarde');
    renderAppointmentPage('manana');
    renderAppointmentPage('tarde');
  }

  function advanceAppointmentShift(shift) {
    var rows = state.appointments[shift] || [];
    var totalPages = Math.max(1, Math.ceil(rows.length / state.appointments.pageSize));
    state.appointments.pages[shift] = ((state.appointments.pages[shift] || 0) + 1) % totalPages;
  }

  function instructionSteps() {
    return [
      {
        text: 'Paso uno: tenga su DNI a la mano.',
        audio: 'assets/audio/cita_paso_1.wav',
      },
      {
        text: 'Paso dos: retire su ticket en Puerta cuatro.',
        audio: 'assets/audio/cita_paso_2.wav',
      },
      {
        text: 'Paso tres: dirijase a admision, revise la pantalla y espere su turno.',
        audio: 'assets/audio/cita_paso_3.wav',
      },
      {
        text: 'Paso cuatro: cuando vea su turno, vaya a ventanilla con su DNI y ticket.',
        audio: 'assets/audio/cita_paso_4.wav',
      },
    ];
  }

  function startInstructionAudio(force) {
    if (!state.instructions.enabled) {
      updateAudioButton();
      return;
    }
    if (state.instructions.blocked && !force) {
      updateAudioButton();
      return;
    }
    if (state.instructions.active) {
      return;
    }

    state.instructions.active = true;
    state.instructions.blocked = false;
    updateAudioButton();
    runInstructionLoop();
  }

  function stopInstructionAudio() {
    state.instructions.active = false;
    state.instructions.running = false;
    if (state.instructions.timer) {
      window.clearTimeout(state.instructions.timer);
      state.instructions.timer = null;
    }
    if (state.instructions.currentAudio) {
      state.instructions.currentAudio.pause();
      state.instructions.currentAudio = null;
    }
    if (window.speechSynthesis) {
      window.speechSynthesis.cancel();
    }
    updateAudioButton();
  }

  function runInstructionLoop() {
    if (!state.instructions.active || state.instructions.running || (state.format !== 'citas_mes_doctor' && state.format !== 'citas_mes_doctor_aviso')) {
      return;
    }

    state.instructions.running = true;
    playInstructionSequence();
  }

  function playLoopingInstructionAudio(source) {
    var audio;

    try {
      if (state.instructions.currentAudio) {
        state.instructions.currentAudio.pause();
      }
      audio = new Audio(source);
      state.instructions.currentAudio = audio;
      audio.preload = 'auto';
      audio.loop = true;
      audio.volume = 1;
      audio.onplaying = function () {
        state.instructions.blocked = false;
        state.instructions.running = true;
        updateAudioButton();
      };
      audio.onerror = function () {
        state.instructions.currentAudio = null;
        state.instructions.active = false;
        state.instructions.running = false;
        state.instructions.blocked = true;
        updateAudioButton();
      };
      audio.load();
      audio.play().catch(function () {
        state.instructions.currentAudio = null;
        state.instructions.active = false;
        state.instructions.running = false;
        state.instructions.blocked = true;
        updateAudioButton();
      });
    } catch (error) {
      state.instructions.currentAudio = null;
      state.instructions.active = false;
      state.instructions.running = false;
      state.instructions.blocked = true;
      updateAudioButton();
    }
  }

  function playInstructionSequence() {
    var audio;
    var completed = false;
    var fallback;
    var retryTimer;
    var attempt = 0;
    var maxAttempts = 3;
    var noticeText = 'Recuerde tener su DNI a la mano. Si no conoce su especialidad, por favor pregunte al personal de chaleco mostaza.';

    if (state.format === 'citas_mes_doctor_aviso') {
      playLoopingInstructionAudio('assets/audio/citas_aviso_rotativo_loop.wav?v=20260617-aviso-rotativo-2min');
      return;
    }

    if (state.format === 'citas_mes_doctor') {
      playLoopingInstructionAudio('assets/audio/cita_pasos_loop.wav?v=20260616-pasos-bella');
      return;
    }

    function finish() {
      if (completed) {
        return;
      }
      completed = true;
      if (fallback) {
        window.clearTimeout(fallback);
      }
      if (retryTimer) {
        window.clearTimeout(retryTimer);
      }
      state.instructions.currentAudio = null;
      state.instructions.blocked = false;
      state.instructions.running = false;
      updateAudioButton();
      if (state.instructions.active && (state.format === 'citas_mes_doctor' || state.format === 'citas_mes_doctor_aviso')) {
        state.instructions.timer = window.setTimeout(runInstructionLoop, state.format === 'citas_mes_doctor_aviso' ? 60000 : 15000);
      }
    }

    function finishNoticeRetry() {
      if (completed) {
        return;
      }
      completed = true;
      if (fallback) {
        window.clearTimeout(fallback);
      }
      if (retryTimer) {
        window.clearTimeout(retryTimer);
      }
      if (state.instructions.currentAudio) {
        state.instructions.currentAudio.pause();
      }
      state.instructions.currentAudio = null;
      state.instructions.blocked = false;
      state.instructions.running = false;
      updateAudioButton();
      if (state.instructions.active && state.format === 'citas_mes_doctor_aviso') {
        state.instructions.timer = window.setTimeout(runInstructionLoop, 60000);
      }
    }

    function failInstructionAudio() {
      if (completed) {
        return;
      }
      if (state.format === 'citas_mes_doctor_aviso') {
        if (fallback) {
          window.clearTimeout(fallback);
        }
        if (attempt < maxAttempts && state.instructions.active) {
          retryTimer = window.setTimeout(playNoticeAudioAttempt, 3000);
          return;
        }
        speakText(noticeText, finishNoticeRetry);
        return;
      }
      completed = true;
      if (fallback) {
        window.clearTimeout(fallback);
      }
      if (state.instructions.currentAudio) {
        state.instructions.currentAudio.pause();
      }
      state.instructions.currentAudio = null;
      state.instructions.active = false;
      state.instructions.running = false;
      state.instructions.blocked = true;
      updateAudioButton();
    }

    function playNoticeAudioAttempt() {
      if (completed || !state.instructions.active || state.format !== 'citas_mes_doctor_aviso') {
        finishNoticeRetry();
        return;
      }
      attempt += 1;
      if (fallback) {
        window.clearTimeout(fallback);
      }
      try {
        if (state.instructions.currentAudio) {
          state.instructions.currentAudio.pause();
        }
        audio = new Audio('assets/audio/citas_aviso_dni_especialidad.wav');
        state.instructions.currentAudio = audio;
        audio.preload = 'auto';
        audio.volume = 1;
        audio.onended = finish;
        audio.onerror = failInstructionAudio;
        fallback = window.setTimeout(failInstructionAudio, 12000);
        audio.load();
        audio.play().catch(function () {
          failInstructionAudio();
        });
      } catch (error) {
        failInstructionAudio();
      }
    }

    if (state.format === 'citas_mes_doctor_aviso') {
      playNoticeAudioAttempt();
      return;
    }

    try {
      if (state.instructions.currentAudio) {
        state.instructions.currentAudio.pause();
      }
      audio = new Audio('assets/audio/cita_instrucciones.wav');
      state.instructions.currentAudio = audio;
      audio.preload = 'auto';
      audio.volume = 1;
      audio.onended = finish;
      audio.onerror = failInstructionAudio;
      fallback = window.setTimeout(failInstructionAudio, 45000);
      audio.play().catch(function () {
        failInstructionAudio();
      });
    } catch (error) {
      failInstructionAudio();
    }
  }

  function updateAudioButton() {
    var button = $('screenAudioButton');
    if (!button) {
      return;
    }
    button.hidden = (state.format !== 'citas_mes_doctor' && state.format !== 'citas_mes_doctor_aviso') || (state.instructions.enabled && state.instructions.active && !state.instructions.blocked);
    button.textContent = state.instructions.blocked ? 'Reactivar audio' : 'Activar audio';
  }

  function enableInstructionAudio() {
    state.instructions.enabled = true;
    state.instructions.blocked = false;
    saveAudioPreference(true);
    updateAudioButton();
    startInstructionAudio(true);
  }

  function turnAudioFiles() {
    return {
      campana: 'assets/audio/turnero/campana.wav?v=20260617',
      ticket: 'assets/audio/turnero/ticket.wav?v=20260617',
      acercarse: 'assets/audio/turnero/acercarse.wav?v=20260617',
      p: 'assets/audio/turnero/p.wav?v=20260617',
      n: 'assets/audio/turnero/n.wav?v=20260617-n-corto',
      pr: 'assets/audio/turnero/pr.wav?v=20260617',
      v: 'assets/audio/turnero/v.wav?v=20260617',
      n0: 'assets/audio/turnero/n0.wav?v=20260617',
      n1: 'assets/audio/turnero/n1.wav?v=20260617',
      n2: 'assets/audio/turnero/n2.wav?v=20260617',
      n3: 'assets/audio/turnero/n3.wav?v=20260617',
      n4: 'assets/audio/turnero/n4.wav?v=20260617',
      n5: 'assets/audio/turnero/n5.wav?v=20260617',
      n6: 'assets/audio/turnero/n6.wav?v=20260617',
      n7: 'assets/audio/turnero/n7.wav?v=20260617',
      n8: 'assets/audio/turnero/n8.wav?v=20260617',
      n9: 'assets/audio/turnero/n9.wav?v=20260617',
      ventanilla_1: 'assets/audio/turnero/ventanilla_1.wav?v=20260617',
      ventanilla_2: 'assets/audio/turnero/ventanilla_2.wav?v=20260617',
      ventanilla_3: 'assets/audio/turnero/ventanilla_3.wav?v=20260617',
      ventanilla_4: 'assets/audio/turnero/ventanilla_4.wav?v=20260617',
      ventanilla_5: 'assets/audio/turnero/ventanilla_5.wav?v=20260617',
      ventanilla_6: 'assets/audio/turnero/ventanilla_6.wav?v=20260617',
      ventanilla_7: 'assets/audio/turnero/ventanilla_7.wav?v=20260617',
      ventanilla_8: 'assets/audio/turnero/ventanilla_8.wav?v=20260617',
      ventanilla_9: 'assets/audio/turnero/ventanilla_9.wav?v=20260617',
    };
  }

  function updateTurnAudioButton() {
    var button = $('turneroAudioButton');
    if (!button) {
      return;
    }
    button.hidden = state.format !== 'turnos_clasico' || (state.turnAudio.enabled && state.turnAudio.ready && !state.turnAudio.blocked);
    button.textContent = state.turnAudio.blocked ? 'Reactivar audio' : 'Activar audio';
  }

  function enableTurnAudio() {
    state.turnAudio.enabled = true;
    state.turnAudio.blocked = false;
    saveTurnAudioPreference(true);
    updateTurnAudioButton();
    setupTurnAudio();
  }

  function setupTurnAudio() {
    var AudioContextClass = window.AudioContext || window.webkitAudioContext;
    var context;
    if (!AudioContextClass) {
      state.turnAudio.blocked = true;
      state.turnAudio.ready = false;
      updateTurnAudioButton();
      return;
    }
    if (state.turnAudio.loading) {
      return;
    }
    state.turnAudio.loading = true;
    try {
      context = state.turnAudio.audioContext || new AudioContextClass();
      state.turnAudio.audioContext = context;
      Promise.resolve(context.resume ? context.resume() : null)
        .then(function () {
          keepTurnAudioAlive();
          return loadTurnAudioBuffers();
        })
        .then(function () {
          state.turnAudio.ready = true;
          state.turnAudio.blocked = false;
          state.turnAudio.loading = false;
          updateTurnAudioButton();
          playTurnAudioQueue();
        })
        .catch(function () {
          state.turnAudio.ready = false;
          state.turnAudio.blocked = true;
          state.turnAudio.loading = false;
          updateTurnAudioButton();
        });
    } catch (error) {
      state.turnAudio.ready = false;
      state.turnAudio.blocked = true;
      state.turnAudio.loading = false;
      updateTurnAudioButton();
    }
  }

  function keepTurnAudioAlive() {
    var context = state.turnAudio.audioContext;
    var oscillator;
    var gain;
    if (!context || state.turnAudio.keepAlive) {
      return;
    }
    try {
      oscillator = context.createOscillator();
      gain = context.createGain();
      gain.gain.value = 0.00001;
      oscillator.frequency.value = 20;
      oscillator.connect(gain);
      gain.connect(context.destination);
      oscillator.start();
      state.turnAudio.keepAlive = oscillator;
    } catch (error) {}
  }

  function loadTurnAudioBuffers() {
    var files = turnAudioFiles();
    var names = Object.keys(files);
    var context = state.turnAudio.audioContext;
    if (!context) {
      return Promise.reject(new Error('Audio no disponible'));
    }
    return Promise.all(names.map(function (name) {
      if (state.turnAudio.buffers[name]) {
        return Promise.resolve();
      }
      return requestAudioBuffer(files[name])
        .then(function (buffer) {
          return new Promise(function (resolve, reject) {
            context.decodeAudioData(buffer.slice(0), function (decoded) {
              state.turnAudio.buffers[name] = decoded;
              resolve();
            }, reject);
          });
        });
    }));
  }

  function requestAudioBuffer(url) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', url, true);
      xhr.responseType = 'arraybuffer';
      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300 && xhr.response) {
          resolve(xhr.response);
          return;
        }
        reject(new Error('No se pudo cargar audio.'));
      };
      xhr.onerror = function () {
        reject(new Error('No se pudo cargar audio.'));
      };
      xhr.send(null);
    });
  }

  function turnTicketSequence(ticket) {
    var code = String((ticket && ticket.codigo) || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    var windowNumber = String((ticket && ticket.ventanilla_numero) || '').replace(/\D/g, '');
    var parts = ['campana', 'ticket'];
    var i;

    for (i = 0; i < code.length; i += 1) {
      if (code.slice(i, i + 2) === 'pr') {
        parts.push('pr');
        i += 1;
        continue;
      }
      if (code[i] === 'p') {
        parts.push('p');
      } else if (code[i] === 'n') {
        parts.push('n');
      } else if (/[0-9]/.test(code[i])) {
        parts.push('n' + code[i]);
      }
    }

    if (windowNumber.length === 1 && state.turnAudio.buffers['ventanilla_' + windowNumber]) {
      parts.push('ventanilla_' + windowNumber);
    } else {
      parts.push('acercarse');
      for (i = 0; i < windowNumber.length; i += 1) {
        parts.push('n' + windowNumber[i]);
      }
    }

    return parts;
  }

  function turnAudioSignature(ticket, updatedAt) {
    return [
      ticket && ticket.id ? ticket.id : '',
      ticket && ticket.codigo ? ticket.codigo : '',
      ticket && ticket.ventanilla_numero ? ticket.ventanilla_numero : '',
      ticket && ticket.asignado_en ? ticket.asignado_en : (updatedAt || ''),
    ].join('|');
  }

  function rememberVisibleTurnCalls(calls, updatedAt) {
    calls.forEach(function (ticket) {
      var signature;
      if (!ticket || ticket.cola_estado !== 'llamando') {
        return;
      }
      signature = turnAudioSignature(ticket, updatedAt);
      state.turnAudio.seen[signature] = true;
    });
  }

  function maybeQueueTurnAudio(calls, changed, updatedAt, hadPreviousUpdate) {
    var pending = [];
    var signature;
    if (state.format !== 'turnos_clasico') {
      return;
    }
    if (!hadPreviousUpdate || !state.turnAudio.enabled || !state.turnAudio.ready || state.turnAudio.blocked || !changed) {
      rememberVisibleTurnCalls(calls, updatedAt);
      return;
    }
    calls.forEach(function (ticket) {
      if (!ticket || ticket.cola_estado !== 'llamando') {
        return;
      }
      signature = turnAudioSignature(ticket, updatedAt);
      if (state.turnAudio.seen[signature]) {
        return;
      }
      state.turnAudio.seen[signature] = true;
      pending.push(ticket);
    });
    pending.slice(0, 10).forEach(function (ticket) {
      state.turnAudio.queue.push({
        signature: turnAudioSignature(ticket, updatedAt),
        sequence: turnTicketSequence(ticket),
      });
    });
    if (state.turnAudio.queue.length > 10) {
      state.turnAudio.queue = state.turnAudio.queue.slice(-10);
    }
    playTurnAudioQueue();
  }

  function playTurnAudioQueue() {
    var item;
    if (!state.turnAudio.ready || state.turnAudio.playing || !state.turnAudio.queue.length) {
      return;
    }
    item = state.turnAudio.queue.shift();
    state.turnAudio.playing = true;
    state.turnAudio.currentSignature = item.signature || '';
    applyTurnAudioAnnouncementSignature();
    playTurnAudioSequence(item.sequence || [], 0, function () {
      state.turnAudio.playing = false;
      state.turnAudio.currentSignature = '';
      applyTurnAudioAnnouncementSignature();
      playTurnAudioQueue();
    });
  }

  function applyTurnAudioAnnouncementSignature() {
    var signature = state.turnAudio.currentSignature;
    var mediaPanel = document.querySelector('.screen-media-panel');
    document.querySelectorAll('.screen-recent-item.is-announcing').forEach(function (item) {
      if (item.getAttribute('data-turn-audio-signature') !== signature) {
        item.classList.remove('is-announcing');
      }
    });
    if (signature) {
      document.querySelectorAll('[data-turn-audio-signature="' + signature.replace(/"/g, '\\"') + '"]').forEach(function (item) {
        item.classList.add('is-announcing');
        if (item.parentElement) {
          item.parentElement.prepend(item);
        }
      });
      if (mediaPanel) {
        mediaPanel.classList.add('is-announcing');
      }
    } else if (mediaPanel) {
      mediaPanel.classList.remove('is-announcing');
    }
  }

  function playTurnAudioSequence(sequence, index, done) {
    var key;
    var buffer;
    var context = state.turnAudio.audioContext;
    var source;
    if (!context || index >= sequence.length) {
      done();
      return;
    }
    key = sequence[index];
    if (key === 'pause') {
      window.setTimeout(function () {
        playTurnAudioSequence(sequence, index + 1, done);
      }, 900);
      return;
    }
    buffer = state.turnAudio.buffers[key];
    if (!buffer) {
      playTurnAudioSequence(sequence, index + 1, done);
      return;
    }
    try {
      source = context.createBufferSource();
      source.buffer = buffer;
      source.connect(context.destination);
      source.onended = function () {
        window.setTimeout(function () {
          playTurnAudioSequence(sequence, index + 1, done);
        }, 90);
      };
      source.start(0);
    } catch (error) {
      state.turnAudio.blocked = true;
      updateTurnAudioButton();
      done();
    }
  }

  function speakInstructionStep(index) {
    var steps = instructionSteps();
    if (!state.instructions.active || state.format !== 'citas_mes_doctor') {
      state.instructions.running = false;
      return;
    }

    if (index >= steps.length) {
      state.instructions.running = false;
      state.instructions.timer = window.setTimeout(runInstructionLoop, 15000);
      return;
    }

    playInstructionBell();
    playInstructionAudio(steps[index], function () {
      state.instructions.timer = window.setTimeout(function () {
        speakInstructionStep(index + 1);
      }, 2000);
    });
  }

  function playInstructionAudio(step, done) {
    var audio;
    var completed = false;
    var fallback;

    function finish() {
      if (completed) {
        return;
      }
      completed = true;
      if (fallback) {
        window.clearTimeout(fallback);
      }
      done();
    }

    if (!step || !step.audio) {
      speakText(step && step.text ? step.text : '', finish);
      return;
    }

    try {
      if (state.instructions.currentAudio) {
        state.instructions.currentAudio.pause();
      }
      audio = new Audio(step.audio);
      state.instructions.currentAudio = audio;
      audio.preload = 'auto';
      audio.volume = 1;
      audio.onended = function () {
        state.instructions.currentAudio = null;
        finish();
      };
      audio.onerror = function () {
        state.instructions.currentAudio = null;
        speakText(step.text || '', finish);
      };
      fallback = window.setTimeout(function () {
        speakText(step.text || '', finish);
      }, 8000);
      audio.play().catch(function () {
        state.instructions.blocked = true;
        speakText(step.text || '', finish);
      });
    } catch (error) {
      state.instructions.blocked = true;
      speakText(step.text || '', finish);
    }
  }

  function speakText(text, done) {
    var utterance;
    var completed = false;
    var fallback;

    function finish() {
      if (completed) {
        return;
      }
      completed = true;
      if (fallback) {
        window.clearTimeout(fallback);
      }
      done();
    }

    if (!window.speechSynthesis || typeof window.SpeechSynthesisUtterance === 'undefined') {
      state.instructions.blocked = true;
      window.setTimeout(finish, 1000);
      return;
    }

    try {
      window.speechSynthesis.cancel();
      utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = 'es-PE';
      utterance.rate = 0.9;
      utterance.pitch = 1;
      utterance.volume = 0.95;
      utterance.onend = finish;
      utterance.onerror = finish;
      window.speechSynthesis.speak(utterance);
      fallback = window.setTimeout(finish, Math.max(2500, text.length * 80));
    } catch (error) {
      state.instructions.blocked = true;
      window.setTimeout(finish, 1000);
    }
  }

  function playInstructionBell() {
    var AudioContextClass = window.AudioContext || window.webkitAudioContext;
    var context;
    var gain;
    var osc;

    if (!AudioContextClass) {
      return;
    }

    try {
      context = state.instructions.audioContext || new AudioContextClass();
      state.instructions.audioContext = context;
      if (context.state === 'suspended' && context.resume) {
        context.resume().catch(function () {});
      }

      gain = context.createGain();
      osc = context.createOscillator();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, context.currentTime);
      osc.frequency.exponentialRampToValueAtTime(1320, context.currentTime + 0.11);
      gain.gain.setValueAtTime(0.0001, context.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.12, context.currentTime + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.22);
      osc.connect(gain);
      gain.connect(context.destination);
      osc.start(context.currentTime);
      osc.stop(context.currentTime + 0.24);
    } catch (error) {
      state.instructions.blocked = true;
    }
  }

  function renderScreenError(message) {
    var container = $('screenRecentList');
    if (container) {
      container.innerHTML = '<p class="empty-state">' + escapeHtml(message) + '</p>';
    }
  }

  function updateScreenStateClasses(currentState, flash) {
    var currentCard = document.querySelector('.screen-current');
    var mediaPanel = document.querySelector('.screen-media-panel');
    var states = ['llamando', 'atendiendo', 'ausente', 'idle'];
    var stateClass = currentState || 'idle';

    states.forEach(function (stateName) {
      if (currentCard) {
        currentCard.classList.remove('screen-state-' + stateName);
      }
      if (mediaPanel) {
        mediaPanel.classList.remove('screen-state-' + stateName);
      }
    });

    if (currentCard) {
      currentCard.classList.add('screen-state-' + stateClass);
    }
    if (mediaPanel) {
      mediaPanel.classList.add('screen-state-' + stateClass);
    }

    if (!flash || !currentCard || !mediaPanel) {
      return;
    }

    currentCard.classList.remove('is-flashing');
    mediaPanel.classList.remove('is-flashing');
  }

  function currentDateValue() {
    var today = new Date();
    var year = today.getFullYear();
    var month = String(today.getMonth() + 1);
    var day = String(today.getDate());
    return year + '-' + (month.length < 2 ? '0' + month : month) + '-' + (day.length < 2 ? '0' + day : day);
  }

  function scheduleNext(delay) {
    if (state.timer) {
      window.clearTimeout(state.timer);
    }
    state.timer = window.setTimeout(loadScreen, delay);
  }

  function syncVideoState() {
    var screenVideo = document.querySelector('.screen-media-video');
    var panel = screenVideo ? screenVideo.closest('.screen-media-panel') : null;
    if (panel) {
      panel.classList.toggle('has-video', Boolean(screenVideo.currentSrc && screenVideo.readyState > 0));
    }
  }

  function appointmentsContactGuideHtml() {
    return [
      '<article class="screen-flow-step">',
      '<span class="screen-flow-number">1</span>',
      '<svg class="screen-flow-art" viewBox="0 0 120 90" role="img" aria-label="Horario por canales de atencion">',
      '<rect x="22" y="18" width="76" height="58" rx="10" fill="#eff6ff" stroke="#2563eb" stroke-width="4"/>',
      '<path d="M22 34h76" stroke="#2563eb" stroke-width="4"/>',
      '<circle cx="42" cy="26" r="5" fill="#f59e0b"/><circle cx="78" cy="26" r="5" fill="#f59e0b"/>',
      '<path d="M60 47v14l12 7" fill="none" stroke="#0f172a" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>',
      '<circle cx="60" cy="59" r="24" fill="none" stroke="#60a5fa" stroke-width="5"/>',
      '</svg>',
      '<strong>Atencion por canales</strong>',
      '<small>7:00 a. m. - 3:30 p. m.</small>',
      '</article>',
      '<span class="screen-flow-arrow" aria-hidden="true">&rarr;</span>',
      '<article class="screen-flow-step">',
      '<span class="screen-flow-number">2</span>',
      '<svg class="screen-flow-art" viewBox="0 0 120 90" role="img" aria-label="Telefono de citas">',
      '<rect x="36" y="10" width="48" height="70" rx="10" fill="#2563eb"/>',
      '<rect x="43" y="20" width="34" height="40" rx="5" fill="#eff6ff"/>',
      '<circle cx="60" cy="70" r="5" fill="#bfdbfe"/>',
      '<path d="M28 30c-8 10-8 20 0 30M92 30c8 10 8 20 0 30" fill="none" stroke="#f59e0b" stroke-width="5" stroke-linecap="round"/>',
      '</svg>',
      '<strong>Telefono</strong>',
      '<small>01 748 1898</small>',
      '</article>',
      '<span class="screen-flow-arrow" aria-hidden="true">&rarr;</span>',
      '<article class="screen-flow-step">',
      '<span class="screen-flow-number">3</span>',
      '<img class="screen-flow-art screen-flow-logo" src="assets/img/whatsapp_icon.png?v=20260617" alt="WhatsApp">',
      '<strong>WhatsApp</strong>',
      '<small>511 319 9394</small>',
      '</article>',
    ].join('');
  }

  function hydrateAppointmentsFlowGuide(format) {
    var source = document.querySelector('#turneroScreen .screen-flow-guide');
    var target = $('appointmentsFlowGuide');
    var normalized = normalizeFormat(format || state.format);
    if (!target) {
      return;
    }
    target.classList.toggle('appointments-contact-guide', normalized === 'citas_mes_doctor_aviso');
    if (normalized === 'citas_mes_doctor_aviso') {
      if (target.getAttribute('data-guide-type') !== 'contact') {
        target.innerHTML = appointmentsContactGuideHtml();
      }
      target.setAttribute('data-guide-type', 'contact');
      target.setAttribute('aria-label', 'Canales para solicitar citas');
      return;
    }
    if (!source) {
      return;
    }
    if (target.getAttribute('data-guide-type') !== 'steps') {
      target.innerHTML = source.innerHTML;
    }
    target.setAttribute('data-guide-type', 'steps');
    target.setAttribute('aria-label', 'Pasos para atencion');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var screenVideo = document.querySelector('.screen-media-video');
    if (screenVideo) {
      screenVideo.addEventListener('loadedmetadata', syncVideoState);
      screenVideo.addEventListener('loadeddata', syncVideoState);
      screenVideo.addEventListener('playing', syncVideoState);
      screenVideo.addEventListener('error', function () {
        var panel = screenVideo.closest('.screen-media-panel');
        if (panel) {
          panel.classList.remove('has-video');
        }
      });
    }
    syncVideoState();
    hydrateAppointmentsFlowGuide();
    loadAudioPreference();
    loadTurnAudioPreference();
    updateAudioButton();
    updateTurnAudioButton();
    if ($('screenAudioButton')) {
      $('screenAudioButton').addEventListener('click', enableInstructionAudio);
    }
    if ($('turneroAudioButton')) {
      $('turneroAudioButton').addEventListener('click', enableTurnAudio);
    }
    loadScreen();
  });
}());
