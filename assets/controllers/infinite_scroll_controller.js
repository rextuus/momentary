import { Controller } from '@hotwired/stimulus';

/*
 * Lädt die nächste Seite der Zeitachse, sobald der "Ältere laden"-Link sichtbar wird.
 * Ohne JavaScript funktioniert der Link als normaler Seitenwechsel.
 */
export default class extends Controller {
    static targets = ['items', 'more', 'link'];

    connect() {
        this.loading = false;
        this.observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                this.load();
            }
        }, { rootMargin: '600px' });
        this.observe();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    observe() {
        if (this.hasLinkTarget) {
            this.observer.observe(this.linkTarget);
        }
    }

    async load() {
        if (this.loading || !this.hasLinkTarget) {
            return;
        }
        this.loading = true;
        const link = this.linkTarget;
        const more = link.closest('[data-infinite-scroll-target="more"]');
        link.classList.add('disabled');
        link.textContent = 'Lädt …';

        try {
            const response = await fetch(link.dataset.partialUrl, { headers: { Accept: 'text/html' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const html = await response.text();
            this.observer.unobserve(link);
            more.insertAdjacentHTML('afterend', html);
            more.remove();
            this.observe();
        } catch (error) {
            link.classList.remove('disabled');
            link.textContent = 'Ältere laden';
        } finally {
            this.loading = false;
        }
    }
}
