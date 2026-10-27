// index.js against a fake Panel (no dependencies): the helpers decide what is
// prepared; the wrapper keeps select synchronous, makes submit wait for every
// preparation, lets at most two pictures be decoded at a time, and never lets
// a failed preparation block the upload.
//   node --test tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../index.js', import.meta.url), 'utf8');

const ascii = (text) => Array.from(text, (c) => c.charCodeAt(0));
const ftyp = (brand) => [0, 0, 0, 0x18, ...ascii('ftyp'), ...ascii(brand), 0, 0, 0, 0];
const JPEG = [0xff, 0xd8, 0xff, 0xe1, 0x00, 0x10];
const PNG = [0x89, ...ascii('PNG'), 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00];
const WEBP = [...ascii('RIFF'), 0, 0, 0, 0, ...ascii('WEBP')];
const GIF = ascii('GIF89a');
const PDF = ascii('%PDF-1.7');
const HEIC = ftyp('heic');
const AVIF = ftyp('avif');
const SERVER = {
  format: 1, active: true, maxEdge: 2560, quality: 0.85, minBytes: 1048576,
  maxUploadBytes: 15728640, maxPixels: 40000000, language: 'en',
};

function settle() {
  return new Promise((resolve) => setImmediate(resolve));
}

function deferred() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
}

// The browser's File as far as Tegaru uses it. slice() hands back the file
// itself; FakeFileReader reads its head bytes once its gate (if any) opens.
class FakeFile {
  constructor(parts, name, options = {}) {
    this.name = name;
    this.type = options.type ?? '';
    this.lastModified = options.lastModified ?? 0;
    this.size = parts.reduce((sum, part) => sum + part.size, 0);
    this.head = new Uint8Array(0);
    this.gate = null;
    this.reads = 0;
  }

  slice() {
    return this;
  }
}

class FakeFileReader {
  readAsArrayBuffer(blob) {
    blob.reads += 1;
    Promise.resolve(blob.gate).then(() => {
      this.result = blob.head;
      this.onload();
    });
  }
}

function photo(name, head, { size, type = 'image/jpeg', gate = null } = {}) {
  const file = new FakeFile([], name, { type, lastModified: 1000 });
  file.size = size ?? head.length;
  file.head = Uint8Array.from(head);
  file.gate = gate;
  return file;
}

// Kirby's upload module as far as Tegaru uses it. select builds items the way
// Kirby does (name without extension, extension after the last dot); submit
// and remove are plain functions that record their calls.
function fakeUpload() {
  let id = 0;
  return {
    files: [],
    calls: [],
    submits: [],
    removed: [],
    select(list) {
      this.calls.push('select');
      const items = Array.from(list, (file) => {
        const dot = file.name.lastIndexOf('.');
        id += 1;
        return {
          id,
          src: file,
          name: file.name.slice(0, dot),
          extension: file.name.slice(dot + 1),
          filename: file.name,
          size: file.size,
          niceSize: '',
          type: file.type,
          url: `blob:original-${id}`,
          completed: false,
          error: null,
        };
      });
      this.files = [...this.files, ...items];
      return 'dialog';
    },
    submit(...args) {
      this.calls.push('submit');
      this.submits.push({ self: this, args, marks: this.files.map((item) => item.tegaru) });
      return 'submitted';
    },
    remove(itemId) {
      this.removed.push(itemId);
      this.files = this.files.filter((item) => item.id !== itemId);
    },
    reset() {
      this.calls.push('reset');
      this.files.splice(0); // as Kirby's reset() does
    },
    pick(options) {
      this.calls.push('pick');
      this.picked = options;
    },
  };
}

function setup({
  upload = fakeUpload(),
  options = SERVER,
  undecodable = [],
  encodedSize = () => 1000000,
  encodeAs = (type) => type, // what the browser hands back for a requested type
  decodeGate = () => null, // a promise that holds the decode of a file name
  dimensions = () => [4032, 3024], // [width, height] of the decoded picture, by file name
  encodeError = null, // an error the encoder throws instead of handing back a blob
  dialogStore = true, // false: a Panel without window.panel.dialog
  clock = new Date(2026, 9, 20, 14, 32, 5), // the device's time (local): a camera name is taken from it
} = {}) {
  const api = { calls: [], readsAtCall: [], fail: false, hang: false };
  const notifications = [];
  const infos = [];
  const registered = [];
  const decodes = [];
  const events = []; // 'start <name>' / 'end <name>' around each decode
  const encodes = [];
  const revoked = [];
  const made = new Map(); // object URL -> file
  const timers = { set: 0, cleared: 0 };
  let urls = 0;

  const dialog = { isOpen: false, id: null, props: null }; // Kirby's dialog store as far as Tegaru reads it
  const window = {
    panel: {
      dialog: dialogStore ? dialog : undefined,
      plugin: (name, extension) => registered.push({ name, extension }),
      notification: {
        success: (text) => notifications.push(['success', text]),
        info: (text) => notifications.push(['info', text]),
        error: (text) => notifications.push(['error', text]),
      },
    },
  };

  class FakeImage {
    set src(url) {
      const file = made.get(url);
      decodes.push(file.name);
      Promise.resolve().then(() => {
        if (undecodable.includes(file.name)) {
          this.onerror();
        } else {
          [this.naturalWidth, this.naturalHeight] = dimensions(file.name);
          this.onload();
        }
      });
    }
  }

  const document = {
    createElement() {
      return {
        width: 0,
        height: 0,
        getContext: () => ({ drawImage() {} }),
        toBlob(callback, type, quality) {
          if (encodeError) {
            throw encodeError;
          }
          encodes.push({ type, quality, width: this.width, height: this.height });
          callback({ size: encodedSize(this.width, this.height, type), type: encodeAs(type) });
        },
      };
    },
  };

  const context = {
    window,
    document,
    URL: {
      createObjectURL(file) {
        urls += 1;
        const url = `blob:new-${urls}`;
        made.set(url, file);
        return url;
      },
      revokeObjectURL(url) {
        revoked.push(url);
      },
    },
    File: FakeFile,
    FileReader: FakeFileReader,
    Image: FakeImage,
    createImageBitmap(file) {
      decodes.push(file.name);
      events.push(`start ${file.name}`);
      return Promise.resolve(decodeGate(file.name)).then(() => {
        events.push(`end ${file.name}`);
        if (undecodable.includes(file.name)) {
          throw new Error('InvalidStateError');
        }
        const [width, height] = dimensions(file.name);
        return { width, height, close() {} };
      });
    },
    console: { info: (text) => infos.push(text) },
    // new Date() without arguments is the clock above; with arguments it is a plain Date.
    Date: class extends Date {
      constructor(...args) {
        super(...(args.length ? args : [clock.getTime()]));
      }
    },
    // The timers a browser provides; the time limit runs on them. Counted for the tests.
    setTimeout(callback, ms) {
      timers.set += 1;
      return setTimeout(callback, ms);
    },
    clearTimeout(handle) {
      timers.cleared += 1;
      return clearTimeout(handle);
    },
  };
  vm.runInNewContext(source, context);

  const app = {
    $panel: { upload },
    $api: {
      get(path) {
        api.calls.push(path);
        api.readsAtCall.push(upload.files.map((item) => item.src.reads));
        if (api.gate) {
          return api.gate.promise.then(() => options); // the answer comes when the test releases it
        }
        if (api.hang) {
          return new Promise(() => {}); // the answer never comes
        }
        return api.fail ? Promise.reject(new Error('offline')) : Promise.resolve(options);
      },
    },
    $t: (key, values) => key + JSON.stringify(values ?? {}),
  };
  registered[0].extension.created(app);

  return {
    app, api, upload, dialog, notifications, infos, registered, decodes, events, encodes, revoked, timers,
    urlCount: () => urls,
    tegaru: window.tegaru,
    filesField: registered[0].extension.components?.['k-files-field'],
  };
}

