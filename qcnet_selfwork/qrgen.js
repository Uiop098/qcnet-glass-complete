/*
 * qrgen.js — minimal, self-contained QR code encoder.
 *
 * Renders any short text/URL as a scannable QR code (SVG), entirely
 * client-side, with zero external dependencies (matches the rest of
 * this app: no CDN scripts anywhere). Supports QR versions 1-5, byte
 * mode, error-correction level L, single Reed-Solomon block — which
 * comfortably covers a URL like:
 *   https://your-domain.com/qr_upload.php?t=<64 hex chars>
 * (that's roughly 90 characters; version 5 holds up to 108 bytes).
 *
 * If a caller ever needs to encode something longer than that, this
 * throws rather than silently producing a broken/unscannable code —
 * grow qr_send.php's token format instead of extending this file's
 * effort budget past what's actually needed here.
 *
 * The core algorithm (finder/timing/alignment placement, Reed-Solomon
 * error correction over GF(256), the zigzag codeword scan, and format
 * -info BCH encoding) follows the standard ISO/IEC 18004 QR code spec.
 * It was built and cross-checked against real decoders before being
 * ported here — see the project notes for how that verification was
 * done.
 */
(function (global) {
  'use strict';

  // ---- GF(256) tables (primitive polynomial 0x11D) ----
  var EXP = new Array(512);
  var LOG = new Array(256);
  (function () {
    var x = 1;
    for (var i = 0; i < 255; i++) {
      EXP[i] = x;
      LOG[x] = i;
      x <<= 1;
      if (x & 0x100) x ^= 0x11D;
    }
    for (var j = 255; j < 512; j++) EXP[j] = EXP[j - 255];
  })();

  function gfMul(a, b) {
    if (a === 0 || b === 0) return 0;
    return EXP[LOG[a] + LOG[b]];
  }

  function rsGeneratorPoly(nsym) {
    var g = [1];
    for (var i = 0; i < nsym; i++) {
      var newG = new Array(g.length + 1).fill(0);
      for (var j = 0; j < g.length; j++) {
        newG[j] ^= g[j];
        newG[j + 1] ^= gfMul(g[j], EXP[i]);
      }
      g = newG;
    }
    return g;
  }

  function rsEncode(data, nsym) {
    var gen = rsGeneratorPoly(nsym);
    var msg = data.concat(new Array(nsym).fill(0));
    for (var i = 0; i < data.length; i++) {
      var coef = msg[i];
      if (coef !== 0) {
        for (var j = 0; j < gen.length; j++) {
          msg[i + j] ^= gfMul(gen[j], coef);
        }
      }
    }
    return msg.slice(data.length);
  }

  // data codewords / EC codewords per version, EC level L, single RS block
  var VERSION_INFO = {
    1: [19, 7],
    2: [34, 10],
    3: [55, 15],
    4: [80, 20],
    5: [108, 26],
  };

  function encodeData(text, version) {
    var info = VERSION_INFO[version];
    var dataCw = info[0];
    var bytes = [];
    // UTF-8 encode
    for (var i = 0; i < text.length; i++) {
      var code = text.codePointAt(i);
      if (code > 0xFFFF) i++; // consumed a surrogate pair
      if (code < 0x80) {
        bytes.push(code);
      } else if (code < 0x800) {
        bytes.push(0xC0 | (code >> 6), 0x80 | (code & 0x3F));
      } else if (code < 0x10000) {
        bytes.push(0xE0 | (code >> 12), 0x80 | ((code >> 6) & 0x3F), 0x80 | (code & 0x3F));
      } else {
        bytes.push(
          0xF0 | (code >> 18),
          0x80 | ((code >> 12) & 0x3F),
          0x80 | ((code >> 6) & 0x3F),
          0x80 | (code & 0x3F)
        );
      }
    }
    if (bytes.length > dataCw - 2) {
      throw new Error('QR text too long for version ' + version + ' (max ' + (dataCw - 2) + ' bytes, got ' + bytes.length + ')');
    }
    var bits = '0100' + pad(bytes.length.toString(2), 8);
    for (var b = 0; b < bytes.length; b++) bits += pad(bytes[b].toString(2), 8);
    var totalBits = dataCw * 8;
    bits += '0'.repeat(Math.min(4, totalBits - bits.length));
    while (bits.length % 8 !== 0) bits += '0';
    var padBytes = [0xEC, 0x11];
    var p = 0;
    while (bits.length < totalBits) {
      bits += pad(padBytes[p % 2].toString(2), 8);
      p++;
    }
    var codewords = [];
    for (var k = 0; k < bits.length; k += 8) codewords.push(parseInt(bits.substr(k, 8), 2));
    var ec = rsEncode(codewords, info[1]);
    return codewords.concat(ec);
  }

  function pad(s, len) {
    while (s.length < len) s = '0' + s;
    return s;
  }

  // ---- Matrix construction ----
  function QR(version) {
    this.version = version;
    this.size = 4 * version + 17;
    this.modules = [];
    this.isFunction = [];
    for (var i = 0; i < this.size; i++) {
      this.modules.push(new Array(this.size).fill(false));
      this.isFunction.push(new Array(this.size).fill(false));
    }
  }

  QR.prototype.setFunction = function (r, c, dark) {
    if (r >= 0 && r < this.size && c >= 0 && c < this.size) {
      this.modules[r][c] = dark;
      this.isFunction[r][c] = true;
    }
  };

  QR.prototype.drawFinder = function (cr, cc) {
    for (var dy = -4; dy <= 4; dy++) {
      for (var dx = -4; dx <= 4; dx++) {
        var dist = Math.max(Math.abs(dx), Math.abs(dy));
        this.setFunction(cr + dy, cc + dx, dist !== 2 && dist !== 4);
      }
    }
  };

  QR.prototype.drawAlignment = function (cr, cc) {
    for (var dy = -2; dy <= 2; dy++) {
      for (var dx = -2; dx <= 2; dx++) {
        var dist = Math.max(Math.abs(dx), Math.abs(dy));
        this.setFunction(cr + dy, cc + dx, dist !== 1);
      }
    }
  };

  QR.prototype.drawTiming = function () {
    for (var i = 8; i < this.size - 8; i++) {
      this.setFunction(6, i, i % 2 === 0);
      this.setFunction(i, 6, i % 2 === 0);
    }
  };

  // ecccBits: L=1, M=0, Q=3, H=2 (standard QR format-info convention)
  QR.prototype.drawFormatBits = function (eccBits, mask) {
    var data = (eccBits << 3) | mask;
    var rem = data;
    for (var r = 0; r < 10; r++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
    var bits = ((data << 10) | rem) ^ 0x5412;
    // NOTE: bit i of the 15-bit format string is the MSB-first bit,
    // i.e. position slot i holds bit (14 - i) — this ordering was the
    // one part of this file that isn't "obvious from the spec diagram"
    // and was confirmed against a real decoder before shipping.
    var gb = function (i) { return ((bits >> (14 - i)) & 1) !== 0; };
    for (var i = 0; i <= 5; i++) this.setFunction(8, i, gb(i));
    this.setFunction(8, 7, gb(6));
    this.setFunction(8, 8, gb(7));
    this.setFunction(7, 8, gb(8));
    for (i = 9; i < 15; i++) this.setFunction(14 - i, 8, gb(i));
    for (i = 0; i <= 7; i++) this.setFunction(this.size - 1 - i, 8, gb(i));
    for (i = 8; i < 15; i++) this.setFunction(8, this.size - 15 + i, gb(i));
    this.setFunction(8, this.size - 8, true);
  };

  QR.prototype.drawCodewords = function (dataBytes) {
    var i = 0;
    var totalBits = dataBytes.length * 8;
    var right = this.size - 1;
    while (right >= 1) {
      if (right === 6) right = 5;
      for (var vert = 0; vert < this.size; vert++) {
        for (var j = 0; j < 2; j++) {
          var x = right - j;
          var upward = ((right + 1) & 2) === 0;
          var y = upward ? this.size - 1 - vert : vert;
          if (!this.isFunction[y][x] && i < totalBits) {
            var bit = (dataBytes[i >>> 3] >> (7 - (i & 7))) & 1;
            this.modules[y][x] = bit !== 0;
            i++;
          }
        }
      }
      right -= 2;
    }
  };

  var MASK_FNS = [
    function (y, x) { return (y + x) % 2 === 0; },
    function (y, x) { return y % 2 === 0; },
    function (y, x) { return x % 3 === 0; },
    function (y, x) { return (y + x) % 3 === 0; },
    function (y, x) { return (Math.floor(y / 2) + Math.floor(x / 3)) % 2 === 0; },
    function (y, x) { return ((y * x) % 2) + ((y * x) % 3) === 0; },
    function (y, x) { return (((y * x) % 2) + ((y * x) % 3)) % 2 === 0; },
    function (y, x) { return (((y + x) % 2) + ((y * x) % 3)) % 2 === 0; },
  ];

  QR.prototype.applyMask = function (mask) {
    var cond = MASK_FNS[mask];
    for (var y = 0; y < this.size; y++) {
      for (var x = 0; x < this.size; x++) {
        if (!this.isFunction[y][x] && cond(y, x)) this.modules[y][x] = !this.modules[y][x];
      }
    }
  };

  QR.prototype.build = function (dataBytes, eccBits, mask) {
    this.drawFinder(3, 3);
    this.drawFinder(3, this.size - 4);
    this.drawFinder(this.size - 4, 3);
    this.drawTiming();
    if (this.version >= 2) {
      var pos = 4 * this.version + 10;
      this.drawAlignment(pos, pos);
    }
    this.setFunction(4 * this.version + 9, 8, true); // fixed dark module
    this.drawFormatBits(eccBits, mask);
    this.drawCodewords(dataBytes);
    this.applyMask(mask);
  };

  function pickVersion(byteLength) {
    for (var v = 1; v <= 5; v++) {
      if (byteLength <= VERSION_INFO[v][0] - 2) return v;
    }
    return null;
  }

  function utf8Length(text) {
    return unescape(encodeURIComponent(text)).length;
  }

  // Public API: encode(text) -> { size, modules } | render(text, opts) -> SVG string
  function encode(text, mask) {
    if (mask === undefined) mask = 0;
    var version = pickVersion(utf8Length(text));
    if (!version) {
      throw new Error('Text too long to encode as a QR code here (max ~106 bytes)');
    }
    var codewords = encodeData(text, version);
    var q = new QR(version);
    q.build(codewords, 1 /* EC level L */, mask);
    return q;
  }

  function renderSVG(text, opts) {
    opts = opts || {};
    var moduleSize = opts.moduleSize || 8;
    var border = opts.border === undefined ? 4 : opts.border;
    var dark = opts.dark || '#000000';
    var light = opts.light || '#ffffff';
    var q = encode(text, opts.mask);
    var n = q.size;
    var full = (n + border * 2) * moduleSize;
    var rects = '';
    for (var y = 0; y < n; y++) {
      for (var x = 0; x < n; x++) {
        if (q.modules[y][x]) {
          rects += '<rect x="' + ((x + border) * moduleSize) + '" y="' + ((y + border) * moduleSize) +
            '" width="' + moduleSize + '" height="' + moduleSize + '"/>';
        }
      }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + full + ' ' + full +
      '" width="' + full + '" height="' + full + '" shape-rendering="crispEdges">' +
      '<rect x="0" y="0" width="' + full + '" height="' + full + '" fill="' + light + '"/>' +
      '<g fill="' + dark + '">' + rects + '</g></svg>';
  }

  global.QRGen = { encode: encode, renderSVG: renderSVG };
})(typeof window !== 'undefined' ? window : this);
