/*
 * Settings on this device, in localStorage. Keys are a contract with
 * player.js and the playback plugin — see device-settings.php.
 */
(function () {
  'use strict';

  var form = document.getElementById('device-settings');
  if (!form) { return; }

  var autoplay = document.getElementById('device-autoplay');
  var speed = document.getElementById('device-speed');
  var status = document.getElementById('device-status');
  var theme = document.getElementById('device-theme');

  function read(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }

  function write(key, value) {
    try {
      window.localStorage.setItem(key, value);
      status.textContent = 'Saved on this device.';
    } catch (e) {
      /* A private window, or a browser refusing site data. Said, because a
         setting that reverts on the next page looks like a broken page. */
      status.textContent = 'This browser is not keeping settings, so this will not stick.';
    }
  }

  autoplay.checked = read('portal.autoplay') !== 'off';

  var stored = read('portal.speed');
  if (stored && speed.querySelector('option[value="' + stored + '"]')) {
    speed.value = stored;
  } else {
    speed.value = '1';
  }

  if (theme) {
    var storedTheme = read('portal.theme');
    theme.value = storedTheme === 'system' || storedTheme === 'light' || storedTheme === 'dark' ? storedTheme : '';

    theme.addEventListener('change', function () {
      if (theme.value === '') {
        /* The site's own: removed rather than stored as a value, so a site that
           later ships a different default is not overruled by a choice nobody
           made. */
        try { window.localStorage.removeItem('portal.theme'); status.textContent = 'Saved on this device.'; }
        catch (e) { status.textContent = 'This browser is not keeping settings, so this will not stick.'; }
      } else {
        write('portal.theme', theme.value);
      }
      /* Applied now, in this tab — the head script only hears other tabs. */
      if (window.portalTheme) { window.portalTheme.set(theme.value || null); }
    });
  }

  autoplay.addEventListener('change', function () {
    write('portal.autoplay', autoplay.checked ? 'on' : 'off');
  });

  speed.addEventListener('change', function () {
    write('portal.speed', speed.value);
  });

  form.hidden = false;
})();