test('registers as sayamaapps/tegaru with a created hook', () => {
  const { registered } = setup();
  assert.equal(registered.length, 1);
  assert.equal(registered[0].name, 'sayamaapps/tegaru');
  assert.equal(typeof registered[0].extension.created, 'function');
});

test('sniff: magic bytes decide, not the name', () => {
  const { tegaru } = setup();
  assert.equal(tegaru.sniff(JPEG), 'jpeg');
  assert.equal(tegaru.sniff(PNG), 'png');
  assert.equal(tegaru.sniff(GIF), 'gif');
  assert.equal(tegaru.sniff(WEBP), 'webp');
  assert.equal(tegaru.sniff(HEIC), 'other', 'HEIC is left to the server, not recognised in the browser');
  assert.equal(tegaru.sniff(ftyp('mif1')), 'other');
  assert.equal(tegaru.sniff(AVIF), 'avif');
  assert.equal(tegaru.sniff(ftyp('isom')), 'other', 'an MP4 video is not a photo');
  assert.equal(tegaru.sniff(PDF), 'other');
  assert.equal(tegaru.sniff(ascii('<svg xmlns')), 'other');
  assert.equal(tegaru.sniff([]), 'other');
  assert.equal(tegaru.sniff(Uint8Array.from(JPEG).buffer), 'jpeg', 'an ArrayBuffer works too');
});

test('targetSize: longest edge to maxEdge, portrait too, small pictures unchanged', () => {
  const { tegaru } = setup();
  const landscape = tegaru.targetSize(4032, 3024, 2560);
  assert.equal([landscape.width, landscape.height, landscape.scale.toFixed(3)].join(' '), '2560 1920 0.635');
  const small = tegaru.targetSize(1000, 800, 2560);
  assert.equal([small.width, small.height, small.scale].join(' '), '1000 800 1');
  const portrait = tegaru.targetSize(3024, 4032, 2560);
  assert.equal([portrait.width, portrait.height].join(' '), '1920 2560');
});

test('isCameraName: the generic camera names, any case; nothing else', () => {
  const { tegaru } = setup();
  assert.equal(tegaru.isCameraName('image.jpg'), true);
  assert.equal(tegaru.isCameraName('IMAGE.JPG'), true);
  assert.equal(tegaru.isCameraName('image.jpeg'), true);
  assert.equal(tegaru.isCameraName('Image.JpEg'), true);
  assert.equal(tegaru.isCameraName('image.png'), false);
  assert.equal(tegaru.isCameraName('image1.jpg'), false);
  assert.equal(tegaru.isCameraName('myimage.jpg'), false);
  assert.equal(tegaru.isCameraName('image.jpg.bak'), false);
  assert.equal(tegaru.isCameraName('IMG_0001.jpg'), false);
});

test('stamp: the local time as YYYY-MM-DD-hh-mm-ss, with leading zeros', () => {
  const { tegaru } = setup();
  assert.equal(tegaru.stamp(new Date(2026, 0, 2, 3, 4, 5)), '2026-01-02-03-04-05');
  assert.equal(tegaru.stamp(new Date(2026, 9, 20, 23, 59, 59)), '2026-10-20-23-59-59');
});

test('cameraName: foto-<time>.jpg; -2, -3 go before the extension while the name is taken, case-blind', () => {
  const { tegaru } = setup();
  const time = '2026-10-20-14-32-05';
  assert.equal(tegaru.cameraName(time, []), 'foto-2026-10-20-14-32-05.jpg');
  assert.equal(tegaru.cameraName(time, ['IMG_1.jpg']), 'foto-2026-10-20-14-32-05.jpg', 'another name does not matter');
  assert.equal(tegaru.cameraName(time, ['FOTO-2026-10-20-14-32-05.JPG']), 'foto-2026-10-20-14-32-05-2.jpg');
  assert.equal(tegaru.cameraName(time, ['foto-2026-10-20-14-32-05.jpg', 'foto-2026-10-20-14-32-05-2.jpg']), 'foto-2026-10-20-14-32-05-3.jpg');
});

test('formatBytes: the item.niceSize format Kirby uses', () => {
  const { tegaru } = setup();
  assert.equal(tegaru.formatBytes(983000), '983KB');
  assert.equal(tegaru.formatBytes(1100000), '1.1MB');
  assert.equal(tegaru.formatBytes(5300000), '5.3MB');
});

test('decide: what is prepared, what is left alone', () => {
  const { tegaru } = setup();
  const options = { active: true, maxEdge: 2560, quality: 0.85, minBytes: 1048576 };
  const big = 2200000;
  const small = 400000;
  assert.equal(tegaru.decide({ type: 'jpeg', size: small, width: 4032, height: 3024 }, options), 'skip');
  assert.equal(tegaru.decide({ type: 'jpeg', size: big, width: 4032, height: 3024 }, options), 'resize');
  assert.equal(tegaru.decide({ type: 'jpeg', size: big, width: 1000, height: 800 }, options), 'resize', 'a big JPEG is re-encoded at any size');
  assert.equal(tegaru.decide({ type: 'jpeg', size: small, width: 4032, height: 3024 }, { ...options, minBytes: 0 }), 'resize', 'minBytes 0 processes everything');
  assert.equal(tegaru.decide({ type: 'png', size: 3000000, width: 4032, height: 3024 }, options), 'resize');
  assert.equal(tegaru.decide({ type: 'png', size: 3000000, width: 1000, height: 800 }, options), 'resize', 'PNG is re-encoded at any size, like JPEG');
  assert.equal(tegaru.decide({ type: 'png', size: 400000, width: 4032, height: 3024 }, options), 'skip', 'under minBytes');
  assert.equal(tegaru.decide({ type: 'webp', size: big, width: 4032, height: 3024 }, options), 'resize', 'WebP stays WebP');
  assert.equal(tegaru.decide({ type: 'webp', size: small, width: 4032, height: 3024 }, options), 'skip');
  assert.equal(tegaru.decide({ type: 'gif', size: big, width: 4032, height: 3024 }, options), 'skip');
  assert.equal(tegaru.decide({ type: 'avif', size: big, width: 4032, height: 3024 }, options), 'skip');
  assert.equal(tegaru.decide({ type: 'jpeg', size: big, width: 4032, height: 3024 }, { ...options, active: false }), 'skip');
});

