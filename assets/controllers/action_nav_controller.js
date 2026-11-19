import { Controller } from '@hotwired/stimulus';

/**
 * The action navigator of the declaration form (#54), on the actions step's <form>.
 *
 * The server renders the list, so its links work without JavaScript. This keeps it live: it
 * lists the rows again whenever one is added, removed or renamed, marks the row in view,
 * writes « Action N sur M » in the bar a narrow screen shows, and keeps the <details> open on
 * a wide screen, where the list is a column rather than a bar.
 *
 * A row the server refused carries `data-invalid` and is listed as such until the next
 * submit: nothing here checks a field. A new row has no mark; a removed one takes its own.
 *
 * The entry markup is the <template> the server renders from the same macro as its list.
 * Names are read in the server's order — event or action, title, task, « Sans titre » — and
 * set as text, never as HTML.
 */
const WIDE = '(width >= 72rem)';

// The row « in view » is the last one whose top has passed this fraction of the viewport.
const READING_LINE = 0.3;

export default class extends Controller {
  static targets = ['row', 'list', 'template', 'details', 'count', 'errors', 'add'];

  static outlets = ['form-collection'];

  connect() {
    this.wide = window.matchMedia(WIDE);
    this.onWideChange = () => this.syncOpen();
    this.wide.addEventListener('change', this.onWideChange);

    this.onScroll = () => this.scheduleCurrent();
    window.addEventListener('scroll', this.onScroll, { passive: true });
    window.addEventListener('resize', this.onScroll, { passive: true });

    this.onKeydown = (event) => this.closeOnEscape(event);
    this.onPointerdown = (event) => this.closeOnOutside(event);
    document.addEventListener('keydown', this.onKeydown);
    document.addEventListener('pointerdown', this.onPointerdown);

    if (this.hasAddTarget) {
      this.addTarget.hidden = false;
    }

    this.syncOpen();
    this.refresh();
  }

  disconnect() {
    this.wide.removeEventListener('change', this.onWideChange);
    window.removeEventListener('scroll', this.onScroll);
    window.removeEventListener('resize', this.onScroll);
    document.removeEventListener('keydown', this.onKeydown);
    document.removeEventListener('pointerdown', this.onPointerdown);
    window.cancelAnimationFrame(this.frame);
  }

  // A row cloned or removed by the form-collection controller.
  rowTargetConnected() {
    this.scheduleRefresh();
  }

  rowTargetDisconnected() {
    this.scheduleRefresh();
  }

  scheduleRefresh() {
    // Stimulus connects the targets one by one as the page starts; one rebuild is enough.
    if (this.refreshQueued) {
      return;
    }

    this.refreshQueued = true;
    queueMicrotask(() => {
      this.refreshQueued = false;
      this.refresh();
    });
  }

  refresh() {
    if (!this.hasListTarget || !this.hasTemplateTarget) {
      return;
    }

    const entries = this.rowTargets.map((row, index) => this.entry(row, index + 1));
    this.listTarget.replaceChildren(...entries);

    const invalid = this.rowTargets.filter((row) => row.hasAttribute('data-invalid')).length;
    this.errorsTarget.hidden = invalid === 0;
    this.errorsTarget.textContent = `${invalid} à corriger`;

    this.markCurrent();
  }

  entry(row, number) {
    const item = this.templateTarget.content.firstElementChild.cloneNode(true);
    const link = item.querySelector('a');
    const name = this.nameOf(row);
    const invalid = row.hasAttribute('data-invalid');

    link.href = `#${row.id}`;
    link.title = name;
    link.classList.toggle('action-nav__link--invalid', invalid);
    link.querySelector('.action-nav__number').textContent = String(number);

    const label = link.querySelector('.action-nav__name');
    const spoken = label.querySelector('.visually-hidden');
    spoken.textContent = `Action ${number} : `;
    label.replaceChildren(spoken, document.createTextNode(name));

    if (invalid) {
      const hint = document.createElement('span');
      hint.className = 'visually-hidden';
      hint.textContent = ' (à corriger)';
      link.append(hint);
    }

    return item;
  }

  nameOf(row) {
    const chosen = (name) => {
      const select = row.querySelector(`select[name$="[${name}]"]`);

      return select !== null && select.value !== '' ? select.selectedOptions[0]?.text.trim() : '';
    };
    const title = row.querySelector('input[name$="[title]"]')?.value.trim() ?? '';

    return chosen('activity') || title || chosen('task') || 'Sans titre';
  }

  // Follows a link: scrolls to the row, focuses its first field and closes the bar.
  go(event) {
    const row = this.rowTargets.find((candidate) => `#${candidate.id}` === event.currentTarget.getAttribute('href'));
    if (row === undefined) {
      return;
    }

    event.preventDefault();
    this.reveal(row);
  }

  add(event) {
    if (!this.hasFormCollectionOutlet) {
      return;
    }

    this.formCollectionOutlet.add(event);
    this.close();

    const row = this.rowTargets.at(-1);
    if (row !== undefined) {
      this.reveal(row);
    }
  }

  reveal(row) {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    row.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
    row.querySelector('input:not([type="hidden"]), select, textarea')?.focus({ preventScroll: true });
    this.close();
  }

  scheduleCurrent() {
    window.cancelAnimationFrame(this.frame);
    this.frame = window.requestAnimationFrame(() => this.markCurrent());
  }

  markCurrent() {
    const line = window.innerHeight * READING_LINE;
    const rows = this.rowTargets;
    let current = 0;
    rows.forEach((row, index) => {
      if (row.getBoundingClientRect().top <= line) {
        current = index;
      }
    });

    this.listTarget.querySelectorAll('a').forEach((link, index) => {
      if (index === current) {
        link.setAttribute('aria-current', 'location');
      } else {
        link.removeAttribute('aria-current');
      }
    });

    this.countTarget.textContent = `Action ${current + 1} sur ${rows.length}`;
  }

  // Open on a wide screen, where the list is a column; a bar to open on a narrow one.
  syncOpen() {
    this.detailsTarget.open = this.wide.matches;
  }

  close() {
    if (!this.wide.matches) {
      this.detailsTarget.open = false;
    }
  }

  closeOnEscape(event) {
    if (event.key === 'Escape' && this.detailsTarget.open && !this.wide.matches) {
      this.close();
      this.detailsTarget.querySelector('summary').focus();
    }
  }

  closeOnOutside(event) {
    if (this.detailsTarget.open && !this.wide.matches && !this.detailsTarget.contains(event.target)) {
      this.close();
    }
  }
}
