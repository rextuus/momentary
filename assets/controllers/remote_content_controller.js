import { Controller } from '@hotwired/stimulus';

/*
 * Lädt einen HTML-Ausschnitt nach dem Seitenaufbau nach (z. B. Status externer Dienste),
 * damit die Seite nicht auf langsame Aufrufe warten muss.
 */
export default class extends Controller {
    static values = { url: String };

    async connect() {
        try {
            const response = await fetch(this.urlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            this.element.innerHTML = response.ok
                ? await response.text()
                : '<span class="text-danger">Status konnte nicht geladen werden.</span>';
        } catch {
            this.element.innerHTML = '<span class="text-danger">Status konnte nicht geladen werden.</span>';
        }
    }
}