test('select hands back the original result at once and prepares each item once', async () => {
  const s = setup();
  const a = photo('IMG_0002.jpeg', JPEG, { size: 400000 });
  const b = photo('IMG_0003.jpeg', JPEG, { size: 400000 });
  assert.equal(s.upload.select([a]), 'dialog');
  assert.equal(s.upload.files[0].tegaru, 'pending', 'marked before any await');
  s.upload.select([b]);
  await settle();
  assert.equal(a.reads, 1);
  assert.equal(b.reads, 1);
  assert.equal(s.api.calls.length, 1, 'options fetched once per session');
  assert.deepEqual(s.upload.files.map((item) => item.tegaru), ['kept', 'kept']);
  assert.deepEqual(s.decodes, [], 'small files are not decoded');
});

test('submit waits for every preparation, then calls the original with its arguments', async () => {
  const s = setup();
  const gate = deferred();
  const a = photo('IMG_0002.jpeg', JPEG, { size: 400000, gate: gate.promise });
  const b = photo('IMG_0003.jpeg', JPEG, { size: 400000 });
  s.upload.select([a]);
  s.upload.select([b]);
  const result = s.upload.submit('upload-arg');
  await settle();
  assert.equal(s.upload.submits.length, 0, 'the original waits for the open preparation');
  gate.resolve();
  assert.equal(await result, 'submitted');
  assert.equal(s.upload.submits.length, 1);
  assert.equal(s.upload.submits[0].self, s.upload);
  assert.deepEqual(s.upload.submits[0].args, ['upload-arg']);
  assert.deepEqual(s.upload.submits[0].marks, ['kept', 'kept'], 'every item had settled before the upload');
});

test('submit also waits for an item selected while it is already waiting', async () => {
  const s = setup();
  const first = deferred();
  const late = deferred();
  s.upload.select([photo('IMG_0001.jpeg', JPEG, { size: 400000, gate: first.promise })]);
  const result = s.upload.submit('later');
  await settle();
  s.upload.select([photo('IMG_0003.jpeg', JPEG, { size: 400000, gate: late.promise })]);
  first.resolve();
  await settle();
  assert.equal(s.upload.submits.length, 0, 'the item selected meanwhile is still being read');
  late.resolve();
  assert.equal(await result, 'submitted');
  assert.deepEqual(s.upload.submits[0].marks, ['kept', 'kept']);
});

test('a failed preparation does not block submit, and the next select asks again', async () => {
  const s = setup();
  s.api.fail = true;
  const a = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  assert.equal(s.upload.submits.length, 1);
  assert.strictEqual(s.upload.files[0].src, a, 'uploaded as it is');
  assert.deepEqual(s.upload.submits[0].marks, ['kept']);
  s.api.fail = false;
  s.upload.select([photo('IMG_0003.jpeg', JPEG, { size: 400000 })]);
  await settle();
  assert.equal(s.api.calls.length, 2, 'the next select asks the API again');
});

test('a picture still not ready at the time limit goes up as it is; submit is not held', async () => {
  const s = setup({ decodeGate: () => new Promise(() => {}) });
  s.tegaru.timeLimit = 10;
  const a = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  assert.equal(await s.upload.submit(), 'submitted');
  assert.strictEqual(s.upload.files[0].src, a, 'the original stays');
  assert.deepEqual(s.upload.submits[0].marks, ['kept']);
  assert.deepEqual(s.notifications, []);
});

test('a result that arrives after the time limit changes nothing', async () => {
  const late = deferred();
  const s = setup({ decodeGate: () => late.promise, encodedSize: () => 1000000 });
  s.tegaru.timeLimit = 10;
  const a = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  late.resolve();
  await settle();
  const [item] = s.upload.files;
  assert.strictEqual(item.src, a, 'the original file stays');
  assert.equal(item.url, 'blob:original-1', 'the preview is not replaced');
  assert.equal(item.tegaru, 'kept');
  assert.deepEqual(s.revoked, [], 'no preview URL was freed');
  assert.deepEqual(s.notifications, []);
});

test('a resized JPEG keeps its name and extension and replaces its item; announced once', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  const a = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  await settle();
  const [item] = s.upload.files;
  assert.equal(item.src.name, 'IMG_0002.jpeg');
  assert.equal(item.src.type, 'image/jpeg');
  assert.equal(item.src.lastModified, 1000, 'the original date is kept');
  assert.equal(item.name, 'IMG_0002');
  assert.equal(item.extension, 'jpeg');
  assert.equal(item.filename, 'IMG_0002.jpeg');
  assert.equal(item.size, 1000000);
  assert.equal(item.niceSize, '1MB');
  assert.equal(item.type, 'image/jpeg');
  assert.notEqual(item.url, 'blob:original-1');
  assert.deepEqual(s.revoked, ['blob:original-1'], 'the old preview URL is freed');
  assert.equal(item.tegaru.from, 2200000);
  assert.equal(item.tegaru.to, 1000000);
  assert.deepEqual(s.encodes, [{ type: 'image/jpeg', quality: 0.85, width: 2560, height: 1920 }]);
  assert.deepEqual(s.notifications, [['success', 'tegaru.panel.resizedOne{"from":"2.2MB","to":"1MB"}']]);
});

test('several resized photos are announced once, with the sums', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  s.upload.select([
    photo('IMG_0002.jpeg', JPEG, { size: 2200000 }),
    photo('IMG_0003.jpeg', JPEG, { size: 2200000 }),
  ]);
  await s.upload.submit();
  await settle();
  assert.deepEqual(s.notifications, [['success', 'tegaru.panel.resized{"count":2,"from":"4.4MB","to":"2MB"}']]);
});

