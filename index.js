/*!
 * Tegaru — lighter photo uploads for the Kirby Panel. https://github.com/sayamaapps/kirby-tegaru
 *
 * Before an upload starts, the Panel decodes a JPEG, PNG or WebP photo in the
 * browser, applies its EXIF rotation, scales the longest edge down to
 * tegaru.maxEdge and re-encodes it in its own type: JPEG and WebP at
 * tegaru.quality, PNG as PNG. The canvas output carries no EXIF block, so no
 * GPS position goes up. A JPEG that the phone's camera named image.jpg (or
 * image.jpeg) is renamed to foto-<local time of the selection>.jpg, so a
 * second capture does not clash with the first on the server. HEIC, GIF,
 * AVIF and anything else are left alone. Nothing is stored in the browser and
 * nothing is sent anywhere except the upload Kirby sends anyway.
 *
 * Kirby 5 has no hook before the upload, so this script wraps select and
 * submit of window.panel.upload. select keeps its own behaviour (the dialog
 * opens at once) and starts the preparation of each new item; submit waits
 * until no item is being prepared any more. A prepared item gets a new
 * item.src and the fields that describe it; the dialog and the upload read
 * those fields, so they follow. Kirby's pick, drop and open all call
 * this.select, so they go through the wrapper as well.
 *
 * Without select and submit (another Kirby version) one console.info is
 * logged and nothing is changed.
 *
 * Kirby's files field is extended once: while Tegaru is active, a tap on the
 * empty field opens the device's picker, as the field's Upload button does,
 * so a phone offers its photo library and camera. Select still lists the
 * files already there.
 *
 * A plain script: ES2017, no modules, no build step. Kirby loads it from the
 * plugin root.
 */
