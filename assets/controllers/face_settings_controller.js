import { Controller } from '@hotwired/stimulus';

/*
 * Einstellungsseite der Gesichtserkennung:
 * - hält Schieberegler und Zahlenfeld einer Einstellung synchron
 * - zeigt die Ähnlichkeits-Schwellen auf einer gemeinsamen Skala
 * - blendet die Tracking-Optionen aus, wenn Tracking abgeschaltet ist
 */
export default class extends Controller {
    static targets = ['marker', 'markerLabel', 'scaleWarning', 'trackingToggle', 'trackingOptions'];
    static values = { scaleMin: { type: Number, default: 50 } };

    connect() {
        this.element.querySelectorAll('[data-setting-row]').forEach((row) => {
            const range = row.querySelector('input[type=range]');
            const number = row.querySelector('input[type=number]');
            if (!range || !number) {
                return;
            }
            range.addEventListener('input', () => {
                number.value = range.value;
                this.updateScale();
            });
            number.addEventListener('input', () => {
                range.value = number.value;
                this.updateScale();
            });
        });

        this.updateScale();
        this.toggleTracking();
    }

    updateScale() {
        const values = {};
        const sameValue = {};
        this.markerTargets.forEach((marker) => {
            const input = this.element.querySelector(`#${marker.dataset.input}`);
            const value = parseFloat(input?.value ?? '0');
            values[marker.dataset.input] = value;
            const clamped = Math.min(100, Math.max(this.scaleMinValue, value));
            // Gleiche Werte leicht versetzen, damit kein Punkt einen anderen verdeckt
            const offset = (sameValue[clamped] = (sameValue[clamped] ?? -1) + 1) * 12;
            marker.style.left = `calc(${((clamped - this.scaleMinValue) / (100 - this.scaleMinValue)) * 100}% + ${offset}px)`;
        });

        this.markerLabelTargets.forEach((label) => {
            label.textContent = `${values[label.dataset.input] ?? ''} %`;
        });

        if (this.hasScaleWarningTarget) {
            const candidate = values[this.scaleWarningTarget.dataset.candidate];
            const others = this.scaleWarningTarget.dataset.others.split(',').map((id) => values[id]);
            this.scaleWarningTarget.hidden = !(candidate > Math.min(...others));
        }
    }

    toggleTracking() {
        if (!this.hasTrackingToggleTarget) {
            return;
        }
        const enabled = this.trackingToggleTarget.checked;
        // inert statt disabled: deaktivierte Felder würden nicht mitgesendet und die Werte gingen verloren
        this.trackingOptionsTarget.classList.toggle('is-disabled', !enabled);
        this.trackingOptionsTarget.inert = !enabled;
    }
}