test('no message when the dialog was closed before the pictures were ready', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  s.upload.files = [];
  await settle();
  assert.deepEqual(s.notifications, []);
});

test('a WebP over the threshold is re-encoded as WebP with the quality setting; its name stays', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  s.upload.select([photo('card.webp', WEBP, { size: 2200000, type: 'image/webp' })]);
  await s.upload.submit();
  await settle();
  const [item] = s.upload.files;
  assert.equal(item.src.name, 'card.webp');
  assert.equal(item.src.type, 'image/webp');
  assert.equal(item.extension, 'webp');
  assert.equal(item.type, 'image/webp');
  assert.deepEqual(s.encodes, [{ type: 'image/webp', quality: 0.85, width: 2560, height: 1920 }]);
  assert.deepEqual(s.notifications, [['success', 'tegaru.panel.resizedOne{"from":"2.2MB","to":"1MB"}']]);
});

test('a PNG under maxEdge is re-encoded at its own size too; it stays PNG', async () => {
  const s = setup({ encodedSize: () => 900000, dimensions: () => [1000, 800] });
  s.upload.select([photo('shot.png', PNG, { size: 1500000, type: 'image/png' })]);
  await s.upload.submit();
  await settle();
  assert.deepEqual(s.encodes, [{ type: 'image/png', quality: undefined, width: 1000, height: 800 }]);
  assert.equal(s.upload.files[0].src.type, 'image/png');
  assert.equal(s.upload.files[0].tegaru.to, 900000);
});

test('a PNG under maxEdge that is not smaller after re-encoding keeps the original', async () => {
  const s = setup({ encodedSize: () => 1600000, dimensions: () => [1000, 800] });
  const a = photo('shot.png', PNG, { size: 1500000, type: 'image/png' });
  s.upload.select([a]);
  await s.upload.submit();
  await settle();
  assert.strictEqual(s.upload.files[0].src, a);
  assert.deepEqual(s.notifications, []);
});

test('a browser that cannot encode WebP hands back PNG: the original WebP stays', async () => {
  const s = setup({ encodeAs: (type) => (type === 'image/webp' ? 'image/png' : type) });
  const a = photo('card.webp', WEBP, { size: 2200000, type: 'image/webp' });
  s.upload.select([a]);
  await s.upload.submit();
  await settle();
  assert.strictEqual(s.upload.files[0].src, a);
  assert.deepEqual(s.upload.submits[0].marks, ['kept']);
  assert.deepEqual(s.notifications, []);
});

test('a picture the browser cannot read goes up unchanged, with an info message', async () => {
  const s = setup({ undecodable: ['broken.jpeg'] });
  const a = photo('broken.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  await settle();
  assert.strictEqual(s.upload.files[0].src, a);
  assert.deepEqual(s.notifications, [['info', 'tegaru.panel.unreadable{"name":"broken.jpeg"}']]);
});

test('a camera capture named image.jpg is renamed to foto-<local time>.jpg, and resized as before', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  s.upload.select([photo('image.jpg', JPEG, { size: 2200000 })]);
  await s.upload.submit();
  await settle();
  const [item] = s.upload.files;
  assert.equal(item.src.name, 'foto-2026-10-20-14-32-05.jpg');
  assert.equal(item.filename, 'foto-2026-10-20-14-32-05.jpg');
  assert.equal(item.name, 'foto-2026-10-20-14-32-05', 'Kirby keeps the name without the extension');
  assert.equal(item.extension, 'jpg');
  assert.equal(item.src.type, 'image/jpeg');
  assert.equal(item.src.lastModified, 1000, 'the original date is kept');
  assert.equal(item.tegaru.to, 1000000, 'still resized');
  assert.deepEqual(s.notifications, [['success', 'tegaru.panel.resizedOne{"from":"2.2MB","to":"1MB"}']]);
});

test('a camera capture under minBytes is renamed too, so the next capture does not clash with it', async () => {
  const s = setup();
  s.upload.select([photo('Image.JPEG', JPEG, { size: 400000 })]);
  await s.upload.submit();
  await settle();
  assert.equal(s.upload.files[0].filename, 'foto-2026-10-20-14-32-05.jpg');
  assert.deepEqual(s.decodes, [], 'not decoded, only renamed');
  assert.deepEqual(s.upload.submits[0].marks, ['kept']);
  assert.deepEqual(s.notifications, []);
});

test('two captures in the same second get -2 and -3 before the extension, also across selections', async () => {
  const s = setup();
  s.upload.select([photo('image.jpg', JPEG, { size: 400000 }), photo('image.jpg', JPEG, { size: 400000 })]);
  await settle();
  s.upload.select([photo('IMAGE.JPG', JPEG, { size: 400000 })]);
  await settle();
  assert.deepEqual(s.upload.files.map((item) => item.filename), [
    'foto-2026-10-20-14-32-05.jpg',
    'foto-2026-10-20-14-32-05-2.jpg',
    'foto-2026-10-20-14-32-05-3.jpg',
  ]);
});

