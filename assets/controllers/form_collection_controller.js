import { Controller } from '@hotwired/stimulus';

/**
 * Adds and removes rows in a Symfony CollectionType.
 *
 * Works off the `prototype` markup Symfony renders in `data-prototype`, replacing
 * the `__name__` placeholder with the next index. The form still submits without
 * this controller — Symfony renders one row server-side — so the page degrades to
 * a single-action declaration rather than to nothing.
 *
 * Expected markup:
 *
 *   <div {{ stimulus_controller('form-collection') }}
 *        data-form-collection-prototype-value="{{ form.vars.prototype|e }}"
 *        data-form-collection-index-value="{{ form|length }}">
 *     <div data-form-collection-target="container">…rows…</div>
 *     <button data-action="form-collection#add">Add</button>
 *   </div>
 */
export default class extends Controller {
  static targets = ['container'];

  static values = {
    prototype: String,
    index: Number,
    // Kept in a value rather than hardcoded so the placeholder can change with
    // the CollectionType `prototype_name` option.
    placeholder: { type: String, default: '__name__' },
  };

  add(event) {
    event.preventDefault();

    const row = document.createElement('div');
    row.innerHTML = this.prototypeValue.replaceAll(this.placeholderValue, this.indexValue);

    const added = row.firstElementChild;
    if (added === null) {
      return;
    }

    // The one authored moment on this page: the row settles in from an already
    // visible state rather than appearing from nothing.
    added.classList.add('action--entering');
    added.addEventListener(
      'animationend',
      () => added.classList.remove('action--entering'),
      { once: true },
    );

    this.containerTarget.append(added);
    this.indexValue += 1;

    // Move focus into the new row so a keyboard user is not left behind at the
    // button they just pressed.
    added.querySelector('input, select, textarea')?.focus();
  }

  remove(event) {
    event.preventDefault();

    const row = event.target.closest('[data-form-collection-row]');
    if (row === null) {
      return;
    }

    // Never leave the volunteer with zero rows: there would be nothing to submit,
    // and the "at least one action" error would appear with no field to fix.
    if (this.rows.length <= 1) {
      return;
    }

    row.remove();
  }

  get rows() {
    return this.containerTarget.querySelectorAll('[data-form-collection-row]');
  }
}
