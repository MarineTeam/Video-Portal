/*
 * Direct browser-to-provider uploads.
 *
 * The file never touches this server. That is not an optimisation: on shared
 * hosting a 2GB upload through PHP would hit the memory limit, the POST size
 * limit, and the request timeout, roughly in that order. The server signs a
 * short-lived ticket for one specific video and the browser talks to bunny.net
 * directly. The API key never leaves the server.
 *
 * TUS is implemented here by hand rather than vendored. The protocol is three
 * requests — POST to create, PATCH to send bytes, HEAD to ask where to resume —
 * and a dependency would have to be committed, updated by cutting a whole
 * release, and served from a host that may be offline. Roughly 200 lines is a
 * better trade than that.
 *
 * Resumability is the reason for TUS at all: a dropped connection halfway
 * through a two-hour upload asks the server where it got to and carries on,
 * rather than starting again.
 */

(function () {
  'use strict';

  var panel = document.getElementById('upload-panel');
  if (!panel) {
    return;
  }

  var token = panel.getAttribute('data-token') || '';
  var CHUNK = 8 * 1024 * 1024;
  var MAX_RETRIES = 5;

  var input = document.getElementById('upload-input');
  var drop = document.getElementById('upload-drop');
  var list = document.getElementById('upload-list');

  // ------------------------------------------------------------ file picking

  if (input) {
    input.addEventListener('change', function () {
      addFiles(input.files);
      // Cleared so picking the same file twice in a row still fires a change.
      input.value = '';
    });
  }

  if (drop) {
    ['dragenter', 'dragover'].forEach(function (name) {
      drop.addEventListener(name, function (e) {
        e.preventDefault();
        drop.classList.add('is-over');
      });
    });

    ['dragleave', 'drop'].forEach(function (name) {
      drop.addEventListener(name, function (e) {
        e.preventDefault();
        drop.classList.remove('is-over');
      });
    });

    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files) {
        addFiles(e.dataTransfer.files);
      }
    });
  }

  function addFiles(files) {
    for (var i = 0; i < files.length; i++) {
      // Uploads run one at a time on purpose. Several at once share the same
      // upstream bandwidth, so they all finish later, and the progress display
      // stops meaning anything.
      queue.push(files[i]);
    }
    pump();
  }

  // ------------------------------------------------------------- the queue

  var queue = [];
  var active = null;

  function pump() {
    if (active || queue.length === 0) {
      return;
    }
    active = new Upload(queue.shift());
    active.start();
  }

  function finished() {
    active = null;
    pump();
  }

  // ------------------------------------------------------------- one upload

  function Upload(file) {
    this.file = file;
    this.videoId = null;
    this.location = null;
    this.offset = 0;
    this.xhr = null;
    this.cancelled = false;
    this.row = renderRow(file.name, this);
  }

  Upload.prototype.start = function () {
    var self = this;

    this.say('Preparing…');

    post('/admin/upload/ticket', {
      title: stripExtension(this.file.name)
    })
      .then(function (data) {
        if (self.cancelled) {
          return;
        }
        self.videoId = data.videoId;
        return self.createTusUpload(data.upload);
      })
      .then(function () {
        if (self.cancelled) {
          return;
        }
        return self.sendChunks();
      })
      .then(function () {
        if (self.cancelled) {
          return;
        }
        return post('/admin/upload/complete', { videoId: self.videoId });
      })
      .then(function () {
        if (!self.cancelled) {
          self.watchEncoding();
        }
      })
      .catch(function (error) {
        if (!self.cancelled) {
          self.fail(error && error.message ? error.message : String(error));
        }
      });
  };

  /**
   * TUS step one: tell the provider how many bytes are coming.
   *
   * The response's Location header is where the bytes go. It is only readable
   * because the provider exposes it via CORS; if that ever changes this fails
   * loudly here rather than silently uploading nothing.
   */
  Upload.prototype.createTusUpload = function (ticket) {
    var self = this;

    var headers = Object.assign({}, ticket.headers, {
      'Tus-Resumable': '1.0.0',
      'Upload-Length': String(this.file.size),
      'Upload-Metadata': metadata({
        filetype: this.file.type || 'video/mp4',
        title: stripExtension(this.file.name)
      })
    });

    return fetch(ticket.endpoint, { method: 'POST', headers: headers }).then(function (response) {
      if (!response.ok) {
        throw new Error('The video service refused the upload (HTTP ' + response.status + ').');
      }

      var location = response.headers.get('Location');
      if (!location) {
        throw new Error(
          'The video service did not say where to send the file. This is usually a '
          + 'CORS configuration problem at the provider rather than anything on this site.'
        );
      }

      self.location = new URL(location, ticket.endpoint).href;
    });
  };

  /**
   * TUS step two, repeatedly.
   *
   * XHR rather than fetch, because upload progress events are the whole point
   * of the display and fetch still cannot report them.
   */
  Upload.prototype.sendChunks = function () {
    var self = this;

    function next() {
      if (self.cancelled || self.offset >= self.file.size) {
        return Promise.resolve();
      }

      var end = Math.min(self.offset + CHUNK, self.file.size);
      var slice = self.file.slice(self.offset, end);
      var base = self.offset;

      return self
        .sendChunk(slice, base)
        .then(function (newOffset) {
          self.offset = newOffset;
          self.progress(self.offset / self.file.size);
          return next();
        });
    }

    return retrying(next, this);
  };

  Upload.prototype.sendChunk = function (slice, base) {
    var self = this;

    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      self.xhr = xhr;

      xhr.open('PATCH', self.location, true);
      xhr.setRequestHeader('Tus-Resumable', '1.0.0');
      xhr.setRequestHeader('Upload-Offset', String(base));
      xhr.setRequestHeader('Content-Type', 'application/offset+octet-stream');

      xhr.upload.onprogress = function (event) {
        if (event.lengthComputable) {
          self.progress((base + event.loaded) / self.file.size);
        }
      };

      xhr.onload = function () {
        self.xhr = null;

        if (xhr.status < 200 || xhr.status >= 300) {
          reject(new Error('The video service rejected part of the file (HTTP ' + xhr.status + ').'));
          return;
        }

        // Trust the server's offset over our own arithmetic: it is the one
        // that decides what it actually stored.
        var reported = parseInt(xhr.getResponseHeader('Upload-Offset') || '', 10);
        resolve(isNaN(reported) ? base + slice.size : reported);
      };

      xhr.onerror = function () {
        self.xhr = null;
        reject(new Error('The connection dropped.'));
      };

      xhr.onabort = function () {
        self.xhr = null;
        reject(new Error('Cancelled.'));
      };

      xhr.send(slice);
    });
  };

  /**
   * Ask the provider how much it already has.
   *
   * This is what makes a dropped connection survivable, and it is why the
   * offset is re-read from the server rather than assumed after a failure —
   * a chunk can be partially stored.
   */
  Upload.prototype.resync = function () {
    var self = this;

    return fetch(this.location, {
      method: 'HEAD',
      headers: { 'Tus-Resumable': '1.0.0' }
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Could not resume the upload (HTTP ' + response.status + ').');
      }
      var offset = parseInt(response.headers.get('Upload-Offset') || '', 10);
      if (!isNaN(offset)) {
        self.offset = offset;
      }
    });
  };

  /**
   * Retry with backoff, resuming from wherever the provider actually got to.
   */
  function retrying(work, upload) {
    var attempt = 0;

    function run() {
      return work().catch(function (error) {
        if (upload.cancelled || attempt >= MAX_RETRIES) {
          throw error;
        }

        attempt++;
        var wait = Math.min(30000, 1000 * Math.pow(2, attempt));
        upload.say('Connection lost. Retrying in ' + Math.round(wait / 1000) + 's…');

        return delay(wait)
          .then(function () {
            return upload.resync();
          })
          .then(run);
      });
    }

    return run();
  }

  /**
   * Encoding happens after the bytes arrive, and takes as long as it takes.
   *
   * Polled rather than pushed, because a shared host has nothing to push with.
   * The interval backs off so a long encode does not hammer the server for
   * half an hour.
   */
  Upload.prototype.watchEncoding = function () {
    var self = this;
    var wait = 3000;

    this.progress(1);
    this.say('Uploaded. Encoding…');
    this.row.cancel.hidden = true;

    function poll() {
      fetch('/admin/upload/status?ids[]=' + encodeURIComponent(self.videoId), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (data) {
          var video = (data.videos || [])[0];
          if (!video) {
            return;
          }

          if (video.status === 'ready') {
            self.done();
            return;
          }

          if (video.status === 'failed') {
            self.fail('The video service could not encode this file.');
            return;
          }

          self.say('Encoding… ' + (video.progress || 0) + '%');
          wait = Math.min(30000, Math.round(wait * 1.5));
          setTimeout(poll, wait);
        })
        .catch(function () {
          // A failed poll is not a failed encode. Keep asking.
          wait = Math.min(30000, Math.round(wait * 1.5));
          setTimeout(poll, wait);
        });
    }

    setTimeout(poll, wait);
  };

  Upload.prototype.cancel = function () {
    this.cancelled = true;

    if (this.xhr) {
      this.xhr.abort();
    }

    if (this.videoId) {
      // Deletes the half-created video at the provider too, so a cancelled
      // upload does not leave an empty row to tidy up by hand.
      post('/admin/upload/cancel', { videoId: this.videoId }).catch(function () {});
    }

    this.say('Cancelled.');
    this.row.el.classList.add('is-error');
    this.row.cancel.hidden = true;
    finished();
  };

  Upload.prototype.done = function () {
    this.say('Ready.');
    this.row.el.classList.add('is-done');
    this.row.cancel.hidden = true;

    if (this.videoId) {
      var link = document.createElement('a');
      link.href = '/admin/videos/' + this.videoId;
      link.className = 'btn tiny secondary';
      link.textContent = 'Edit';
      this.row.actions.appendChild(link);
    }

    finished();
  };

  Upload.prototype.fail = function (message) {
    this.say(message);
    this.row.el.classList.add('is-error');
    this.row.cancel.hidden = true;
    finished();
  };

  Upload.prototype.progress = function (fraction) {
    var percent = Math.max(0, Math.min(100, Math.round(fraction * 100)));
    this.row.bar.style.width = percent + '%';
    this.row.el.setAttribute('aria-valuenow', String(percent));
  };

  Upload.prototype.say = function (message) {
    this.row.status.textContent = message;
  };

  // ---------------------------------------------------------------- helpers

  function renderRow(name, upload) {
    var el = document.createElement('li');
    el.className = 'upload-row';
    el.setAttribute('role', 'progressbar');
    el.setAttribute('aria-valuemin', '0');
    el.setAttribute('aria-valuemax', '100');
    el.setAttribute('aria-label', 'Uploading ' + name);

    var title = document.createElement('span');
    title.className = 'upload-name';
    title.textContent = name;

    var status = document.createElement('span');
    status.className = 'upload-status muted small';
    status.textContent = 'Waiting…';

    var track = document.createElement('span');
    track.className = 'upload-track';
    var bar = document.createElement('span');
    bar.className = 'upload-bar';
    track.appendChild(bar);

    var actions = document.createElement('span');
    actions.className = 'upload-actions';

    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn tiny danger';
    cancel.textContent = 'Cancel';
    cancel.addEventListener('click', function () {
      upload.cancel();
    });
    actions.appendChild(cancel);

    el.appendChild(title);
    el.appendChild(status);
    el.appendChild(track);
    el.appendChild(actions);
    list.appendChild(el);

    return { el: el, bar: bar, status: status, cancel: cancel, actions: actions };
  }

  function post(path, body) {
    return fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': token
      },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response
        .json()
        .catch(function () {
          return {};
        })
        .then(function (data) {
          if (!response.ok) {
            throw new Error(data.error || data.message || 'Request failed (HTTP ' + response.status + ').');
          }
          return data;
        });
    });
  }

  /** TUS metadata is comma-separated "key base64value" pairs. */
  function metadata(pairs) {
    return Object.keys(pairs)
      .map(function (key) {
        return key + ' ' + base64(pairs[key]);
      })
      .join(',');
  }

  /** btoa cannot handle non-Latin1, and filenames routinely are. */
  function base64(value) {
    var bytes = new TextEncoder().encode(String(value));
    var binary = '';
    bytes.forEach(function (byte) {
      binary += String.fromCharCode(byte);
    });
    return btoa(binary);
  }

  function stripExtension(name) {
    return name.replace(/\.[^.]+$/, '') || name;
  }

  function delay(ms) {
    return new Promise(function (resolve) {
      setTimeout(resolve, ms);
    });
  }
})();