test('with Tegaru inactive a camera capture keeps its name', async () => {
  const s = setup({ options: { ...SERVER, active: false } });
  const a = photo('image.jpg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  await settle();
  assert.strictEqual(s.upload.files[0].src, a);
  assert.equal(s.upload.files[0].filename, 'image.jpg');
  assert.equal(s.upload.submits[0].marks.length, 1);
});

test('only a JPEG is renamed: a PNG named image.jpg keeps its name and type', async () => {
  const s = setup({ encodedSize: () => 900000 });
  s.upload.select([photo('image.jpg', PNG, { size: 2200000, type: 'image/png' })]);
  await s.upload.submit();
  await settle();
  assert.equal(s.upload.files[0].src.name, 'image.jpg');
  assert.equal(s.upload.files[0].src.type, 'image/png');
});

test('other names are not camera names: myimage.jpg and IMG_0002.jpeg keep theirs', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  s.upload.select([photo('myimage.jpg', JPEG, { size: 2200000 }), photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  await s.upload.submit();
  await settle();
  assert.deepEqual(s.upload.files.map((item) => item.filename), ['myimage.jpg', 'IMG_0002.jpeg']);
});

test('a name typed before the settings arrive is kept: a late answer does not rename a camera capture over it', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  s.api.gate = deferred();
  s.upload.select([photo('image.jpg', JPEG, { size: 2200000 })]);
  await settle();
  assert.equal(s.api.calls.length, 1, 'the settings are being asked for');
  const [item] = s.upload.files;
  item.name = 'portrait'; // the user renames in the open dialog while the answer is on its way
  s.api.gate.resolve();
  await s.upload.submit();
  assert.equal(item.name, 'portrait');
  assert.equal(item.filename, 'image.jpg');
  assert.equal(item.src.name, 'image.jpg');
  assert.equal(item.tegaru.to, 1000000, 'still resized');
  assert.deepEqual(s.notifications, [['success', 'tegaru.panel.resizedOne{"from":"2.2MB","to":"1MB"}']]);
});

test('settings that never arrive: a camera capture keeps its name, since Tegaru cannot tell it is active', async () => {
  const s = setup();
  s.api.hang = true;
  s.tegaru.timeLimit = 10;
  const a = photo('image.jpg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  assert.equal(await s.upload.submit(), 'submitted');
  assert.strictEqual(s.upload.files[0].src, a);
  assert.equal(s.upload.files[0].filename, 'image.jpg');
});

test('a large PNG is resized and stays PNG; the encoder gets no quality setting', async () => {
  const s = setup({ encodedSize: () => 900000 });
  s.upload.select([photo('shot.png', PNG, { size: 3000000, type: 'image/png' })]);
  await s.upload.submit();
  await settle();
  const [item] = s.upload.files;
  assert.equal(item.src.name, 'shot.png');
  assert.equal(item.src.type, 'image/png');
  assert.equal(item.extension, 'png');
  assert.deepEqual(s.encodes, [{ type: 'image/png', quality: undefined, width: 2560, height: 1920 }]);
});

test('a result that is not smaller keeps the original', async () => {
  const s = setup({ encodedSize: () => 2500000 });
  const a = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  await settle();
  assert.strictEqual(s.upload.files[0].src, a);
  assert.equal(s.upload.files[0].tegaru, 'kept');
  assert.deepEqual(s.notifications, []);
});

test('at most two pictures are decoded at a time; the first ready picture takes the next free slot', async () => {
  const names = ['IMG_0001.jpeg', 'IMG_0002.jpeg', 'IMG_0003.jpeg', 'IMG_0004.jpeg', 'IMG_0005.jpeg'];
  const gates = new Map(names.map((name) => [name, deferred()]));
  const s = setup({ decodeGate: (name) => gates.get(name)?.promise ?? null });
  s.upload.select(names.map((name) => photo(name, JPEG, { size: 2200000 })));
  const done = s.upload.submit();
  await settle();
  assert.deepEqual(s.events, ['start IMG_0001.jpeg', 'start IMG_0002.jpeg'], 'two start, three wait');

  gates.get('IMG_0002.jpeg').resolve();
  await settle();
  assert.deepEqual(s.events.slice(2), ['end IMG_0002.jpeg', 'start IMG_0003.jpeg'], 'the next in line takes the free slot');

  gates.get('IMG_0001.jpeg').resolve();
  await settle();
  assert.deepEqual(s.events.slice(4), ['end IMG_0001.jpeg', 'start IMG_0004.jpeg']);

  gates.get('IMG_0003.jpeg').resolve();
  gates.get('IMG_0004.jpeg').resolve();
  gates.get('IMG_0005.jpeg').resolve();
  await settle();
  assert.equal(await done, 'submitted');

  const starts = s.events.filter((e) => e.startsWith('start ')).map((e) => e.slice(6));
  assert.deepEqual(starts, names, 'decodes start in the order they became ready (here the order of selection)');
  let inFlight = 0;
  let peak = 0;
  for (const e of s.events) {
    inFlight += e.startsWith('start ') ? 1 : -1;
    peak = Math.max(peak, inFlight);
  }
  assert.equal(peak, 2, 'never more than two at once');
  assert.equal(s.encodes.length, 5, 'every picture was encoded');
});

test('GIF, HEIC, AVIF and PDF are neither decoded nor changed; HEIC is left to the server', async () => {
  const s = setup();
  const files = [
    photo('loop.gif', GIF, { size: 3000000, type: 'image/gif' }),
    photo('IMG_0001.HEIC', HEIC, { size: 3000000, type: 'image/heic' }),
    photo('shot.avif', AVIF, { size: 3000000, type: 'image/avif' }),
    photo('scan.pdf', PDF, { size: 3000000, type: 'application/pdf' }),
  ];
  s.upload.select(files);
  await s.upload.submit();
  await settle();
  assert.deepEqual(s.decodes, []);
  files.forEach((file, i) => assert.strictEqual(s.upload.files[i].src, file));
  assert.deepEqual(s.upload.submits[0].marks, ['kept', 'kept', 'kept', 'kept']);
  assert.deepEqual(s.upload.removed, [], 'nothing is removed from the upload');
  assert.deepEqual(s.notifications, []);
});

test('inactive: nothing is decoded or changed; the first bytes are read locally, nothing is sent', async () => {
  const s = setup({ options: { ...SERVER, active: false } });
  const a = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  await settle();
  assert.equal(a.reads, 1, 'the head is read once');
  assert.deepEqual(s.api.calls, ['tegaru/options'], 'the settings are asked for once');
  assert.deepEqual(s.api.readsAtCall, [[1]], 'the head was read before the settings were asked for');
  assert.deepEqual(s.decodes, []);
  assert.strictEqual(s.upload.files[0].src, a);
});

test('without select and submit: one console.info, nothing wrapped', () => {
  const upload = { files: [], submit() { return 'original'; } };
  const submit = upload.submit;
  const s = setup({ upload });
  assert.equal(s.infos.length, 1);
  assert.match(s.infos[0], /panel\.upload not found/);
  assert.strictEqual(upload.submit, submit);
  assert.equal(upload.select, undefined);
  assert.deepEqual(s.api.calls, []);
});

test('the time limit is 20 seconds by default', () => {
  assert.equal(setup().tegaru.timeLimit, 20000);
});

test('a picture ready before the limit clears its timer', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  await s.upload.submit();
  assert.ok(s.timers.set >= 1);
  assert.equal(s.timers.cleared, s.timers.set, 'every timer (time limit, poll) is cleared when the picture settles first');
  assert.equal(s.upload.files[0].tegaru.to, 1000000);
});

test('a JPEG past the time limit goes up as it is: no removal, no message', async () => {
  const s = setup({ decodeGate: () => new Promise(() => {}) });
  s.tegaru.timeLimit = 10;
  const a = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  assert.strictEqual(s.upload.files[0].src, a);
  assert.equal(s.upload.files[0].tegaru, 'kept');
  assert.deepEqual(s.upload.removed, []);
  assert.deepEqual(s.notifications, []);
});

test('an item that expires while it waits for a slot is never decoded', async () => {
  const second = deferred();
  const s = setup({
    decodeGate: (name) => {
      if (name === 'IMG_0001.jpeg') return new Promise(() => {});
      if (name === 'IMG_0002.jpeg') return second.promise;
      return null;
    },
  });
  s.tegaru.timeLimit = 10;
  s.upload.select([
    photo('IMG_0001.jpeg', JPEG, { size: 2200000 }),
    photo('IMG_0002.jpeg', JPEG, { size: 2200000 }),
    photo('IMG_0003.jpeg', JPEG, { size: 2200000 }),
  ]);
  assert.equal(await s.upload.submit(), 'submitted');
  assert.deepEqual(s.decodes, ['IMG_0001.jpeg', 'IMG_0002.jpeg'], 'two slots taken, the third waits');
  second.resolve(); // the second decode ends after the limit and frees its slot
  await settle();
  assert.deepEqual(s.decodes, ['IMG_0001.jpeg', 'IMG_0002.jpeg'], 'the expired item in the queue is never decoded');
  assert.deepEqual(s.upload.submits[0].marks, ['kept', 'kept', 'kept']);
});

test('a result that arrives after the item left the dialog creates no URL and no message', async () => {
  const gate = deferred();
  const s = setup({ decodeGate: () => gate.promise, encodedSize: () => 1000000 });
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  const urlsBefore = s.urlCount();
  s.upload.files = []; // the dialog drops the item while it is still being prepared
  gate.resolve();
  await s.upload.submit();
  await settle();
  assert.equal(s.urlCount(), urlsBefore, 'no object URL for an item that is gone');
  assert.deepEqual(s.revoked, []);
  assert.deepEqual(s.notifications, []);
});

test('dialog cancelled: an undecodable picture that returns later gets no message', async () => {
  const gate = deferred();
  const s = setup({ undecodable: ['IMG_0001.jpeg', 'IMG_0002.jpeg'], decodeGate: () => gate.promise });
  s.upload.select([
    photo('IMG_0001.jpeg', JPEG, { size: 2200000 }),
    photo('IMG_0002.jpeg', JPEG, { size: 2200000 }),
  ]);
  await settle();
  s.upload.files.splice(0);
  gate.resolve();
  await s.upload.submit();
  await settle();
  assert.deepEqual(s.upload.removed, []);
  assert.deepEqual(s.notifications, []);
});

test('a cancelled selection frees the way: its waiting items are not decoded and submit does not wait for them', async () => {
  const second = deferred();
  const s = setup({
    decodeGate: (name) => {
      if (name === 'IMG_0001.jpeg') return new Promise(() => {}); // holds one slot for good
      if (name === 'IMG_0002.jpeg') return second.promise; // holds the other until released
      return null;
    },
    encodedSize: () => 1000000,
  });
  s.tegaru.timeLimit = 50;
  s.upload.select([
    photo('IMG_0001.jpeg', JPEG, { size: 2200000 }),
    photo('IMG_0002.jpeg', JPEG, { size: 2200000 }),
    photo('IMG_0003.jpeg', JPEG, { size: 2200000 }), // waits for a slot
  ]);
  await settle();
  assert.deepEqual(s.decodes, ['IMG_0001.jpeg', 'IMG_0002.jpeg']);
  s.upload.files.splice(0); // Kirby's cancel() empties the list
  s.upload.select([photo('IMG_0004.jpeg', JPEG, { size: 2200000 })]);
  await settle();
  second.resolve(); // one slot frees: the cancelled third item is passed over, the new photo is decoded
  assert.equal(await s.upload.submit(), 'submitted', 'submit does not wait for the cancelled, hanging item');
  assert.deepEqual(s.decodes, ['IMG_0001.jpeg', 'IMG_0002.jpeg', 'IMG_0004.jpeg'], 'the cancelled item in the queue is never decoded');
  assert.equal(s.upload.files.length, 1);
  assert.equal(s.upload.files[0].tegaru.to, 1000000, 'the new photo was replaced');
  await new Promise((resolve) => setTimeout(resolve, 80)); // the cancelled items pass their limit quietly
  assert.deepEqual(s.notifications.filter(([kind]) => kind !== 'success'), []);
});

test('cancelled while waiting: the click resolves at once, uploads nothing, and a photo picked afterwards waits for its own click', async () => {
  const first = deferred();
  const s = setup({
    decodeGate: (name) => (name === 'IMG_0001.jpeg' ? first.promise : null),
    encodedSize: () => 1000000,
  });
  s.upload.select([photo('IMG_0001.jpeg', JPEG, { size: 2200000 })]);
  const click = s.upload.submit(); // the upload button, while the photo is still being prepared
  await settle();
  s.upload.reset(); // Kirby's cancel()
  assert.equal(await click, undefined, 'the click resolves without waiting for the decode, and uploads nothing');
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]); // a new dialog
  await settle();
  assert.deepEqual(s.upload.calls, ['select', 'reset', 'select'], "Kirby's submit was not called");
  assert.equal(s.upload.files.length, 1);
  assert.equal(s.upload.files[0].tegaru.to, 1000000, 'the new photo is prepared and waits in the dialog');
  assert.equal(await s.upload.submit(), 'submitted', 'its own click uploads it');
  assert.equal(s.upload.submits[0].marks.length, 1);
  first.resolve(); // the old decode ends later and changes nothing
  await settle();
  assert.equal(s.upload.submits.length, 1);
});

