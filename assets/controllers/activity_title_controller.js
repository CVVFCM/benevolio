import { Controller } from '@hotwired/stimulus';

/**
 * On one declaration line: the free-text title is only needed when no event or
 * action is chosen from the list (« Autre »), so it is hidden while one is.
 *
 * The server does not depend on this. A title typed beside a chosen activity is
 * dropped, and without JavaScript the field simply stays visible.
 *
 * Both targets are optional: an association with nothing to offer renders no
 * select, and the title then stays as it is.
 */
export default class extends Controller {
  static targets = ['select', 'title'];

  connect() {
    this.toggle();
  }

  toggle() {
    if (!this.hasSelectTarget || !this.hasTitleTarget) {
      return;
    }

    this.titleTarget.hidden = this.selectTarget.value !== '';
  }
}
