import { Controller } from '@hotwired/stimulus';
import * as bootstrap from 'bootstrap';

/*
 * Steuert die Video-Timeline-Ansicht:
 * - expandAll: öffnet alle eingeklappten Bereiche
 * - toggleUnknowns: blendet unbekannte Gesichter ein/aus
 */
export default class extends Controller {
    expandAll() {
        this.element.querySelectorAll('.collapse').forEach((el) => {
            const bsCollapse = bootstrap.Collapse.getOrCreateInstance(el);
            bsCollapse.show();
        });
    }

    toggleUnknowns() {
        this.element.querySelectorAll('.face-card-container').forEach((container) => {
            if (container.querySelector('.is-unknown-card') || container.querySelector('.is-unknown')) {
                const col = container.closest('.col');
                if (col) {
                    col.classList.toggle('d-none');
                }
            }
        });
    }
}