test('a photo cut by Kirby while the click waits is no cancel: the click uploads what the dialog holds', async () => {
  const first = deferred();
  const s = setup({
    decodeGate: (name) => (name === 'IMG_0001.jpeg' ? first.promise : null),
    encodedSize: () => 1000000,
  });
  const a = photo('IMG_0001.jpeg', JPEG, { size: 2200000 });
  s.tegaru.timeLimit = 200; // the cut photo's decode never ends; its timer must not keep the process alive
  s.upload.select([a]);
  const click = s.upload.submit();
  await settle();
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  s.upload.files = s.upload.files.filter((item) => item.src !== a); // max: 1 cuts the first
  assert.equal(await click, 'submitted');
  assert.equal(s.upload.submits[0].marks.length, 1);
  assert.equal(typeof s.upload.submits[0].marks[0], 'object', 'the remaining photo was prepared');
});

test('a photo added to the open dialog while it waits is prepared and goes up with the same click', async () => {
  const first = deferred();
  const s = setup({
    decodeGate: (name) => (name === 'IMG_0001.jpeg' ? first.promise : null),
    encodedSize: () => 1000000,
  });
  s.upload.select([photo('IMG_0001.jpeg', JPEG, { size: 2200000 })]);
  const click = s.upload.submit();
  await settle();
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]); // dropped onto the open dialog
  first.resolve();
  assert.equal(await click, 'submitted');
  assert.deepEqual(s.upload.submits[0].marks.map((mark) => typeof mark), ['object', 'object'], 'both prepared before the upload');
});

