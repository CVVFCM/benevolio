import { Controller } from '@hotwired/stimulus';

/**
 * The scoreboard on the home page counts up to its figures once, as the page opens: the one
 * authored moment of the sports-hall look.
 *
 * The figures are real text in the page, so without JavaScript, or for anyone who prefers less
 * motion, they simply stand as written. Only the leading number of each value moves; its unit
 * (a span after it) is left alone. French decimals keep their comma.
 */
const DURATION = 1100;

export default class extends Controller {
  static targets = ['value'];

  connect() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }

    this.counters = this.valueTargets
      .map((element) => ({ node: element.firstChild }))
      .filter(({ node }) => node !== null && node.nodeType === Node.TEXT_NODE)
      .map(({ node }) => {
        const text = node.nodeValue.trim();
        const decimals = text.includes(',') ? text.split(',')[1].length : 0;

        return { node, text, decimals, target: Number.parseFloat(text.replace(',', '.')) };
      })
      .filter(({ target }) => Number.isFinite(target));

    this.start = null;
    this.frame = window.requestAnimationFrame((time) => this.step(time));
  }

  disconnect() {
    window.cancelAnimationFrame(this.frame);
    this.counters?.forEach(({ node, text }) => {
      node.nodeValue = text;
    });
  }

  step(time) {
    this.start ??= time;
    const progress = Math.min(1, (time - this.start) / DURATION);
    // Ease out: fast at first, settling on the figure like a scoreboard catching up.
    const eased = 1 - (1 - progress) ** 3;

    this.counters.forEach(({ node, decimals, target }) => {
      node.nodeValue = (target * eased).toFixed(decimals).replace('.', ',');
    });

    if (progress < 1) {
      this.frame = window.requestAnimationFrame((next) => this.step(next));
    } else {
      this.counters.forEach(({ node, text }) => {
        node.nodeValue = text;
      });
    }
  }
}
