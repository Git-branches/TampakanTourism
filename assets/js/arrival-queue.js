/* =============================================================================
   TourSync — the offline arrival queue                            Feature 2
   -----------------------------------------------------------------------------
   A visit recorded at a waterfall with one bar of signal, or none, is written
   here first and sent when the network comes back. This file is the whole of
   that promise, and it is loaded twice on purpose:

     · by the logbook page, which writes into it and drains it while open
     · by the service worker via importScripts, which drains it after the
       browser has been closed and the phone has wandered back into coverage

   So nothing in here may touch the DOM or `window`. Everything hangs off
   `self`, which is the page in one context and the worker in the other.

   IndexedDB rather than localStorage. localStorage is synchronous, capped
   around five megabytes, and — the disqualifying part — it is cleared by the
   same "clear site data" gesture people perform when a page misbehaves. An
   arrival is somebody's visit to a public place, recorded on behalf of a
   municipality. It gets a real database.
   ========================================================================== */

(function (scope) {
    'use strict';

    var DB_NAME    = 'toursync-offline';
    var DB_VERSION = 1;
    var STORE      = 'arrivals';

    /* After this many rejections the record stops being retried automatically.
       It is NOT deleted — a rejected arrival stays visible so somebody can act
       on it. Silently discarding a visitor's record would be the one failure
       this whole feature exists to prevent. */
    var MAX_ATTEMPTS = 5;

    var config = {
        submitUrl: '',
        tokenUrl:  ''
    };

    function configure(options) {
        if (options && options.submitUrl) { config.submitUrl = options.submitUrl; }
        if (options && options.tokenUrl)  { config.tokenUrl  = options.tokenUrl; }
    }

    /* ---- Identity ------------------------------------------------------- */

    /* The id is minted on the device, before the record has ever been sent.
       That is what makes a retry recognisable as the same arrival rather than
       a second one — see the client_uuid guard in api/arrivals/submit.php. */
    function uuid() {
        if (scope.crypto && typeof scope.crypto.randomUUID === 'function') {
            return scope.crypto.randomUUID();
        }

        // Older Android browsers. Still 122 random bits from a CSPRNG.
        var bytes = new Uint8Array(16);
        scope.crypto.getRandomValues(bytes);

        bytes[6] = (bytes[6] & 0x0f) | 0x40;   // version 4
        bytes[8] = (bytes[8] & 0x3f) | 0x80;   // variant 1

        var hex = [];
        for (var i = 0; i < 16; i++) {
            hex.push((bytes[i] + 0x100).toString(16).slice(1));
        }

        return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-'
             + hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-'
             + hex.slice(10, 16).join('');
    }

    /* ---- Database ------------------------------------------------------- */

    function open() {
        return new Promise(function (resolve, reject) {
            var request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function (event) {
                var db = event.target.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    db.createObjectStore(STORE, { keyPath: 'uuid' });
                }
            };

            request.onsuccess = function () { resolve(request.result); };
            request.onerror   = function () { reject(request.error); };
        });
    }

    function transact(mode, work) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx     = db.transaction(STORE, mode);
                var store  = tx.objectStore(STORE);
                var result = work(store);

                tx.oncomplete = function () { db.close(); resolve(result); };
                tx.onerror    = function () { db.close(); reject(tx.error); };
                tx.onabort    = function () { db.close(); reject(tx.error); };
            });
        });
    }

    function readAll() {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx      = db.transaction(STORE, 'readonly');
                var request = tx.objectStore(STORE).getAll();

                request.onsuccess = function () { db.close(); resolve(request.result || []); };
                request.onerror   = function () { db.close(); reject(request.error); };
            });
        });
    }

    /* ---- Queue operations ----------------------------------------------- */

    /**
     * Stores a filled-in form. `fields` is a plain object of the form values;
     * capturedAt is stamped here rather than at send time, because the whole
     * point is that these two moments are different.
     */
    function enqueue(fields) {
        var record = {
            uuid:       uuid(),
            capturedAt: Math.floor(Date.now() / 1000),
            fields:     fields,
            attempts:   0,
            lastError:  null,
            blocked:    false
        };

        return transact('readwrite', function (store) {
            store.put(record);
            return record;
        });
    }

    function remove(id) {
        return transact('readwrite', function (store) { store.delete(id); });
    }

    function save(record) {
        return transact('readwrite', function (store) { store.put(record); });
    }

    /** Records still waiting to be sent — the number a visitor is shown. */
    function pending() {
        return readAll().then(function (rows) {
            return rows.filter(function (r) { return !r.blocked; });
        });
    }

    function blocked() {
        return readAll().then(function (rows) {
            return rows.filter(function (r) { return r.blocked; });
        });
    }

    /* ---- Sending -------------------------------------------------------- */

    /* The token in the queued form is as old as the record. This collects a
       current one, which is why the sync path is no weaker than the browser
       path — see api/arrivals/token.php. */
    function freshToken() {
        return fetch(config.tokenUrl, {
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (res) {
            if (!res.ok) { throw new Error('token endpoint returned ' + res.status); }
            return res.json();
        }).then(function (data) {
            if (!data || !data.token) { throw new Error('token endpoint returned no token'); }
            return data.token;
        });
    }

    function send(record, token) {
        var body = new FormData();

        Object.keys(record.fields).forEach(function (name) {
            body.append(name, record.fields[name]);
        });

        body.set('_token',      token);
        body.set('mode',        'sync');
        body.set('client_uuid', record.uuid);
        body.set('captured_at', String(record.capturedAt));

        return fetch(config.submitUrl, {
            method:      'POST',
            body:        body,
            credentials: 'same-origin',
            cache:       'no-store'
        });
    }

    /**
     * Empties the queue, as far as the network allows.
     *
     * Three outcomes per record, and they are deliberately different:
     *
     *   accepted   the server stored it (or recognised a retry of something it
     *              had already stored) — drop it from the device
     *   rejected   the server says the record itself is wrong; count the
     *              attempt, keep the record, stop after MAX_ATTEMPTS
     *   deferred   network or server trouble; leave it exactly as it is and
     *              come back later. Nothing is counted, nothing is lost.
     */
    function drain() {
        var report = { sent: 0, rejected: 0, deferred: 0, remaining: 0 };

        return pending().then(function (rows) {
            if (rows.length === 0) {
                return report;
            }

            return freshToken().then(function (token) {
                // Sequential, not parallel: the rate limiter counts submissions
                // per address, and firing six at once is the surest way to be
                // told to come back later.
                return rows.reduce(function (chain, record) {
                    return chain.then(function () {
                        return send(record, token).then(function (res) {
                            if (res.ok) {
                                report.sent++;
                                return remove(record.uuid);
                            }

                            if (res.status >= 500 || res.status === 429) {
                                report.deferred++;
                                return;
                            }

                            // 4xx — the server has judged the record itself.
                            record.attempts  = (record.attempts || 0) + 1;
                            record.blocked   = record.attempts >= MAX_ATTEMPTS;
                            record.lastError = 'Rejected by the server (' + res.status + ')';
                            report.rejected++;
                            return save(record);

                        }).catch(function () {
                            // Never reached the server at all.
                            report.deferred++;
                        });
                    });
                }, Promise.resolve());

            }).catch(function () {
                // No token means no network. Everything stays queued.
                report.deferred = rows.length;
            });

        }).then(function () {
            return pending().then(function (rows) {
                report.remaining = rows.length;
                return report;
            });
        });
    }

    scope.ArrivalQueue = {
        configure: configure,
        enqueue:   enqueue,
        pending:   pending,
        blocked:   blocked,
        remove:    remove,
        drain:     drain,
        uuid:      uuid
    };
})(self);
