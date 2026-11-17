import { Controller } from '@hotwired/stimulus';

/**
 * Moves focus to the error summary when a step comes back with errors, so a keyboard or
 * screen-reader user starts from the list of what to fix — and from there, its links to
 * each field.
 *
 * Without JavaScript the summary is still the first thing in the form and its links still
 * work; this only saves the trip to it.
 */
export default class extends Controller {
  connect() {
    this.element.focus();
  }
}
