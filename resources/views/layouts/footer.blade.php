{{-- Core JS --}}
<script src="{{ asset('assets/js/jquery.min.js') }}"></script> {{-- jika tidak ada, hapus baris ini --}}
<script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/plugins/sweetalert2/sweetalert2.min.js') }}"></script>

{{-- Plugins untuk sidebar --}}
<script src="{{ asset('assets/plugins/simplebar/js/simplebar.min.js') }}"></script>
<script src="{{ asset('assets/plugins/metismenu/js/metisMenu.min.js') }}"></script>
<script src="{{ asset('assets/plugins/perfect-scrollbar/js/perfect-scrollbar.min.js') }}"></script>

{{-- App JS (jika tema kamu punya) --}}
<script src="{{ asset('assets/js/app.js') }}"></script>

<script>
  // SimpleBar (sesuai atribut data-simplebar="true" di wrapper)
  (function () {
    var sb = document.querySelector('.sidebar-wrapper[data-simplebar="true"]');
    if (sb && typeof SimpleBar !== 'undefined') {
      // instantiate sekali saja
      if (!sb.SimpleBar) new SimpleBar(sb);
    }
  })();

  // PerfectScrollbar (optional; kalau pakai SimpleBar, ini tidak wajib)
  // new PerfectScrollbar('.sidebar-wrapper');

  // MetisMenu init (penting agar bullet & nested menu jadi gaya metis)
  (function () {
    var menu = document.getElementById('menu');
    if (menu) {
      // jQuery plugin:
      if (window.jQuery && typeof jQuery(menu).metisMenu === 'function') {
        jQuery(menu).metisMenu();
      }
      // kalau kamu pakai versi vanilla (metismenujs), gunakan:
      // new MetisMenu(menu);
    }
  })();
</script>

<script>
  // Guard global untuk semua form yang mengubah data.
  // GET/filter tidak dikunci; POST/PUT/PATCH/DELETE dikunci setelah submit valid
  // agar double-click tidak membuat insert/update/delete ganda.
  (function () {
    const writeMethods = new Set(['post', 'put', 'patch', 'delete']);
    const buttonStates = new WeakMap();

    const createSpinner = () => {
      const spinner = document.createElement('span');
      spinner.className = 'spinner-border spinner-border-sm me-1';
      spinner.setAttribute('role', 'status');
      spinner.setAttribute('aria-hidden', 'true');
      return spinner;
    };

    const startButton = (button, loadingText = 'Memproses...') => {
      if (!button || button.dataset.appLoading === '1') return false;

      buttonStates.set(button, {
        html: button.innerHTML,
        value: button.value,
        disabled: button.disabled,
      });
      button.dataset.appLoading = '1';
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;

      if (button instanceof HTMLInputElement) {
        button.value = loadingText;
      } else {
        button.replaceChildren(createSpinner(), document.createTextNode(loadingText));
      }
      return true;
    };

    const stopButton = (button) => {
      const state = buttonStates.get(button);
      if (!button || !state) return;

      if (button instanceof HTMLInputElement) {
        button.value = state.value;
      } else {
        button.innerHTML = state.html;
      }
      button.disabled = state.disabled;
      button.removeAttribute('aria-busy');
      delete button.dataset.appLoading;
      buttonStates.delete(button);
    };

    const startForm = (form, loadingText = 'Memproses...') => {
      if (!form || form.dataset.appLoading === '1') return false;

      form.dataset.appLoading = '1';
      form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]').forEach((button) => {
        startButton(button, button.dataset.loadingText || loadingText);
      });
      return true;
    };

    const stopForm = (form) => {
      if (!form) return;
      form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]').forEach(stopButton);
      delete form.dataset.appLoading;
      form.removeAttribute('aria-busy');
    };

    window.AppLoading = { startButton, stopButton, startForm, stopForm };

    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;

      const method = (form.getAttribute('method') || 'get').toLowerCase();
      if (!writeMethods.has(method)) return;

      // Handler validasi yang membatalkan submit harus bisa membiarkan user
      // memperbaiki form. Exception ini untuk form refresh CSRF yang memang
      // membatalkan event lalu meneruskan submit secara programmatic.
      if (event.defaultPrevented && form.dataset.csrfRefresh !== '1') return;

      if (form.dataset.appLoading === '1') {
        event.preventDefault();
        return;
      }

      startForm(form);
    });

    // Jika halaman kembali dari browser history, tombol tidak boleh tertinggal
    // dalam keadaan loading.
    window.addEventListener('pageshow', (event) => {
      if (!event.persisted) return;
      document.querySelectorAll('form[data-app-loading="1"]').forEach(stopForm);
    });
  })();
</script>