test('dialog closed by Escape (no reset): the click uploads nothing; the next dialog has its own click', async () => {
  const first = deferred();
  const s = setup({
    decodeGate: (name) => (name === 'IMG_0001.jpeg' ? first.promise : null),
    encodedSize: () => 1000000,
  });
  s.dialog.isOpen = true;
  s.dialog.id = 'dialog-1';
  s.upload.select([photo('IMG_0001.jpeg', JPEG, { size: 2200000 })]);
  const click = s.upload.submit();
  await settle();
  s.dialog.isOpen = false; // Escape closes the Panel dialog; Kirby's upload store keeps its files
  s.dialog.id = null;
  assert.equal(await click, undefined, 'noticed by the poll, long before the decode ends');
  assert.deepEqual(s.upload.calls, ['select'], "Kirby's submit was not called");
  s.dialog.isOpen = true;
  s.dialog.id = 'dialog-2';
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  const second = s.upload.submit();
  first.resolve();
  assert.equal(await second, 'submitted');
  assert.deepEqual(s.upload.calls, ['select', 'select', 'submit'], 'one upload, by its own click');
});

test('two immediate picks: only the latest click uploads, nothing goes up twice', async () => {
  const first = deferred();
  const s = setup({
    decodeGate: (name) => (name === 'IMG_0001.jpeg' ? first.promise : null),
    encodedSize: () => 1000000,
  });
  s.upload.select([photo('IMG_0001.jpeg', JPEG, { size: 2200000 })]);
  const click1 = s.upload.submit();
  await settle();
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  const click2 = s.upload.submit();
  first.resolve();
  assert.equal(await click1, undefined, 'superseded');
  assert.equal(await click2, 'submitted');
  assert.deepEqual(s.upload.calls.filter((call) => call === 'submit'), ['submit']);
  assert.equal(s.upload.submits[0].marks.length, 2, 'both photos, prepared, in the one upload');
});

test('a JPEG whose encoder throws goes up as it is, without a message', async () => {
  const s = setup({ encodeError: new Error('canvas tainted') });
  const a = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  s.upload.select([a]);
  await s.upload.submit();
  assert.strictEqual(s.upload.files[0].src, a);
  assert.deepEqual(s.notifications, []);
});

test('a photo removed by hand while the click waits is not waited for', async () => {
  const s = setup({
    decodeGate: (name) => (name === 'IMG_0001.jpeg' ? new Promise(() => {}) : null),
    encodedSize: () => 1000000,
  });
  s.tegaru.timeLimit = 2000; // well above the poll, so only the poll can end the wait this fast
  s.upload.select([
    photo('IMG_0001.jpeg', JPEG, { size: 2200000 }),
    photo('IMG_0002.jpeg', JPEG, { size: 2200000 }),
  ]);
  const click = s.upload.submit();
  await settle();
  s.upload.remove(1); // the x on the first item
  const started = Date.now();
  assert.equal(await click, 'submitted');
  assert.ok(Date.now() - started < 1000, 'noticed by the poll (100 ms), not after the time limit (2 s)');
  assert.equal(s.upload.submits[0].marks.length, 1);
  assert.equal(typeof s.upload.submits[0].marks[0], 'object');
});

test('a photo that leaves the dialog during its decode is not encoded', async () => {
  const gate = deferred();
  const s = setup({ decodeGate: () => gate.promise, encodedSize: () => 1000000 });
  s.upload.select([photo('IMG_0001.jpeg', JPEG, { size: 2200000 })]);
  await settle();
  s.upload.remove(1);
  gate.resolve();
  await s.upload.submit();
  await settle();
  assert.deepEqual(s.encodes, [], 'decoded, but not encoded');
  assert.deepEqual(s.notifications, []);
});

test('without the Panel dialog store: one console.info, the script still runs', async () => {
  const s = setup({ encodedSize: () => 1000000, dialogStore: false });
  assert.equal(s.infos.length, 1);
  assert.match(s.infos[0], /panel\.dialog not found/);
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  assert.equal(await s.upload.submit(), 'submitted');
  assert.equal(s.upload.files[0].tegaru.to, 1000000);
});

test('a dialog reopened without an id is told apart by its props object: the old click uploads nothing', async () => {
  // Kirby 5.6 gives every dialog an id; this covers a Panel that would not, through the second key.
  const first = deferred();
  const s = setup({
    decodeGate: (name) => (name === 'IMG_0001.jpeg' ? first.promise : null),
    encodedSize: () => 1000000,
  });
  s.dialog.isOpen = true;
  s.dialog.id = null;
  s.dialog.props = { preview: {} };
  s.upload.select([photo('IMG_0001.jpeg', JPEG, { size: 2200000 })]);
  const click = s.upload.submit();
  await settle();
  s.dialog.isOpen = false; // closed by Escape ...
  s.dialog.props = null;
  s.dialog.isOpen = true; // ... and reopened at once, again without an id
  s.dialog.props = { preview: {} };
  first.resolve();
  assert.equal(await click, undefined);
  assert.deepEqual(s.upload.calls, ['select']);
});

test('the same photo picked again after its item was prepared is dropped; the prepared item stays', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  const same = () => photo('image.jpg', JPEG, { size: 2200000 });
  s.upload.select([same()]);
  await settle();
  assert.equal(s.upload.files[0].filename, 'foto-2026-10-20-14-32-05.jpg', 'prepared and renamed');
  s.upload.select([same()]); // the fake select does not filter duplicates, as Kirby no longer can here
  await s.upload.submit();
  assert.equal(s.upload.files.length, 1, 'the re-picked original is dropped');
  assert.deepEqual(s.upload.removed, [2]);
  assert.equal(s.decodes.length, 1, 'decoded once');
  assert.equal(s.notifications.length, 1, 'one message, from the first selection');
});

test('a different photo with the same name is not a duplicate: size, type or modification time differ', async () => {
  const s = setup({ encodedSize: () => 1000000 });
  s.upload.select([photo('IMG_0002.jpeg', JPEG, { size: 2200000 })]);
  await settle();
  const otherTime = photo('IMG_0002.jpeg', JPEG, { size: 2200000 });
  otherTime.lastModified = 2000;
  s.upload.select([
    photo('IMG_0002.jpeg', JPEG, { size: 2300000 }), // another size
    photo('IMG_0002.jpeg', JPEG, { size: 2200000, type: 'image/pjpeg' }), // another type
    otherTime, // another modification time
  ]);
  await s.upload.submit();
  assert.equal(s.upload.files.length, 4);
  assert.deepEqual(s.upload.removed, []);
});


