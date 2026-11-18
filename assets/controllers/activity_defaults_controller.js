import { Controller } from '@hotwired/stimulus';

/**
 * On one declaration line: choosing an event or action fills in what it usually
 * asks of a volunteer — its number of days, two journeys a day, its hours.
 *
 * Only when the select changes, never on connect: a line restored from the
 * session, or redisplayed with errors, already holds what the volunteer typed.
 * A choice then overwrites the three fields, by decision — the volunteer picked
 * the activity, and corrects from its figures. « Autre », and an activity that
 * carries no figure, leave the fields as they are.
 *
 * The figures come from `data-days` and `data-hours` on the chosen option. The
 * server does not depend on any of this.
 */
export default class extends Controller {
  static targets = ['days', 'journeys', 'hours'];

  fill(event) {
    const option = event.target.selectedOptions[0];
    if (option === undefined) {
      return;
    }

    const { days, hours } = option.dataset;

    if (days !== undefined) {
      if (this.hasDaysTarget) {
        this.daysTarget.value = days;
      }

      // There and back, each day.
      if (this.hasJourneysTarget) {
        this.journeysTarget.value = String(Number(days) * 2);
      }
    }

    if (hours !== undefined && this.hasHoursTarget) {
      // "14.00" → "14", "7.50" → "7.5": what a number input shows for it.
      this.hoursTarget.value = String(Number(hours));
    }
  }
}
