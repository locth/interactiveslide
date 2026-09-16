// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * A QR code encoder, so a room can join from its phones.
 *
 * Byte mode only, which is every URL there is. It is written out here from ISO/IEC
 * 18004 rather than pulled in as a library: the plugin ships no third party
 * JavaScript, and the whole job is some finite field arithmetic and two tables.
 * The symbol is drawn black on white whatever the theme, because a phone camera
 * is not reading the page's colour scheme.
 *
 * @module     mod_interactiveslide/qrcode
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    /** @var {Object} Error correction levels: the row in the tables and the format bits. */
    var LEVELS = {
        L: {row: 0, bits: 1},
        M: {row: 1, bits: 0},
        Q: {row: 2, bits: 3},
        H: {row: 3, bits: 2}
    };

    /** @var {Array} Error correction codewords per block, by level then version. */
    var ECC_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28,
            28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
            26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30,
            28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28,
            30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30]
    ];

    /** @var {Array} Error correction blocks, by level then version. */
    var BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8,
            8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16,
            17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20,
            23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25,
            25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81]
    ];

    /**
     * Modules left for data and error correction once every function pattern is drawn.
     *
     * @param {Number} version
     * @return {Number}
     */
    var rawModules = function(version) {
        var result = (16 * version + 128) * version + 64;
        if (version >= 2) {
            var align = Math.floor(version / 7) + 2;
            result -= (25 * align - 10) * align - 55;
            if (version >= 7) {
                result -= 36;
            }
        }
        return result;
    };

    /**
     * Data codewords a symbol of this version and level carries.
     *
     * @param {Number} version
     * @param {Object} level
     * @return {Number}
     */
    var dataCodewords = function(version, level) {
        return Math.floor(rawModules(version) / 8)
            - ECC_PER_BLOCK[level.row][version] * BLOCKS[level.row][version];
    };

    /**
     * Multiply in GF(2^8) modulo x^8 + x^4 + x^3 + x^2 + 1.
     *
     * @param {Number} x
     * @param {Number} y
     * @return {Number}
     */
    var gfMultiply = function(x, y) {
        var z = 0;
        for (var i = 7; i >= 0; i--) {
            z = (z << 1) ^ ((z >>> 7) * 0x11D);
            z ^= ((y >>> i) & 1) * x;
        }
        return z;
    };

    /**
     * The Reed-Solomon generator polynomial of a degree, highest term dropped.
     *
     * @param {Number} degree
     * @return {Number[]}
     */
    var rsDivisor = function(degree) {
        var result = [];
        for (var i = 0; i < degree - 1; i++) {
            result.push(0);
        }
        result.push(1);

        var root = 1;
        for (var k = 0; k < degree; k++) {
            for (var j = 0; j < result.length; j++) {
                result[j] = gfMultiply(result[j], root);
                if (j + 1 < result.length) {
                    result[j] ^= result[j + 1];
                }
            }
            root = gfMultiply(root, 0x02);
        }
        return result;
    };

    /**
     * The error correction codewords for one block of data.
     *
     * @param {Number[]} data
     * @param {Number[]} divisor
     * @return {Number[]}
     */
    var rsRemainder = function(data, divisor) {
        var result = divisor.map(function() {
            return 0;
        });
        data.forEach(function(byte) {
            var factor = byte ^ result.shift();
            result.push(0);
            divisor.forEach(function(coefficient, i) {
                result[i] ^= gfMultiply(coefficient, factor);
            });
        });
        return result;
    };

    /**
     * Split the data into blocks, add error correction to each and interleave them.
     *
     * @param {Number[]} data
     * @param {Number} version
     * @param {Object} level
     * @return {Number[]}
     */
    var interleave = function(data, version, level) {
        var blocks = BLOCKS[level.row][version];
        var eccLength = ECC_PER_BLOCK[level.row][version];
        var raw = Math.floor(rawModules(version) / 8);
        var shortBlocks = blocks - raw % blocks;
        var shortLength = Math.floor(raw / blocks);
        var divisor = rsDivisor(eccLength);

        var filled = [];
        for (var i = 0, k = 0; i < blocks; i++) {
            var block = data.slice(k, k + shortLength - eccLength + (i < shortBlocks ? 0 : 1));
            k += block.length;
            var ecc = rsRemainder(block, divisor);
            if (i < shortBlocks) {
                block.push(0);
            }
            filled.push(block.concat(ecc));
        }

        var result = [];
        for (var column = 0; column < filled[0].length; column++) {
            for (var b = 0; b < filled.length; b++) {
                // The padding byte added to a short block is not part of the symbol.
                if (column !== shortLength - eccLength || b >= shortBlocks) {
                    result.push(filled[b][column]);
                }
            }
        }
        return result;
    };

    /**
     * Centres of the alignment patterns along one axis.
     *
     * @param {Number} version
     * @return {Number[]}
     */
    var alignmentPositions = function(version) {
        if (version === 1) {
            return [];
        }
        var count = Math.floor(version / 7) + 2;
        var step = Math.floor((version * 8 + count * 3 + 5) / (count * 4 - 4)) * 2;
        var result = [6];
        for (var position = version * 4 + 10; result.length < count; position -= step) {
            result.splice(1, 0, position);
        }
        return result;
    };

    /**
     * The mask condition for a module: true means invert it.
     *
     * @param {Number} mask
     * @param {Number} x
     * @param {Number} y
     * @return {Boolean}
     */
    var masked = function(mask, x, y) {
        switch (mask) {
            case 0: return (x + y) % 2 === 0;
            case 1: return y % 2 === 0;
            case 2: return x % 3 === 0;
            case 3: return (x + y) % 3 === 0;
            case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0;
            case 5: return x * y % 2 + x * y % 3 === 0;
            case 6: return (x * y % 2 + x * y % 3) % 2 === 0;
            default: return ((x + y) % 2 + x * y % 3) % 2 === 0;
        }
    };

    /**
     * How hard a masked symbol is for a scanner, by the standard's four rules.
     *
     * Every mask decodes; this only picks the one with the fewest long runs,
     * solid blocks and finder lookalikes, which is what a phone at the back of a
     * lecture hall struggles with.
     *
     * @param {Boolean[][]} modules
     * @return {Number}
     */
    var penalty = function(modules) {
        var size = modules.length;
        var score = 0;
        var dark = 0;

        var line = function(get) {
            var run = 1;
            for (var i = 1; i <= size; i++) {
                if (i < size && get(i) === get(i - 1)) {
                    run++;
                    continue;
                }
                if (run >= 5) {
                    score += 3 + (run - 5);
                }
                run = 1;
            }

            var at = function(i) {
                return i >= 0 && i < size ? get(i) : false;
            };
            for (var s = 0; s + 7 <= size; s++) {
                if (at(s) && !at(s + 1) && at(s + 2) && at(s + 3) && at(s + 4) && !at(s + 5) && at(s + 6)) {
                    var before = !at(s - 1) && !at(s - 2) && !at(s - 3) && !at(s - 4);
                    var after = !at(s + 7) && !at(s + 8) && !at(s + 9) && !at(s + 10);
                    if (before || after) {
                        score += 40;
                    }
                }
            }
        };

        for (var y = 0; y < size; y++) {
            line(function(x) {
                return modules[y][x];
            });
        }
        for (var x = 0; x < size; x++) {
            line(function(row) {
                return modules[row][x];
            });
        }

        for (var r = 0; r < size; r++) {
            for (var c = 0; c < size; c++) {
                if (modules[r][c]) {
                    dark++;
                }
                if (r + 1 < size && c + 1 < size) {
                    var colour = modules[r][c];
                    if (colour === modules[r][c + 1] && colour === modules[r + 1][c]
                            && colour === modules[r + 1][c + 1]) {
                        score += 3;
                    }
                }
            }
        }

        var total = size * size;
        score += (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;

        return score;
    };

    /**
     * Encode text as a QR symbol.
     *
     * @param {String} text
     * @param {String} [levelname] L, M, Q or H; M when omitted
     * @return {Boolean[][]} rows of modules, true for dark
     */
    var encode = function(text, levelname) {
        var level = LEVELS[levelname] || LEVELS.M;
        var bytes = Array.prototype.slice.call(new TextEncoder().encode(String(text)));

        var version;
        var capacity = 0;
        for (version = 1; version <= 40; version++) {
            capacity = dataCodewords(version, level) * 8;
            if (4 + (version < 10 ? 8 : 16) + bytes.length * 8 <= capacity) {
                break;
            }
        }
        if (version > 40) {
            throw new Error('Too much data for a QR code');
        }

        var bits = [];
        var push = function(value, length) {
            for (var i = length - 1; i >= 0; i--) {
                bits.push((value >>> i) & 1);
            }
        };

        push(4, 4);
        push(bytes.length, version < 10 ? 8 : 16);
        bytes.forEach(function(byte) {
            push(byte, 8);
        });
        push(0, Math.min(4, capacity - bits.length));
        push(0, (8 - bits.length % 8) % 8);
        for (var pad = 0xEC; bits.length < capacity; pad ^= 0xEC ^ 0x11) {
            push(pad, 8);
        }

        var data = [];
        for (var b = 0; b < bits.length; b += 8) {
            var value = 0;
            for (var j = 0; j < 8; j++) {
                value = (value << 1) | bits[b + j];
            }
            data.push(value);
        }

        var size = version * 4 + 17;
        var modules = [];
        var reserved = [];
        for (var row = 0; row < size; row++) {
            modules.push(new Array(size).fill(false));
            reserved.push(new Array(size).fill(false));
        }

        var set = function(x, y, isdark) {
            modules[y][x] = isdark;
            reserved[y][x] = true;
        };

        var bit = function(value, index) {
            return ((value >>> index) & 1) !== 0;
        };

        // Timing patterns.
        for (var t = 0; t < size; t++) {
            set(6, t, t % 2 === 0);
            set(t, 6, t % 2 === 0);
        }

        // Finder patterns, with their separators.
        [[3, 3], [size - 4, 3], [3, size - 4]].forEach(function(centre) {
            for (var dy = -4; dy <= 4; dy++) {
                for (var dx = -4; dx <= 4; dx++) {
                    var distance = Math.max(Math.abs(dx), Math.abs(dy));
                    var fx = centre[0] + dx;
                    var fy = centre[1] + dy;
                    if (fx >= 0 && fx < size && fy >= 0 && fy < size) {
                        set(fx, fy, distance !== 2 && distance !== 4);
                    }
                }
            }
        });

        // Alignment patterns, except where a finder already sits.
        var positions = alignmentPositions(version);
        var count = positions.length;
        for (var ai = 0; ai < count; ai++) {
            for (var aj = 0; aj < count; aj++) {
                if ((ai === 0 && aj === 0) || (ai === 0 && aj === count - 1) || (ai === count - 1 && aj === 0)) {
                    continue;
                }
                for (var ay = -2; ay <= 2; ay++) {
                    for (var ax = -2; ax <= 2; ax++) {
                        set(positions[ai] + ax, positions[aj] + ay, Math.max(Math.abs(ax), Math.abs(ay)) !== 1);
                    }
                }
            }
        }

        var drawFormat = function(mask) {
            var formatdata = (level.bits << 3) | mask;
            var rem = formatdata;
            for (var i = 0; i < 10; i++) {
                rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
            }
            var formatbits = ((formatdata << 10) | rem) ^ 0x5412;

            for (var a = 0; a <= 5; a++) {
                set(8, a, bit(formatbits, a));
            }
            set(8, 7, bit(formatbits, 6));
            set(8, 8, bit(formatbits, 7));
            set(7, 8, bit(formatbits, 8));
            for (var c = 9; c < 15; c++) {
                set(14 - c, 8, bit(formatbits, c));
            }

            for (var d = 0; d < 8; d++) {
                set(size - 1 - d, 8, bit(formatbits, d));
            }
            for (var e = 8; e < 15; e++) {
                set(8, size - 15 + e, bit(formatbits, e));
            }
            set(8, size - 8, true);
        };

        // Reserve the format areas now; the real bits are written once a mask is chosen.
        drawFormat(0);

        if (version >= 7) {
            var rem = version;
            for (var v = 0; v < 12; v++) {
                rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
            }
            var versionbits = (version << 12) | rem;
            for (var vi = 0; vi < 18; vi++) {
                var along = size - 11 + vi % 3;
                var across = Math.floor(vi / 3);
                set(along, across, bit(versionbits, vi));
                set(across, along, bit(versionbits, vi));
            }
        }

        // Codewords, zigzagging up and down in two-module columns from the right.
        var codewords = interleave(data, version, level);
        var index = 0;
        for (var right = size - 1; right >= 1; right -= 2) {
            if (right === 6) {
                right = 5;
            }
            for (var vert = 0; vert < size; vert++) {
                for (var k = 0; k < 2; k++) {
                    var x = right - k;
                    var upward = ((right + 1) & 2) === 0;
                    var y = upward ? size - 1 - vert : vert;
                    if (!reserved[y][x] && index < codewords.length * 8) {
                        modules[y][x] = bit(codewords[index >>> 3], 7 - (index & 7));
                        index++;
                    }
                }
            }
        }

        var applyMask = function(mask) {
            for (var my = 0; my < size; my++) {
                for (var mx = 0; mx < size; mx++) {
                    if (!reserved[my][mx] && masked(mask, mx, my)) {
                        modules[my][mx] = !modules[my][mx];
                    }
                }
            }
        };

        var best = 0;
        var lowest = Infinity;
        for (var mask = 0; mask < 8; mask++) {
            applyMask(mask);
            drawFormat(mask);
            var score = penalty(modules);
            if (score < lowest) {
                lowest = score;
                best = mask;
            }
            // XOR is its own inverse, so applying the mask again takes it off.
            applyMask(mask);
        }

        applyMask(best);
        drawFormat(best);

        return modules;
    };

    /**
     * Encode text as an SVG QR code.
     *
     * Horizontal runs of dark modules become one rectangle each, which keeps the
     * path a few kilobytes even for a long link.
     *
     * @param {String} text
     * @param {Object} [options] level (L, M, Q, H) and margin in modules, 4 by default
     * @return {String} SVG markup, built only from numbers
     */
    var svg = function(text, options) {
        options = options || {};
        var modules = encode(text, options.level);
        var margin = options.margin === undefined ? 4 : Number(options.margin);
        var size = modules.length;
        var full = size + margin * 2;

        var path = '';
        for (var y = 0; y < size; y++) {
            var x = 0;
            while (x < size) {
                if (!modules[y][x]) {
                    x++;
                    continue;
                }
                var start = x;
                while (x < size && modules[y][x]) {
                    x++;
                }
                path += 'M' + (start + margin) + ' ' + (y + margin) + 'h' + (x - start) + 'v1h-' + (x - start) + 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + full + ' ' + full + '"' +
            ' shape-rendering="crispEdges" focusable="false" aria-hidden="true">' +
            '<rect width="' + full + '" height="' + full + '" fill="#ffffff"/>' +
            '<path d="' + path + '" fill="#000000"/>' +
            '</svg>';
    };

    return {
        encode: encode,
        svg: svg
    };
});
