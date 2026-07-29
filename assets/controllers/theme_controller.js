import { Controller } from '@hotwired/stimulus';

/**
 * Light / dark / auto, for the surfaces EasyAdmin does not own.
 *
 * The back-office already has its own switcher; this covers the public volunteer
 * pages and the login page. The choice lives in localStorage rather than a cookie:
 * it is a display preference, nothing needs it server-side, and a cookie on a site
 * that otherwise sets only a session one would be a poor trade.
 *
 * The attribute is applied twice, and both matter. A blocking script in
 * base.html.twig sets it before first paint, so there is no flash of the wrong
 * theme; this controller sets it again on every click. Without the inline script
 * the page would repaint after the module loads, which is exactly what a visitor
 * who chose dark notices.
 *
 * `auto` REMOVES the key rather than storing the word. Storing "auto" would freeze
 * whatever the operating system happened to prefer at that moment; removing it lets
 * the media query in app.css keep answering the question.
 *
 * Expected markup:
 *
 *   <div {{ stimulus_controller('theme') }}>
 *     <button data-action="theme#choose" data-theme-value-param="light">…</button>
 *     <button data-action="theme#choose" data-theme-value-param="dark">…</button>
 *     <button data-action="theme#choose" data-theme-value-param="auto">…</button>
 *   </div>
 */
export default class extends Controller {
  static values = {
    // Kept in a value so the inline script in base.html.twig and this controller
    // cannot drift apart silently — change it in both, or neither works.
    storageKey: { type: String, default: 'benevolio-theme' },
  };

  connect() {
    // The markup ships hidden: with JavaScript off these buttons would do nothing,
    // and an inert control is worse than no control. Reaching here is the proof
    // that they work.
    this.element.hidden = false;

    this.#reflect(this.#stored() ?? 'auto');
  }

  choose(event) {
    const choice = event.params.value;

    try {
      if (choice === 'auto') {
        localStorage.removeItem(this.storageKeyValue);
      } else {
        localStorage.setItem(this.storageKeyValue, choice);
      }
    } catch {
      // Blocked storage throws. Applying the choice below still works for this
      // page; it just will not be remembered.
    }

    this.#apply(choice);
    this.#reflect(choice);
  }

  #apply(choice) {
    if (choice === 'auto') {
      delete document.documentElement.dataset.theme;

      return;
    }

    document.documentElement.dataset.theme = choice;
  }

  /**
   * Marks the active button. aria-pressed rather than a class alone, so the state
   * is announced and not merely coloured.
   */
  #reflect(choice) {
    this.element.querySelectorAll('[data-theme-value-param]').forEach((button) => {
      button.setAttribute(
        'aria-pressed',
        button.dataset.themeValueParam === choice ? 'true' : 'false',
      );
    });
  }

  #stored() {
    try {
      const stored = localStorage.getItem(this.storageKeyValue);

      return stored === 'light' || stored === 'dark' ? stored : null;
    } catch {
      return null;
    }
  }
}