// Kirby's files field as far as the extension uses it. When Kirby registers
// the component it replaces the extension's extends string with its own files
// field; inherit() does that with a stand-in whose open and buttons are
// Kirby's in shape: open lists the files already there, buttons has Upload
// (while the field takes uploads) and Select, both of which Kirby wires to the
// field. The field instance is built the way Vue merges the extension over it.
function inherit(s, { members = 'kirby' } = {}) {
  const log = [];
  const kirby = {
    methods: {
      open() {
        log.push('list');
        return 'files dialog';
      },
    },
    computed: {
      buttons() {
        const buttons = [{ text: 'select', icon: 'checklist', click: () => this.open() }];
        if (this.hasDropzone) {
          buttons.unshift({ text: 'upload', icon: 'upload', click: () => this.$panel.upload.pick(this.uploadOptions) });
        }
        return buttons;
      },
    },
  };
  s.filesField.extends = { options: members === 'kirby' ? kirby : members };
  return log;
}

function mountField(s, { uploads = { accept: 'image/jpeg' }, disabled = false, more = true } = {}) {
  const def = s.filesField;
  const field = {
    disabled,
    more,
    uploads,
    $panel: { upload: s.upload },
    // Kirby: !disabled && more && uploads (the uploads object, not true).
    get hasDropzone() {
      return !this.disabled && this.more && this.uploads;
    },
    get uploadOptions() {
      return { accept: this.uploads.accept, url: '/api/pages/home/fields/photo/upload' };
    },
    get buttons() {
      return def.computed.buttons.call(this);
    },
    open() {
      return def.methods.open.call(this);
    },
  };
  def.mounted.call(field);
  return field;
}

const button = (field, icon) => field.buttons.find((b) => b.icon === icon);

test('registers an extension of Kirby\'s files field next to the created hook', () => {
  const { filesField } = setup();
  assert.equal(filesField.extends, 'k-files-field');
  assert.equal(typeof filesField.methods.open, 'function');
  assert.equal(typeof filesField.computed.buttons, 'function');
  assert.equal(typeof filesField.mounted, 'function');
});

test('active: a tap on the empty field opens the device\'s picker with the Upload button\'s options', async () => {
  const s = setup();
  const log = inherit(s);
  const field = mountField(s);
  await settle();
  field.open(); // what Kirby's empty field calls
  assert.deepEqual(s.upload.calls, ['pick']);
  assert.deepEqual(s.upload.picked, field.uploadOptions);
  assert.deepEqual(log, [], 'the list of files already there is not opened');
});

test('active: Select still lists the files already there; Upload stays Kirby\'s', async () => {
  const s = setup();
  const log = inherit(s);
  const field = mountField(s);
  await settle();
  assert.deepEqual(field.buttons.map((b) => b.icon), ['upload', 'checklist'], 'order and buttons unchanged');
  assert.equal(button(field, 'checklist').click(), 'files dialog');
  assert.deepEqual(log, ['list']);
  assert.deepEqual(s.upload.calls, []);
  button(field, 'upload').click();
  assert.deepEqual(s.upload.calls, ['pick']);
});

test('the settings are asked for when a field appears, once per Panel session', async () => {
  const s = setup();
  inherit(s);
  mountField(s);
  mountField(s);
  await settle();
  s.upload.select([photo('IMG_0001.jpeg', JPEG, { size: 2200000 })]);
  await settle();
  assert.deepEqual(s.api.calls, ['tegaru/options']);
});

test('inactive: the empty field lists the files already there, as in Kirby', async () => {
  const s = setup({ options: { ...SERVER, active: false } });
  const log = inherit(s);
  const field = mountField(s);
  await settle();
  assert.equal(field.open(), 'files dialog');
  assert.deepEqual(log, ['list']);
  assert.deepEqual(s.upload.calls, []);
});

test('settings not there yet: a tap lists the files, since the picker cannot wait for the answer', async () => {
  const s = setup();
  s.api.hang = true;
  const log = inherit(s);
  const field = mountField(s);
  await settle();
  field.open();
  assert.deepEqual(log, ['list']);
  assert.deepEqual(s.upload.calls, []);
});

test('settings failed: a tap lists the files; the next field asks again and then picks', async () => {
  const s = setup();
  s.api.fail = true;
  const log = inherit(s);
  const first = mountField(s);
  await settle();
  first.open();
  assert.deepEqual(log, ['list']);
  s.api.fail = false;
  const second = mountField(s);
  await settle();
  second.open();
  assert.deepEqual(s.api.calls, ['tegaru/options', 'tegaru/options']);
  assert.deepEqual(s.upload.calls, ['pick']);
});

test('a field that takes no uploads, is disabled or full lists the files, as in Kirby', async () => {
  const s = setup();
  const log = inherit(s);
  const fields = [mountField(s, { uploads: false }), mountField(s, { disabled: true }), mountField(s, { more: false })];
  await settle();
  fields.forEach((field) => field.open());
  assert.deepEqual(log, ['list', 'list', 'list']);
  assert.deepEqual(s.upload.calls, []);
});

test('Kirby without the Select button: the empty field keeps listing the files, so they stay reachable', async () => {
  const s = setup();
  const log = [];
  inherit(s, {
    members: {
      methods: { open: () => log.push('list') },
      computed: { buttons: () => [{ text: 'upload', icon: 'upload', click() {} }] },
    },
  });
  const field = mountField(s);
  await settle();
  field.open();
  assert.deepEqual(log, ['list']);
  assert.deepEqual(s.upload.calls, []);
});

test('Kirby without open or buttons: nothing throws, the field has no buttons of Tegaru\'s', async () => {
  const s = setup({ options: { ...SERVER, active: false } });
  inherit(s, { members: {} });
  const field = mountField(s);
  await settle();
  assert.equal(field.open(), null);
  assert.equal(field.buttons.length, 0);
});

test('without the upload module: nothing is asked for and the empty field lists the files', async () => {
  const upload = { files: [], submit() { return 'original'; } };
  const s = setup({ upload });
  const log = inherit(s);
  const field = mountField(s);
  await settle();
  field.open();
  assert.deepEqual(s.api.calls, []);
  assert.deepEqual(log, ['list']);
});

test('a computed with getter and setter is read through its getter', async () => {
  const s = setup();
  const log = inherit(s);
  const buttons = s.filesField.extends.options.computed.buttons;
  s.filesField.extends.options.computed.buttons = { get: buttons, set() {} };
  const field = mountField(s);
  await settle();
  assert.deepEqual(field.buttons.map((b) => b.icon), ['upload', 'checklist']);
  button(field, 'checklist').click();
  assert.deepEqual(log, ['list']);
});
