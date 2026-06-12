/* global cashuMintPickerL10n */
(function () {
  'use strict';

  var SENTINEL = '__discover__';

  // "https://mint.x.example/Bitcoin/" -> "mint.x.example/Bitcoin"
  function displayUrl(url) {
    return String(url)
      .replace(/^https?:\/\//, '')
      .replace(/\/+$/, '');
  }

  function mintLabel(mint) {
    var name = (mint.name || '').trim();
    var host = displayUrl(mint.url);
    return name ? name + ' — ' + host : host;
  }

  // Auditor /mints/ payload -> [{name, url}]: OK-state only, fewest errors first.
  function auditorMints(list) {
    return list
      .filter(function (m) {
        return !!m && 'OK' === m.state && !!m.url;
      })
      .sort(function (a, b) {
        return (a.n_errors || 0) - (b.n_errors || 0);
      })
      .map(function (m) {
        return { name: m.name || '', url: m.url };
      });
  }

  function buildOptions(doc, select, l10n, mints, withSentinel) {
    select.textContent = '';
    var placeholder = doc.createElement('option');
    placeholder.value = '';
    placeholder.textContent = l10n.i18n.placeholder;
    select.appendChild(placeholder);
    mints.forEach(function (mint) {
      var opt = doc.createElement('option');
      opt.value = mint.url;
      opt.textContent = mintLabel(mint);
      select.appendChild(opt);
    });
    if (withSentinel) {
      var disc = doc.createElement('option');
      disc.value = SENTINEL;
      disc.textContent = l10n.i18n.discover;
      select.appendChild(disc);
    }
  }

  // Wire the picker under the trusted-mint input. The select is a pure UI
  // affordance — never POSTed; picking a mint only fills the text input
  // (the merchant still saves, which runs the sanitizer + limits probe).
  function init(doc, l10n, fetcher) {
    var input = doc.getElementById('cashu_trusted_mint');
    if (!input || !l10n || !Array.isArray(l10n.starterMints)) {
      return null;
    }

    var select = doc.createElement('select');
    select.id = 'cashu-mint-picker';
    var notice = doc.createElement('span');
    notice.className = 'description';
    notice.hidden = true;
    var wrap = doc.createElement('p');
    wrap.appendChild(select);
    wrap.appendChild(doc.createTextNode(' '));
    wrap.appendChild(notice);
    input.insertAdjacentElement('afterend', wrap);

    buildOptions(doc, select, l10n, l10n.starterMints, true);

    function discover() {
      select.disabled = true;
      select.value = '';
      select.options[0].textContent = l10n.i18n.discovering;
      fetcher(l10n.auditorApi + '/mints/')
        .then(function (res) {
          if (!res.ok) {
            throw new Error('HTTP ' + res.status);
          }
          return res.json();
        })
        .then(function (list) {
          var mints = auditorMints(list);
          if (!mints.length) {
            throw new Error('no usable mints');
          }
          // Full list loaded — the sentinel has done its job.
          buildOptions(doc, select, l10n, mints, false);
        })
        .catch(function () {
          buildOptions(doc, select, l10n, l10n.starterMints, true);
          notice.textContent = l10n.i18n.failed;
          notice.hidden = false;
        })
        .then(function () {
          select.disabled = false;
        });
    }

    select.addEventListener('change', function () {
      var value = select.value;
      notice.hidden = true;
      if (!value) {
        return;
      }
      if (SENTINEL === value) {
        discover();
        return;
      }
      input.value = value;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      select.value = ''; // back to the placeholder: the select is an action, not a value
    });

    return { select: select, notice: notice };
  }

  var api = { init: init, auditorMints: auditorMints, mintLabel: mintLabel };

  if ('undefined' !== typeof window) {
    window.CashuMintPicker = api;
    if ('undefined' !== typeof cashuMintPickerL10n && 'undefined' !== typeof document) {
      var run = function () {
        init(document, cashuMintPickerL10n, window.fetch.bind(window));
      };
      if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', run);
      } else {
        run();
      }
    }
  }
})();
