/**
 * @file starmus-recorder.js
 * @version 5.0.0‑final
 * @description Handles microphone recording, calibration, speech rec (optional), and visualizer/audio graph.
 *   Tier‑aware, resilient: supports fallback for browsers missing full WebAudio, safe mediaRecorder lifecycle, clean teardown.
 */

(function (global) {
  'use strict';

  var CommandBus = global.StarmusHooks;
  var debugLog = (CommandBus && CommandBus.debugLog) || function () {};

  var recorderRegistry = {};
  var sharedAudioContext = null;

  function emitStarmusEvent(instanceId, event, payload) {
    try {
      if (CommandBus && typeof CommandBus.dispatch === 'function') {
        CommandBus.dispatch('starmus_event', {
          instanceId: instanceId,
          event: event,
          severity: payload.severity || 'info',
          message: payload.message || '',
          data: payload.data || {}
        });
      }
    } catch (e) {
      console.warn('[Starmus] Telemetry emit failed:', e);
    }
  }

  function starmusWaitForTrack(stream) {
    return new Promise(function (resolve) {
      var tracks = (stream.getAudioTracks && stream.getAudioTracks()) || [];
      if (!tracks.length) {
        resolve();
        return;
      }
      var t = tracks[0];
      if (t.readyState === 'live') {
        resolve();
        return;
      }
      var checks = 0;
      var iv = setInterval(function () {
        if (t.readyState === 'live' || checks > 100) {
          clearInterval(iv);
          resolve();
        }
        checks += 1;
      }, 50);
      setTimeout(function () {
        clearInterval(iv);
        resolve();
      }, 5000);
    });
  }

  function getSharedContext() {
    var Ctx = global.AudioContext || global.webkitAudioContext;
    if (!Ctx) {
      throw new Error('AudioContext not supported');
    }
    if (!sharedAudioContext || sharedAudioContext.state === 'closed') {
      sharedAudioContext = new Ctx({ latencyHint: 'interactive' });
      debugLog('[Recorder] Created AudioContext, state:', sharedAudioContext.state);
      if (typeof sharedAudioContext.createMediaStreamSource !== 'function') {
        throw new Error('AudioContext lacks createMediaStreamSource');
      }
    }
    return sharedAudioContext;
  }

  function createDestinationSafe(audioContext) {
    var fn = audioContext.createMediaStreamDestination ||
             audioContext.createMediaStreamAudioDestination;
    if (typeof fn !== 'function') {
      throw new Error('MediaStreamDestination not supported');
    }
    var dest = fn.call(audioContext);
    if (!dest || !dest.stream) {
      throw new Error('Destination stream invalid');
    }
    return dest;
  }

  function setupAudioGraph(rawStream) {
    var audioContext = getSharedContext();
    var source = audioContext.createMediaStreamSource(rawStream);

    try {
      var destNode = createDestinationSafe(audioContext);

      var highPass = audioContext.createBiquadFilter();
      highPass.type = 'highpass';
      highPass.frequency.value = 85;

      var compressor = audioContext.createDynamicsCompressor();
      compressor.threshold.value = -20;
      compressor.knee.value = 40;
      compressor.ratio.value = 12;
      compressor.attack.value = 0;
      compressor.release.value = 0.25;

      var analyser = audioContext.createAnalyser();
      analyser.fftSize = 2048;

      source.connect(highPass);
      highPass.connect(compressor);
      compressor.connect(analyser);
      analyser.connect(destNode);

      return {
        audioContext: audioContext,
        destinationStream: destNode.stream,
        analyser: analyser,
        nodes: [source, highPass, compressor, analyser, destNode],
        fallbackActive: false
      };
    } catch (e) {
      debugLog('[Recorder] Audio graph failed — fallback to raw stream:', e.message);
      var analyser2 = audioContext.createAnalyser();
      analyser2.fftSize = 2048;
      source.connect(analyser2);
      return {
        audioContext: audioContext,
        destinationStream: rawStream,
        analyser: analyser2,
        nodes: [source, analyser2],
        fallbackActive: true
      };
    }
  }

  function calibrateAudioLevels(stream, onUpdate) {
    return new Promise(function (resolve) {
      var audioContext;
      try {
        audioContext = getSharedContext();
      } catch (e) {
        // Fallback — return default calibration if no AudioContext
        resolve({
          gain: 1.0,
          snr: 1.0,
          noiseFloor: 0,
          speechLevel: 0,
          timestamp: new Date().toISOString()
        });
        return;
      }

      var analyser = audioContext.createAnalyser();
      analyser.fftSize = 2048;
      var mic = audioContext.createMediaStreamSource(stream);
      mic.connect(analyser);

      var buffer = new Float32Array(analyser.fftSize);
      var samples = [];
      var start = Date.now();
      var DURATION = 15000;

      function tick() {
        if (audioContext.state !== 'running') {
          requestAnimationFrame(tick);
          return;
        }
        analyser.getFloatTimeDomainData(buffer);
        var sum = 0;
        for (var i = 0; i < buffer.length; i++) sum += buffer[i] * buffer[i];
        var rms = Math.sqrt(sum / buffer.length);
        if (!isFinite(rms)) {
          requestAnimationFrame(tick);
        } else {
          samples.push(rms);
          if (onUpdate) {
            var volumePercent = Math.min(100, rms * 2000);
            onUpdate('Calibrating…', volumePercent, false);
          }
          if (Date.now() - start < DURATION) {
            requestAnimationFrame(tick);
          } else {
            var third = Math.max(1, Math.floor(samples.length / 3));
            var quiet = samples.slice(0, third);
            var speech = samples.slice(third, third * 2);
            var avg = function (arr) {
              var s = 0;
              for (var i = 0; i < arr.length; i++) s += arr[i];
              return s / arr.length;
            };
            var noiseFloor = avg(quiet);
            var speechLevel = avg(speech);
            var snr = speechLevel / Math.max(noiseFloor, 1e-6);
            var gain = snr < 3 ? 6.0 : Math.max(1.0, Math.min(4.0, 0.1 / Math.max(speechLevel, 1e-6)));
            var calibration = {
              gain: parseFloat(gain.toFixed(3)),
              snr: parseFloat(snr.toFixed(3)),
              noiseFloor: parseFloat(noiseFloor.toFixed(6)),
              speechLevel: parseFloat(speechLevel.toFixed(6)),
              timestamp: new Date().toISOString()
            };
            try { mic.disconnect(); } catch (_) {}
            try { analyser.disconnect(); } catch (_) {}
            if (onUpdate) onUpdate('Mic calibrated', null, true);
            resolve(calibration);
          }
        }
      }

      tick();
    });
  }

  function initRecorder(store, instanceId) {
    // Tier awareness (optional future logic)
    var tier = (store.getState && store.getState().tier) || 'A';
    debugLog('[Recorder][' + instanceId + '] Tier:', tier);

    CommandBus.subscribe('setup-mic', function (_p, meta) {
      if (!meta || meta.instanceId !== instanceId) return;

      if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        var msg = 'Microphone not supported.';
        emitStarmusEvent(instanceId, 'E_RECORDER_UNSUPPORTED', { severity: 'error', message: msg });
        store.dispatch({ type: 'starmus/error', payload: { message: msg, retryable: false } });
        return;
      }

      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (rawStream) {
        emitStarmusEvent(instanceId, 'E_MIC_ACCESS', { severity: 'info', message: 'Mic access granted' });
        store.dispatch({ type: 'starmus/calibration-start' });

        return calibrateAudioLevels(rawStream, function (msg, volume) {
          store.dispatch({ type: 'starmus/calibration-update', message: msg, volumePercent: volume });
        }).then(function (cal) {
          try { rawStream.getTracks().forEach(function (t) { t.stop(); }); } catch (_) {}
          // Correct payload key
          store.dispatch({ type: 'starmus/calibration-complete', calibration: cal });
        });

      }).catch(function (err) {
        var msg = err && err.name === 'NotAllowedError'
          ? 'Microphone permission denied.'
          : 'Failed to access microphone.';
        emitStarmusEvent(instanceId, 'E_MIC_ACCESS', { severity: 'error', message: msg, data: { error: err.message } });
        store.dispatch({ type: 'starmus/error', payload: { message: msg, retryable: true } });
      });
    });

    CommandBus.subscribe('start-recording', function (_p, meta) {
      if (!meta || meta.instanceId !== instanceId) return;
      var state = store.getState();

      if (state.status === 'calibrating') {
        store.dispatch({ type: 'starmus/error', payload: { message: 'Please wait for calibration to complete.', retryable: true } });
        return;
      }
      if (!state.calibration || state.calibration.complete !== true) {
        store.dispatch({ type: 'starmus/error', payload: { message: 'Please setup your microphone first.', retryable: true } });
        return;
      }
      if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        store.dispatch({ type: 'starmus/error', payload: { message: 'Microphone not available.', retryable: false } });
        return;
      }

      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (rawStream) {
        var graph = setupAudioGraph(rawStream);
        var destinationStream = graph.destinationStream;
        var analyser = graph.analyser;

        if (graph.audioContext.state === 'suspended') {
          graph.audioContext.resume().catch(function () {});
        }

        starmusWaitForTrack(destinationStream).then(function () {
          var tracks = (destinationStream.getAudioTracks && destinationStream.getAudioTracks()) || [];
          if (!tracks.length) {
            try { rawStream.getTracks().forEach(function (t) { t.stop(); }); } catch (_) {}
            store.dispatch({ type: 'starmus/error', payload: { message: 'Invalid audio stream', retryable: true } });
            return;
          }

          var chunks = [];
          var mediaRecorder;
          try {
            mediaRecorder = new (global.MediaRecorder)(destinationStream, {});
          } catch (e) {
            debugLog('[Recorder] MediaRecorder creation failed:', e);
            try { rawStream.getTracks().forEach(function (t) { t.stop(); }); } catch (_) {}
            store.dispatch({ type: 'starmus/error', payload: { message: 'Unable to start recording', retryable: true } });
            return;
          }

          var recObj = {
            mediaRecorder: mediaRecorder,
            rawStream: rawStream,
            processedStream: destinationStream,
            audioContext: graph.audioContext,
            analyser: analyser,
            nodes: graph.nodes,
            fallbackActive: graph.fallbackActive
          };
          recorderRegistry[instanceId] = recObj;

          mediaRecorder.ondataavailable = function (e) {
            if (e.data && e.data.size > 0) chunks.push(e.data);
          };

          mediaRecorder.onstop = function () {
            if (recorderRegistry[instanceId] === recObj) {
              try {
                if (recObj.audioContext && recObj.audioContext.close) recObj.audioContext.close();
              } catch (_) {}
              var blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
              var fileName = 'starmus-recording-' + Date.now() + '.webm';
              store.dispatch({ type: 'starmus/recording-available', payload: { blob: blob, fileName: fileName } });

              try { rawStream.getTracks().forEach(function (t) { t.stop(); }); } catch (_) {}
              try { destinationStream.getTracks().forEach(function (t) { t.stop(); }); } catch (_) {}
              recObj.nodes.forEach(function (n) {
                try { n.disconnect(); } catch (_) {}
              });
              delete recorderRegistry[instanceId];
            }
          };

          mediaRecorder.onerror = function (err) {
            debugLog('[Recorder] MediaRecorder error:', err);
            emitStarmusEvent(instanceId, 'E_RECORDER_ERROR', { severity: 'error', message: err.message });
            store.dispatch({ type: 'starmus/error', payload: { message: 'Recording error', retryable: true } });
          };

          try {
            if (mediaRecorder.state === 'inactive') {
              mediaRecorder.start(3000);
              store.dispatch({ type: 'starmus/mic-start' });
            } else {
              throw new Error('MediaRecorder not in inactive state');
            }
          } catch (e) {
            debugLog('[Recorder] mediaRecorder.start failed:', e);
            try { rawStream.getTracks().forEach(function (t) { t.stop(); }); } catch (_) {}
            delete recorderRegistry[instanceId];
            store.dispatch({ type: 'starmus/error', payload: { message: 'Cannot start recording', retryable: true } });
            emitStarmusEvent(instanceId, 'E_RECORDER_START_FAIL', { severity: 'error', message: e.message });
            return;
          }

          var meterBuffer = new Float32Array(analyser.fftSize);
          recObj.startTime = Date.now();

          function meterLoop() {
            var rec = recorderRegistry[instanceId];
            if (!rec || !rec.mediaRecorder) return;
            if (rec.mediaRecorder.state !== 'recording' && rec.mediaRecorder.state !== 'paused')
              return;

            try {
              rec.analyser.getFloatTimeDomainData(meterBuffer);
              var sum = 0;
              for (var i = 0; i < meterBuffer.length; i++) sum += meterBuffer[i] * meterBuffer[i];
              var rms = Math.sqrt(sum / meterBuffer.length);
              var amplitude = Math.min(100, Math.max(0, rms * 4000));
              var elapsed = (Date.now() - recObj.startTime) / 1000;
              store.dispatch({ type: 'starmus/recorder-tick', duration: elapsed, amplitude: amplitude });
            } catch (_) {
              // ignore
            }
            recObj.rafId = requestAnimationFrame(meterLoop);
          }
          recObj.rafId = requestAnimationFrame(meterLoop);

        });
      }).catch(function (err) {
        var msg = 'Could not access microphone.';
        emitStarmusEvent(instanceId, 'E_MIC_ACCESS', { severity: 'error', message: msg, data: { error: err.message } });
        store.dispatch({ type: 'starmus/error', payload: { message: msg, retryable: true } });
      });
    });

    CommandBus.subscribe('stop-mic', function (_p, meta) {
      if (!meta || meta.instanceId !== instanceId) return;
      var rec = recorderRegistry[instanceId];
      if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'recording') {
        store.dispatch({ type: 'starmus/mic-stop' });
        try { rec.mediaRecorder.stop(); } catch (_) {}
      }
    });

    CommandBus.subscribe('pause-mic', function (_p, meta) {
      if (!meta || meta.instanceId !== instanceId) return;
      var rec = recorderRegistry[instanceId];
      if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'recording' &&
          typeof rec.mediaRecorder.pause === 'function') {
        store.dispatch({ type: 'starmus/mic-pause' });
        rec.mediaRecorder.pause();
      }
    });

    CommandBus.subscribe('resume-mic', function (_p, meta) {
      if (!meta || meta.instanceId !== instanceId) return;
      var rec = recorderRegistry[instanceId];
      if (rec && rec.mediaRecorder && rec.mediaRecorder.state === 'paused' &&
          typeof rec.mediaRecorder.resume === 'function') {
        store.dispatch({ type: 'starmus/mic-resume' });
        rec.mediaRecorder.resume();
      }
    });

    CommandBus.subscribe('reset', function (_p, meta) {
      if (!meta || meta.instanceId !== instanceId) return;
      var rec = recorderRegistry[instanceId];
      if (rec) {
        try {
          if (rec.mediaRecorder && rec.mediaRecorder.state !== 'inactive')
            rec.mediaRecorder.stop();
        } catch (_) {}
        if (rec.rafId) cancelAnimationFrame(rec.rafId);
        if (rec.rawStream) rec.rawStream.getTracks().forEach(function (t) { t.stop(); });
        if (rec.processedStream) rec.processedStream.getTracks().forEach(function (t) { t.stop(); });
        if (rec.nodes) rec.nodes.forEach(function (n) {
          try { n.disconnect(); } catch (_) {}
        });
        delete recorderRegistry[instanceId];
      }
    });
  }

  global.initStarmusRecorder = initRecorder;

})(typeof window !== 'undefined' ? window : globalThis);