(function () {
  'use strict';

  if (typeof window === 'undefined') {
    return;
  }

  // Enough for the magic bytes; the rest of the photo is never read.
  var HEAD_BYTES = 65536;
  // The longest a picture may take to prepare, in ms. Past it the picture goes
  // up as it is.
  var TIME_LIMIT = 20000;
  // The type each decoded picture is encoded as.
  var OUTPUT_TYPE = {
    jpeg: 'image/jpeg',
    png: 'image/png',
    webp: 'image/webp'
  };
  // Pictures decoded and encoded at the same time. A decoded 24-MP photo takes
  // a lot of memory; the others wait in one queue, and the first ready picture
  // takes the next free slot.
  var SLOTS = 2;

  /** True if the bytes at offset spell the ASCII text. */
  function has(bytes, offset, text) {
    if (offset + text.length > bytes.length) {
      return false;
    }
    for (var i = 0; i < text.length; i++) {
      if (bytes[offset + i] !== text.charCodeAt(i)) {
        return false;
      }
    }
    return true;
  }

  /**
   * The type of a file from its first bytes (ArrayBuffer or typed array):
   * 'jpeg' | 'png' | 'gif' | 'webp' | 'avif' | 'other' (HEIC is 'other').
   */
  function sniff(buffer) {
    var bytes = ArrayBuffer.isView(buffer)
      ? new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength)
      : new Uint8Array(buffer);
    if (has(bytes, 0, '\xFF\xD8\xFF')) {
      return 'jpeg';
    }
    if (has(bytes, 0, '\x89PNG\r\n\x1A\n')) {
      return 'png';
    }
    if (has(bytes, 0, 'GIF8')) {
      return 'gif';
    }
    if (has(bytes, 0, 'RIFF') && has(bytes, 8, 'WEBP')) {
      return 'webp';
    }
    if (has(bytes, 4, 'ftyp') && bytes.length >= 12) {
      var brand = String.fromCharCode(bytes[8], bytes[9], bytes[10], bytes[11]);
      if (brand === 'avif' || brand === 'avis') {
        return 'avif';
      }
    }
    return 'other';
  }

  /** The size the picture gets: longest edge at most maxEdge, aspect ratio kept. */
  function targetSize(width, height, maxEdge) {
    var scale = Math.min(1, maxEdge / Math.max(width, height));
    return {
      width: Math.round(width * scale),
      height: Math.round(height * scale),
      scale: scale
    };
  }

  /** True for the generic name a phone's camera gives a capture: image.jpg or image.jpeg, any case. */
  function isCameraName(name) {
    return /^image\.jpe?g$/i.test(name);
  }

  /** The local time of the device as 'YYYY-MM-DD-hh-mm-ss'. */
  function stamp(date) {
    function two(n) {
      return (n < 10 ? '0' : '') + n;
    }
    return [date.getFullYear(), two(date.getMonth() + 1), two(date.getDate())].join('-') + '-' +
      [two(date.getHours()), two(date.getMinutes()), two(date.getSeconds())].join('-');
  }

  /**
   * The name of a camera capture taken at `time` (a stamp): foto-<time>.jpg.
   * While the name is in `taken` (the other items of the upload, case-blind),
   * -2, -3 … goes before the extension.
   */
  function cameraName(time, taken) {
    var used = (taken || []).map(function (other) {
      return other.toLowerCase();
    });
    var candidate = 'foto-' + time + '.jpg';
    for (var n = 2; used.indexOf(candidate.toLowerCase()) >= 0; n++) {
      candidate = 'foto-' + time + '-' + n + '.jpg';
    }
    return candidate;
  }

  /** Kirby's own item.niceSize format: Intl, English, narrow units (983KB, 1.1MB). */
  function formatBytes(bytes) {
    return new Intl.NumberFormat('en', {
      notation: 'compact',
      style: 'unit',
      unit: 'byte',
      unitDisplay: 'narrow'
    }).format(bytes);
  }

  /**
   * What to do with a picture, decided before it is decoded: 'skip' or
   * 'resize' (JPEG, PNG or WebP at or above minBytes, re-encoded as its own
   * type at any size, because the canvas output carries no metadata;
   * encodePicture keeps the original when the result is not smaller).
   * info = {type, size}.
   */
  function decide(info, options) {
    if (!options || !options.active) {
      return 'skip';
    }
    if (info.type !== 'jpeg' && info.type !== 'png' && info.type !== 'webp') {
      return 'skip';
    }
    return info.size < options.minBytes ? 'skip' : 'resize';
  }

  /** The first bytes of a file, enough for sniff(). */
  function readHead(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () {
        resolve(reader.result);
      };
      reader.onerror = function () {
        reject(reader.error);
      };
      reader.readAsArrayBuffer(file.slice(0, HEAD_BYTES));
    });
  }

  /** The picture with EXIF orientation applied: an ImageBitmap, or an <img> when the browser refuses. */
  function decode(file) {
    return Promise.resolve()
      .then(function () {
        return createImageBitmap(file, { imageOrientation: 'from-image' });
      })
      .then(function (bitmap) {
        return {
          width: bitmap.width,
          height: bitmap.height,
          source: bitmap,
          close: function () {
            bitmap.close();
          }
        };
      }, function () {
        return decodeWithImage(file);
      });
  }

  function decodeWithImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        resolve({
          width: img.naturalWidth,
          height: img.naturalHeight,
          source: img,
          close: function () {
            URL.revokeObjectURL(url);
          }
        });
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error('the browser cannot decode this file'));
      };
      img.src = url;
    });
  }

  /** The resized picture as a Blob: OffscreenCanvas when present, else a detached canvas. */
  function render(source, size, type, quality) {
    var encoding = type === 'image/png' ? { type: type } : { type: type, quality: quality };
    if (typeof OffscreenCanvas === 'function') {
      var offscreen = new OffscreenCanvas(size.width, size.height);
      offscreen.getContext('2d').drawImage(source, 0, 0, size.width, size.height);
      return offscreen.convertToBlob(encoding);
    }
    return new Promise(function (resolve) {
      var canvas = document.createElement('canvas');
      canvas.width = size.width;
      canvas.height = size.height;
      canvas.getContext('2d').drawImage(source, 0, 0, size.width, size.height);
      if (type === 'image/png') {
        canvas.toBlob(resolve, type);
      } else {
        canvas.toBlob(resolve, type, quality);
      }
    });
  }

  // The slots: at most SLOTS pictures are decoded and encoded at a time. The
  // others wait in one queue; the first ready picture takes the next free slot.
  var freeSlots = SLOTS;
  var waiting = [];

  /**
   * Runs task in the next free slot (the first ready picture takes it);
   * resolves or rejects with its result.
   */
  function inSlot(task) {
    return new Promise(function (resolve, reject) {
      waiting.push({ task: task, resolve: resolve, reject: reject });
      admit();
    });
  }

  function admit() {
    while (freeSlots > 0 && waiting.length > 0) {
      start(waiting.shift());
    }
  }

  function start(job) {
    freeSlots -= 1;
    Promise.resolve()
      .then(job.task)
      .then(job.resolve, job.reject)
      .then(function () {
        freeSlots += 1;
        admit();
      });
  }

  /**
   * Decodes and re-encodes one picture; the caller holds a slot meanwhile.
   * wanted() says whether the picture is still needed (checked after the
   * decode, so a dropped item is not encoded). Resolves to null when the
   * picture stays as it is, to {undecodable: true} when the browser cannot
   * decode it, else to {blob, type}.
   */
  async function encodePicture(original, type, opts, wanted) {
    var decoded;
    try {
      decoded = await decode(original);
    } catch (error) {
      return { undecodable: true };
    }
    try {
      if (wanted && !wanted()) {
        return null;
      }
      var outType = OUTPUT_TYPE[type];
      var size = targetSize(decoded.width, decoded.height, opts.maxEdge);
      var blob = null;
      try {
        blob = await render(decoded.source, size, outType, opts.quality);
      } catch (error) {
        blob = null;
      }
      // The browser may hand back another type (WebP unsupported gives PNG),
      // nothing, or throw: the original stays.
      if (!blob || blob.type !== outType) {
        return null;
      }
      // Never larger than the original.
      if (blob.size >= original.size) {
        return null;
      }
      return { blob: blob, type: outType };
    } finally {
      decoded.close();
    }
  }

  // While a click waits, the dialog is looked at again this often (ms), so an
  // item removed by hand or a dialog closed by other means is noticed soon.
  var POLL = 100;

  // The settings as far as the files field needs them before a tap: attach()
  // sets load, and known once an answer came. A tap cannot wait for the
  // request, because the device's picker opens only within the tap itself.
  var settings = { load: null, known: null };

  /**
   * Wraps select, submit and reset of one upload module; app gives the API and
   * the translations. reset is wrapped only to learn at once that the dialog
   * was cancelled (Kirby's cancel and done call it); it runs unchanged.
   *
   * The rule everything below follows: a click uploads exactly when, once its
   * photos are ready or past their time limit, it is still the latest click,
   * the upload was not reset since, and the Panel dialog of the click is still
   * the open one (a click made without a dialog, Kirby's immediate pick, is
   * bound to none); it uploads what the dialog holds then. For an item the
   * dialog no longer holds no decode or encode is started, and it is not
   * replaced, not removed, not announced and not waited for; a decode or
   * encode already running ends on its own and its result is dropped. A photo
   * picked again after its item was prepared is dropped silently, found by
   * comparing it with the original as Kirby's duplicate filter compares; the
   * prepared item stays (Kirby's own filter would keep the later pick).
   *
   * The state of one module is a plain object, passed as the first argument of
   * the functions below. Nothing is shared between modules.
   */
  function attach(app, upload) {
    var state = {
      app: app,
      upload: upload,
      select: upload.select,
      submit: upload.submit,
      reset: typeof upload.reset === 'function' ? upload.reset : null,
      pending: new Map(), // job promise -> item, one per item still being prepared
      optionsPromise: null, // one request per Panel session; a failure is asked again
      dialogs: 0, // counts resets: a click whose dialog was reset meanwhile uploads nothing
      clicks: 0, // counts submit calls: only the latest one uploads
      wakers: [] // waiting clicks, woken by a reset
    };
    settings.load = function () {
      return loadOptions(state);
    };
    wrapSelect(state);
    wrapSubmit(state);
    if (state.reset) {
      wrapReset(state);
    }
  }

  // Clicks and dialogs: a waiting click looks at the dialog again on every reset and every POLL ms.

  /** Wakes the clicks that wait for a reset. */
  function wake(state) {
    state.wakers.slice().forEach(function (waker) {
      waker();
    });
  }

  /**
   * A promise that resolves on the next reset or after POLL ms, whichever
   * comes first, with done() to end it early. Leaves no timer or waker behind.
   */
  function nextChange(state) {
    var done = null;
    var promise = new Promise(function (resolve) {
      var timer = setTimeout(function () {
        done();
      }, POLL);
      done = function () {
        clearTimeout(timer);
        var at = state.wakers.indexOf(done);
        if (at >= 0) {
          state.wakers.splice(at, 1);
        }
        resolve();
      };
      state.wakers.push(done);
    });
    return { promise: promise, done: done };
  }

  /**
   * The Panel dialog of this moment while one is open, else null (no dialog,
   * or a Panel without the store: then a click is never bound to a dialog).
   * Kirby gives every opened dialog an id (the key) and, for the upload
   * dialog, a fresh props object (a second key); both are compared, so a
   * closed and reopened dialog is told apart. A dialog Kirby restores from
   * its history keeps both and counts as the same one.
   */
  function openDialog() {
    var dialog = window.panel && window.panel.dialog;
    return dialog && dialog.isOpen === true ? { id: dialog.id, props: dialog.props } : null;
  }

  /** True when the dialog seen at the click is still the open one (or there was none). */
  function sameDialog(seen) {
    if (!seen) {
      return true;
    }
    var now = openDialog();
    return now !== null && now.id === seen.id && now.props === seen.props;
  }

  /** The module's options from Kirby's API, asked once per Panel session; a failure is asked again. */
  function loadOptions(state) {
    if (!state.optionsPromise) {
      state.optionsPromise = Promise.resolve()
        .then(function () {
          return state.app.$api.get('tegaru/options');
        })
        .then(function (data) {
          if (!data || typeof data !== 'object') {
            throw new Error('tegaru/options: no options object');
          }
          settings.known = {
            active: data.active === true,
            maxEdge: data.maxEdge,
            quality: data.quality,
            minBytes: data.minBytes
          };
          return settings.known;
        })
        .catch(function (error) {
          state.optionsPromise = null;
          throw error;
        });
    }
    return state.optionsPromise;
  }

  /** A notification of the Panel, its text translated by Kirby. */
  function notify(state, kind, key, values) {
    window.panel.notification[kind](state.app.$t(key, values));
  }

  // Items: what the dialog holds, and what is done to it.

  function removeItem(state, item) {
    if (typeof state.upload.remove === 'function') {
      state.upload.remove(item.id); // Kirby's remove takes the id, not the item
    } else {
      state.upload.files = state.upload.files.filter(function (file) {
        return file !== item;
      });
    }
    URL.revokeObjectURL(item.url);
  }

  /** True while the dialog still holds the item (not cancelled, not removed). */
  function inDialog(state, item) {
    return (state.upload.files || []).indexOf(item) >= 0;
  }

  /** The names of the other items in the upload. */
  function takenNames(state, item) {
    return (state.upload.files || [])
      .filter(function (other) {
        return other !== item;
      })
      .map(function (other) {
        return other.filename;
      })
      .filter(Boolean);
  }

  /**
   * Keeps what Kirby compares in findDuplicate, before the item's file is
   * replaced or renamed, so a re-picked original is still recognised. Kept
   * once: a renamed file that is then resized keeps the first record.
   */
  function keepOriginal(item) {
    if (!item.tegaruOriginal) {
      var file = item.src;
      item.tegaruOriginal = { name: file.name, type: file.type, size: file.size, lastModified: file.lastModified };
    }
  }

  /**
   * A camera capture (JPEG named image.jpg or image.jpeg) gets its name from
   * the time of selection, so the next capture does not clash with it. Only
   * with the settings in hand and only while Tegaru is active. The item
   * keeps its bytes; only the name changes. A name the user typed after the
   * selection (item.name differs from tegaruName) is kept.
   */
  function renameCapture(state, item, sniffed, opts) {
    var file = item.src;
    if (!opts.active || sniffed !== 'jpeg' || !isCameraName(file.name)) {
      return;
    }
    if (item.name !== item.tegaruName) {
      return;
    }
    var name = cameraName(item.tegaruStamp, takenNames(state, item));
    keepOriginal(item);
    item.src = new File([file], name, { type: file.type, lastModified: file.lastModified });
    // Kirby keeps item.name without the extension.
    item.name = name.slice(0, name.length - '.jpg'.length);
    item.extension = 'jpg';
    item.filename = name;
  }

  /** The prepared picture takes the place of the original in the item. */
  function replace(item, original, blob, type) {
    var file = new File([blob], original.name, { type: type, lastModified: original.lastModified });
    var previousUrl = item.url;
    keepOriginal(item);
    item.src = file;
    item.size = blob.size;
    item.niceSize = formatBytes(blob.size);
    item.type = type;
    item.url = URL.createObjectURL(file);
    URL.revokeObjectURL(previousUrl);
    item.tegaru = { from: original.size, to: blob.size };
  }

  /**
   * Prepares one item. A failure leaves the item as it is, so the upload is
   * never blocked. The time limit (see track) can end the run at any await:
   * the item is then 'kept' or 'removed' already, so every step after an
   * await checks that it is still 'pending', and a late result changes nothing.
   */
  async function prepare(state, item) {
    // The type comes from the first bytes, read locally: no request yet.
    var type = sniff(await readHead(item.src));
    if (item.tegaru !== 'pending') {
      return;
    }
    var opts = await loadOptions(state);
    if (item.tegaru !== 'pending') {
      return;
    }
    // A camera capture is renamed whether or not its picture is resized, so
    // that the next capture does not clash with it.
    renameCapture(state, item, type, opts);
    var original = item.src;
    // One decision, before a slot is taken: inactive, other formats and small
    // files stay as they are.
    if (decide({ type: type, size: original.size }, opts) === 'skip') {
      return;
    }

    var wanted = function () {
      return item.tegaru === 'pending' && inDialog(state, item);
    };
    var result = await inSlot(function () {
      // An item that timed out or left the dialog while it waited for a slot
      // is not decoded at all.
      return wanted() ? encodePicture(original, type, opts, wanted) : null;
    });
    if (item.tegaru !== 'pending' || !result) {
      return;
    }
    // The dialog may have dropped the item meanwhile (cancelled): nothing to
    // replace, and no message.
    if (!inDialog(state, item)) {
      return;
    }
    if (result.undecodable) {
      notify(state, 'info', 'tegaru.panel.unreadable', { name: original.name });
      return;
    }
    replace(item, original, result.blob, result.type);
  }

  /**
   * Starts the preparation of one item. The job leaves pending when prepare
   * has settled or when the time limit is over. Past the limit the item goes
   * up as it is ('kept'); see prepare for what a late result does.
   */
  function track(state, item) {
    var timer = null;
    var limit = new Promise(function (resolve) {
      timer = setTimeout(function () {
        if (item.tegaru === 'pending') {
          item.tegaru = 'kept';
        }
        resolve();
      }, window.tegaru.timeLimit);
    });
    var job = Promise.race([prepare(state, item), limit])
      .catch(function () {
        // The item is uploaded as it is.
      })
      .then(function () {
        clearTimeout(timer);
        if (item.tegaru === 'pending') {
          item.tegaru = 'kept';
        }
        state.pending.delete(job);
      });
    state.pending.set(job, item);
    return job;
  }

  /**
   * One notification per select, after its items have settled, if any picture
   * got smaller. Items the dialog no longer holds (closed meanwhile) are not counted.
   */
  function report(state, items) {
    var shown = state.upload.files || [];
    var done = items.filter(function (item) {
      return item.tegaru && typeof item.tegaru === 'object' && shown.indexOf(item) >= 0;
    });
    if (!done.length) {
      return;
    }
    var from = 0;
    var to = 0;
    done.forEach(function (item) {
      from += item.tegaru.from;
      to += item.tegaru.to;
    });
    if (done.length === 1) {
      notify(state, 'success', 'tegaru.panel.resizedOne', { from: formatBytes(from), to: formatBytes(to) });
    } else {
      notify(state, 'success', 'tegaru.panel.resized', {
        count: done.length,
        from: formatBytes(from),
        to: formatBytes(to)
      });
    }
  }

  /** True when a new item's file is the original of an item already prepared (Kirby's own comparison). */
  function pickedAgain(item, list) {
    var file = item.src;
    return list.some(function (other) {
      var o = other !== item && other.tegaruOriginal;
      return o && o.name === file.name && o.type === file.type && o.size === file.size && o.lastModified === file.lastModified;
    });
  }

  // The wrappers. Each new item starts its preparation at once; the first
  // ready picture takes the next free slot.

  /** Wraps select: marks and prepares the new items; select itself keeps its behaviour. */
  function wrapSelect(state) {
    var select = state.select;
    state.upload.select = function () {
      var result = select.apply(this, arguments);
      var batch = [];
      var list = ((this && this.files) || []).slice();
      for (var i = 0; i < list.length; i++) {
        if (list[i] && !list[i].tegaru) {
          if (pickedAgain(list[i], list)) {
            // Kirby's duplicate filter no longer sees it (the first item's file was replaced): drop it the same way, silently.
            removeItem(state, list[i]);
            continue;
          }
          list[i].tegaru = 'pending';
          list[i].tegaruStamp = stamp(new Date()); // the time of selection, for a camera name
          list[i].tegaruName = list[i].name; // the name Kirby gave it; a rename by the user changes item.name only
          batch.push(list[i]);
        }
      }
      if (batch.length) {
        Promise.all(batch.map(function (item) {
          return track(state, item);
        }))
          .then(function () {
            report(state, batch);
          })
          .catch(function (error) {
            console.info('Tegaru: ' + error.message);
          });
      }
      return result;
    };
  }

  /** The jobs of the items the dialog still holds; items it dropped are not waited for. */
  function openJobs(state) {
    var jobs = [];
    state.pending.forEach(function (item, job) {
      if (inDialog(state, item)) {
        jobs.push(job);
      }
    });
    return jobs;
  }

  /**
   * Resolves once no item of the dialog is being prepared; items selected
   * meanwhile are waited for too, items the dialog dropped are not. The
   * dialog is looked at again on every reset and every POLL ms, so a
   * cancelled dialog or a removed item is not waited for until its decode
   * ends; the wait also ends as soon as stillStands() says the click is void.
   */
  async function untilSettled(state, stillStands) {
    var jobs = openJobs(state);
    while (stillStands() && jobs.length > 0) {
      var change = nextChange(state);
      await Promise.race([Promise.all(jobs), change.promise]);
      change.done();
      jobs = openJobs(state);
    }
  }

  /**
   * Wraps submit: waits for the items of the dialog, then runs Kirby's submit
   * with what the dialog holds then: photos added to the open dialog while it
   * waits are prepared and go up with the same click. Nothing is uploaded when,
   * meanwhile, the upload was reset (cancel), the Panel dialog of the click is
   * no longer the open one (closed by Escape or otherwise), or a later click
   * came (a later immediate pick): a photo picked after that waits for its own
   * click, and no photo goes up twice.
   */
  function wrapSubmit(state) {
    var submit = state.submit;
    state.upload.submit = function () {
      var self = this;
      var args = arguments;
      var click = ++state.clicks;
      var dialog = state.dialogs;
      var seen = openDialog();
      /** The rule from the top of attach(), for this click. */
      function stillStands() {
        return click === state.clicks && dialog === state.dialogs && sameDialog(seen);
      }
      return untilSettled(state, stillStands).then(function () {
        return stillStands() ? submit.apply(self, args) : undefined;
      });
    };
  }

  /** Wraps reset: counts it and wakes the waiting clicks; reset itself runs unchanged. */
  function wrapReset(state) {
    var reset = state.reset;
    state.upload.reset = function () {
      var result = reset.apply(this, arguments);
      state.dialogs += 1;
      wake(state);
      return result;
    };
  }

  /**
   * Kirby's files field, extended. Kirby's empty field and its Select button
   * both call open(), which lists the files already there. While Tegaru is
   * active, a tap on the empty field opens the device's picker instead, with
   * the options of the field's own Upload button, so a phone offers its photo
   * library and camera. Select is bound to Kirby's open() and keeps listing
   * the files already there. When Kirby registers this component it replaces
   * the extends string with its own files field; inherited() reads Kirby's
   * members from there.
   */
  var filesField = {
    extends: 'k-files-field',
    computed: {
      buttons: function () {
        var buttons = callInherited('computed', 'buttons', this) || [];
        var at = selectButton(buttons);
        if (at >= 0) {
          var field = this;
          buttons[at] = Object.assign({}, buttons[at], {
            click: function () {
              return callInherited('methods', 'open', field);
            }
          });
        }
        return buttons;
      }
    },
    // The settings are asked for when a field appears, so the first tap knows them.
    mounted: function () {
      if (settings.load) {
        settings.load().catch(function () {
          // Asked again by the next field or select; until then a tap lists the files.
        });
      }
    },
    methods: {
      open: function () {
        if (uploadsWhenEmpty(this)) {
          return this.$panel.upload.pick(this.uploadOptions);
        }
        return callInherited('methods', 'open', this);
      }
    }
  };

  /** Kirby's own computed or method of the files field, or null. */
  function inherited(kind, name) {
    var parent = filesField.extends;
    var members = parent && parent.options ? parent.options[kind] : null;
    var member = members ? members[name] : null;
    if (member && typeof member === 'object') {
      member = member.get; // a computed with a getter and a setter
    }
    return typeof member === 'function' ? member : null;
  }

  function callInherited(kind, name, field) {
    var member = inherited(kind, name);
    return member ? member.call(field) : null;
  }

  /** The index of Kirby's Select button (icon checklist), -1 without one. */
  function selectButton(buttons) {
    for (var i = 0; i < buttons.length; i++) {
      if (buttons[i] && buttons[i].icon === 'checklist') {
        return i;
      }
    }
    return -1;
  }

  /**
   * True when a tap on the empty field opens the device's picker: Tegaru is
   * active (its settings have come), the field takes uploads (Kirby's
   * hasDropzone: not disabled, below max, uploads allowed), the upload module
   * can pick, and Select still lists the files already there.
   */
  function uploadsWhenEmpty(field) {
    var upload = field.$panel ? field.$panel.upload : null;
    return Boolean(settings.known && settings.known.active) &&
      Boolean(field.hasDropzone) &&
      Boolean(field.uploadOptions) &&
      Boolean(upload) && typeof upload.pick === 'function' &&
      selectButton(field.buttons || []) >= 0;
  }

  // Test access only (@internal, @deprecated): the pure helpers and the time
  // limit, read by tests/tegaru-js.test.mjs. Not part of the public API; removed in 2.0.
  window.tegaru = {
    sniff: sniff,
    targetSize: targetSize,
    isCameraName: isCameraName,
    stamp: stamp,
    cameraName: cameraName,
    formatBytes: formatBytes,
    decide: decide,
    timeLimit: TIME_LIMIT
  };

  if (!window.panel || typeof window.panel.plugin !== 'function') {
    return;
  }

  window.panel.plugin('sayamaapps/tegaru', {
    components: {
      'k-files-field': filesField
    },
    created: function (app) {
      var upload = app && app.$panel ? app.$panel.upload : null;
      if (!upload || typeof upload.select !== 'function' || typeof upload.submit !== 'function') {
        console.info('Tegaru: panel.upload not found in this Kirby version, nothing changed.');
        return;
      }
      if (!window.panel.dialog) {
        console.info('Tegaru: panel.dialog not found in this Kirby version; a dialog closed by Escape is not detected.');
      }
      attach(app, upload);
    }
  });
})();
