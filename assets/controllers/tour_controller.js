import { Controller } from '@hotwired/stimulus';
import { driver } from 'driver.js';

/**
 * A guided tour of the page: driver.js highlights one element at a time, with a short
 * text beside it.
 *
 * The steps come from the page itself, as a JSON value rendered by Twig — the French
 * texts live with the markup they describe, and this controller holds only the
 * mechanics. Each step is `{element?, title, description, side?}`; `element` is a CSS
 * selector (none centres the popover), and a step whose element is not on the page, or
 * not displayed, is left out — the activity select of an association that has none,
 * « Se connecter » for a volunteer already logged in.
 *
 * TRAP — driver.js renders the title and the description as HTML. The texts are
 * authored strings in templates; never put anything a person typed into one.
 *
 * A tour opens by itself once per browser and per tour, then again after a year: the
 * date it was seen is kept in localStorage under `benevolio-tour:<id>`. It is marked as
 * seen when it opens, not when it is finished — closing it early is an answer too. It
 * never opens by itself:
 *   - when the page says so (`autostart` false: a step redisplayed with its errors, a
 *     logged-in volunteer on the form, a super-admin impersonating);
 *   - on a phone-width screen, where a popover beside an element has no room;
 *   - when storage is unavailable, because nothing could remember the answer and the
 *     tour would open on every page view.
 * The replay button (`data-tour-target="replay"`, `data-action="tour#start"`) works in
 * every case. It ships hidden and is revealed here, as the theme switcher is: without
 * JavaScript it would do nothing.
 */
const STORAGE_PREFIX = 'benevolio-tour:';
const ONE_YEAR_MS = 365 * 24 * 60 * 60 * 1000;
const NARROW_SCREEN = '(max-width: 40rem)';

export default class extends Controller {
  static values = {
    id: String,
    steps: Array,
    autostart: { type: Boolean, default: true },
  };

  static targets = ['replay'];

  connect() {
    this.replayTargets.forEach((button) => {
      button.hidden = false;
    });

    if (!this.autostartValue || window.matchMedia(NARROW_SCREEN).matches) {
      return;
    }

    if (this.#seenRecently() !== false) {
      return;
    }

    if (this.#markSeen()) {
      this.start();
    }
  }

  disconnect() {
    this.driver?.destroy();
  }

  start() {
    this.driver?.destroy();

    this.driver = driver({
      // Steps whose element is absent or not displayed are dropped here rather than
      // skipped by driver.js, which would still count them: « 3 sur 6 » on a five-step
      // tour.
      steps: this.stepsValue.filter((step) => this.#isShown(step.element)).map((step, index) => ({
        element: step.element,
        popover: {
          title: step.title,
          description: step.description,
          side: step.side,
          // Nothing comes before the first step; a greyed « Précédent » still reads as
          // a button.
          ...(index === 0 ? { showButtons: ['next', 'close'] } : {}),
        },
      })),
      showProgress: true,
      progressText: '{{current}} sur {{total}}',
      nextBtnText: 'Suivant',
      prevBtnText: 'Précédent',
      doneBtnText: 'Terminer',
      closeBtnLabel: 'Fermer la visite',
      popoverClass: 'tour',
    });

    this.driver.drive();
  }

  #isShown(selector) {
    if (!selector) {
      return true;
    }

    const element = document.querySelector(selector);

    return element !== null && element.getClientRects().length > 0;
  }

  /**
   * True or false when storage answered, null when it could not be read.
   */
  #seenRecently() {
    let stored;

    try {
      stored = localStorage.getItem(this.#key());
    } catch {
      return null;
    }

    const seenAt = Date.parse(stored ?? '');

    return !Number.isNaN(seenAt) && Date.now() - seenAt < ONE_YEAR_MS;
  }

  #markSeen() {
    try {
      localStorage.setItem(this.#key(), new Date().toISOString());

      return true;
    } catch {
      return false;
    }
  }

  #key() {
    return STORAGE_PREFIX + this.idValue;
  }
}
